<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\Edge;
use RunCache\Core\Setting;
use RunCache\Support\Logger;
use RunCache\Support\Validator;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare APO CDN integration. Extends the shared Cloudflare API client with
 * APO-specific setting, purge, and header handling. Cache Rules and APO are mutually exclusive.
 */
class CloudflareApo extends Cloudflare
{
    public const APO_SETTING_ID = 'automatic_platform_optimization';
    public const CDN_PROVIDER_KEY = 'cloudflare-apo';

    protected array $provider_config = [
        'key' => self::CDN_PROVIDER_KEY,
        'label' => 'Cloudflare APO',
        'cache_type_constant' => RUNCACHE_CACHE_TYPE_CLOUDFLARE_APO,
        'settings_option_key' => 'edge',
    ];

    /**
     * Filter the selective-purge chunk size for Cloudflare APO.
     *
     * @param int $chunk_size Maximum number of files per request.
     * @return int
     */
    protected function get_purge_chunk_size(int $chunk_size = 30): int
    {
        return (int) apply_filters('runcache_cloudflare_apo_purge_chunk_size', $chunk_size);
    }

    protected array $default_settings = [
        'api_token' => '',
        'zone_id' => '',
        'auth_type' => 'token',
        'account_email' => '',
        'apo_enabled' => false,
    ];

    protected bool $apo_enabled = false;

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        parent::__construct($logger, $setting);
    }

    /**
     * Read APO settings from the edge settings option.
     *
     * @return array
     */
    public function get_settings(): array
    {
        $edge_settings = (new Edge(null, null, $this->setting))->get_provider_settings($this->get_provider_key());
        return wp_parse_args($edge_settings, $this->default_settings);
    }

    /**
     * Emits cf-edge-cache for APO and X-CF-Status for the Cloudflare layer.
     * The origin page cache owns the X-RunCache-Type / X-RunCache-Status headers.
     *
     * @return void
     */
    public function do_cache(): void
    {
        if (!function_exists('runcache_should_cache')) {
            require_once RUNCACHE_PLUGIN_DIR . 'includes/runcache-filter.php';
        }

        $should = runcache_should_cache();

        $this->logger->debug('runcache_should_cache() returned: ' . ($should ? 'TRUE' : 'FALSE'), ['provider' => $this->get_provider_label()]);

        if ($should) {
            header('X-CF-Status: HIT');
            header('cf-edge-cache: cache,platform=wordpress');

            // Keep Vary header support aligned with cache_key_extra_var rules.
            $vary_headers = self::get_vary_header_names();
            if (!empty($vary_headers)) {
                header('Vary: ' . implode(', ', $vary_headers));
            }
        } else {
            header('X-CF-Status: BYPASS');
            header('cf-edge-cache: no-cache');
        }

        $this->logger->debug('APO headers sent', ['provider' => $this->get_provider_label()]);
    }

    /**
     * Persist the APO-enabled flag alongside the common Cloudflare settings.
     *
     * @param array $merged_settings Settings merged with defaults.
     * @return array
     */
    protected function sanitize_extra_settings(array $merged_settings): array
    {
        return [
            'apo_enabled' => (bool) ($merged_settings['apo_enabled'] ?? false),
        ];
    }

    /**
     * Load the APO-enabled flag from storage.
     *
     * @param array $settings
     * @return void
     */
    protected function load_extra_settings(array $settings): void
    {
        $this->apo_enabled = (bool) ($settings['apo_enabled'] ?? false);
    }

    /**
     * Initialize WordPress hooks
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_save_cloudflare_apo_settings', [$this, 'ajax_save_settings']);

        if ($this->is_apo_active()) {
            $this->logger->info('Cloudflare APO hooks registered', [
                'provider' => $this->get_provider_label(),
                'zone_id' => $this->zone_id,
            ]);

            add_action('send_headers', [$this, 'do_cache'], 99999);
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
            add_action('runcache_after_purge_url', [$this, 'purge_url_cache']);

            // Re-push APO setting on rules change to keep cache-key headers in sync.
            add_action('runcache_rules_updated', [$this, 'refresh_cache_setting']);
        }
    }

    /**
     * Check whether APO is currently enabled as the active CDN provider.
     *
     * @return bool
     */
    protected function is_apo_active(): bool
    {
        if (!(new Edge(null, null, $this->setting))->is_active($this->get_provider_key())) {
            return false;
        }

        $provider_settings = $this->get_settings();

        return !empty($provider_settings['api_token']) && !empty($provider_settings['zone_id']);
    }

    /**
     * Purge all cache via Cloudflare API. Logs before the API call to confirm
     * the hook fired.
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        $this->logger->info('Cloudflare APO purge_all_cache hook fired', [
            'provider' => $this->get_provider_label(),
            'zone_id' => $this->zone_id,
            'api_token_set' => $this->api_token !== '',
        ]);

        parent::purge_all_cache();
    }

    /**
     * Re-enable the APO setting after a rules update. Runs only when the remote
     * setting is disabled, so a manual dashboard change stays untouched.
     *
     * @return void
     */
    public function refresh_cache_setting(): void
    {
        if (!$this->has_valid_credentials() || !$this->apo_enabled) {
            return;
        }

        $current = $this->get_apo_setting();
        if (is_wp_error($current)) {
            $this->logger->warning('Could not read current APO setting; skipping refresh', [
                'provider' => $this->get_provider_label(),
                'error' => $current->get_error_message(),
            ]);
            return;
        }

        if (!empty($current['enabled'])) {
            return;
        }

        $this->logger->info('Refreshing APO setting after rules update', ['provider' => $this->get_provider_label()]);
        $this->enable();
    }

    /**
     * Enable Cloudflare APO
     *
     * @return bool
     */
    public function enable(): bool
    {
        return $this->set_apo_enabled(true);
    }

    /**
     * Disable Cloudflare APO
     *
     * @return bool
     */
    public function disable(): bool
    {
        return $this->set_apo_enabled(false);
    }

    /**
     * Verify Cloudflare APO setup
     *
     * @return array
     */
    public function verify(): array
    {
        try {
            if (!$this->has_valid_credentials()) {
                return [
                    'verified' => false,
                    'message' => __('Cloudflare APO API token and Zone ID are required.', 'runcache'),
                ];
            }

            $response = $this->make_request('GET', $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id]));

            if (is_wp_error($response)) {
                $message = __('Cloudflare APO verification failed. Please check your credentials and try again.', 'runcache');
                $this->logger->error('Verification API request failed', [
                    'provider' => $this->get_provider_label(),
                    'error' => $response->get_error_message(),
                ]);
                return [
                    'verified' => false,
                    'message' => $message,
                ];
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $result = $body['result'] ?? [];

            $domain = $this->get_site_domain();
            $zone_name = $result['name'] ?? '';
            if ($zone_name !== '' && !$this->zone_matches_domain($zone_name, $domain)) {
                $message = $this->get_verify_zone_mismatch_message($domain, $zone_name);
                $this->logger->error('Verification zone mismatch', [
                    'provider' => $this->get_provider_label(),
                    'site_domain' => $domain,
                    'zone_name' => $zone_name,
                ]);
                return [
                    'verified' => false,
                    'message' => $message,
                ];
            }

            return [
                'verified' => true,
                'message' => $this->get_verify_success_message(),
                'data' => $result,
            ];
        } catch (Exception $exception) {
            $this->logger->error('Failed to verify APO', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'verified' => false,
                'message' => __('Cloudflare APO verification failed. Please check your credentials and try again.', 'runcache'),
            ];
        }
    }

    /**
     * Save APO credentials into the edge settings option.
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        try {
            $merged_settings = wp_parse_args($settings, $this->default_settings);

            $auth_type = strtolower(sanitize_text_field($merged_settings['auth_type'] ?? 'token'));
            if (!in_array($auth_type, ['token', 'key'], true)) {
                $auth_type = 'token';
            }

            $account_email = sanitize_email($merged_settings['account_email'] ?? '');

            $sanitized_settings = array_merge([
                'api_token' => $this->normalize_api_token($merged_settings['api_token'] ?? ''),
                'zone_id' => sanitize_text_field($merged_settings['zone_id'] ?? ''),
                'auth_type' => $auth_type,
                'account_email' => $account_email,
            ], $this->sanitize_extra_settings($merged_settings));

            if ($sanitized_settings['api_token'] === '') {
                throw new Exception(__('Cloudflare APO API token is required.', 'runcache'));
            }

            if ($sanitized_settings['zone_id'] === '') {
                throw new Exception(__('Cloudflare APO Zone ID is required.', 'runcache'));
            }

            if ($sanitized_settings['auth_type'] === 'key' && $sanitized_settings['account_email'] === '') {
                throw new Exception('Cloudflare account email is required when using an API key');
            }

            $result = (new Edge(null, null, $this->setting))->save_provider(
                $this->get_provider_key(),
                $sanitized_settings,
                true
            );
            if (!$result) {
                throw new Exception(__('Failed to save Cloudflare APO settings.', 'runcache'));
            }

            do_action('runcache_edge_settings_saved', $this->get_settings());

            $this->api_token = $sanitized_settings['api_token'];
            $this->zone_id = $sanitized_settings['zone_id'];
            $this->auth_type = $sanitized_settings['auth_type'];
            $this->account_email = $sanitized_settings['account_email'];
            $this->load_extra_settings($sanitized_settings);

            $this->logger->info('Settings saved successfully', ['provider' => $this->get_provider_label()]);

            // If APO is the active CDN provider, try to enable it remotely now.
            if ($this->is_apo_active()) {
                $this->enable();
            }

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to save settings', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build the hostnames list for the APO setting. Always includes the site
     * domain and its www or apex counterpart, even for an empty remote list.
     *
     * @return array<int, string>
     */
    protected function get_site_hostnames(): array
    {
        $domain = $this->get_site_domain();
        if ($domain === '') {
            return [];
        }

        $hostnames = [$domain];

        if (str_starts_with($domain, 'www.')) {
            $hostnames[] = substr($domain, 4);
        } elseif (substr_count($domain, '.') === 1) {
            $hostnames[] = 'www.' . $domain;
        }

        return array_values(array_unique($hostnames));
    }

    /**
     * Set APO enabled state via API and persist the flag locally.
     *
     * @param bool $enabled
     * @return bool
     */
    private function set_apo_enabled(bool $enabled): bool
    {
        try {
            if (!$this->has_valid_credentials()) {
                $this->logger->error('Cannot toggle APO: API token or Zone ID missing', ['provider' => $this->get_provider_label()]);
                return false;
            }

            $current = $this->get_apo_setting();
            if (is_wp_error($current)) {
                $this->logger->warning('Could not read current APO setting; using minimal toggle payload', [
                    'provider' => $this->get_provider_label(),
                    'error' => $current->get_error_message(),
                ]);
                $current = [];
            }

            $site_hostnames = $this->get_site_hostnames();
            $normalized_site_hostnames = array_map(
                static fn ($h) => strtolower(rtrim($h, '.')),
                $site_hostnames
            );

            if ($enabled) {
                $hostnames = array_values(array_unique(array_merge(
                    $current['hostnames'] ?? [],
                    $site_hostnames
                )));
            } else {
                // Disable only this site. Keep the zone-level enabled flag so
                // APO stays active for the remaining hostnames.
                $hostnames = array_values(array_filter(
                    $current['hostnames'] ?? [],
                    static fn ($h) => !in_array(strtolower(rtrim((string) $h, '.')), $normalized_site_hostnames, true)
                ));
            }

            $value = array_merge(
                [
                    'enabled' => $enabled,
                    'hostnames' => [],
                    'wordpress' => true,
                    'wp_plugin' => true,
                    'cf' => true,
                    'cache_by_device_type' => false,
                ],
                $current,
                [
                    'hostnames' => $hostnames,
                ]
            );

            // Only an explicit enable() call turns APO on. Disabling removes
            // this site from the hostnames list and keeps the zone enabled.
            if ($enabled) {
                $value['enabled'] = true;
            }

            $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'settings', self::APO_SETTING_ID]);
            $response = $this->make_request('PATCH', $url, [
                'value' => $value,
            ]);

            if (is_wp_error($response)) {
                $this->logger->error('Failed to toggle APO', [
                    'provider' => $this->get_provider_label(),
                    'enabled' => $enabled,
                    'error' => $response->get_error_message(),
                ]);
                return false;
            }

            $this->logger->info('APO toggled', [
                'provider' => $this->get_provider_label(),
                'enabled' => $enabled,
            ]);

            // Update the local apo_enabled flag inside the edge settings option.
            $edge = new Edge(null, null, $this->setting);
            $edge_settings = $edge->get_settings();
            if (!isset($edge_settings['provider']) || !is_array($edge_settings['provider'])) {
                $edge_settings['provider'] = [];
            }
            if (!isset($edge_settings['provider'][$this->get_provider_key()]) || !is_array($edge_settings['provider'][$this->get_provider_key()])) {
                $edge_settings['provider'][$this->get_provider_key()] = [];
            }
            $edge_settings['provider'][$this->get_provider_key()]['apo_enabled'] = $enabled;
            $this->setting->set(Edge::OPTION_KEY, $edge_settings);
            $this->apo_enabled = $enabled;

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Exception while toggling APO', [
                'provider' => $this->get_provider_label(),
                'enabled' => $enabled,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Read the current APO setting value.
     *
     * @return array|WP_Error
     */
    private function get_apo_setting()
    {
        if (!$this->has_valid_credentials()) {
            return new WP_Error('cloudflare_apo_credentials_missing', 'API token or Zone ID missing');
        }

        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'settings', self::APO_SETTING_ID]);
        $response = $this->make_request('GET', $url);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $result = $body['result'] ?? [];
        $value = $result['value'] ?? [];

        if (!is_array($value)) {
            return new WP_Error('cloudflare_apo_invalid_response', 'Unexpected APO setting response format');
        }

        return $value;
    }

    /**
     * Check whether APO can be edited on the current zone.
     *
     * @return bool
     */
    private function is_apo_editable(): bool
    {
        if (!$this->has_valid_credentials()) {
            return false;
        }

        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'settings', self::APO_SETTING_ID]);
        $response = $this->make_request('GET', $url);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $result = $body['result'] ?? [];

        if (!is_array($result) || !isset($result['editable'])) {
            return false;
        }

        return (bool) $result['editable'];
    }

    /**
     * Check whether the site domain has a proxied DNS record in Cloudflare.
     *
     * @return bool
     */
    private function is_domain_proxied(): bool
    {
        if (!$this->has_valid_credentials()) {
            return false;
        }

        $domain = $this->get_site_domain();
        if ($domain === '') {
            return false;
        }

        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'dns_records']);
        $url .= '?name=' . urlencode($domain);

        $response = $this->make_request('GET', $url);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $records = $body['result'] ?? [];

        if (!is_array($records) || $records === []) {
            return false;
        }

        foreach ($records as $record) {
            if (!empty($record['proxied'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * AJAX handler for saving settings.
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        try {
            (new Validator())->validate_ajax_request();

            $decoded_settings = (new Validator())->validate_ajax_settings();

            $decoded_settings = $this->detect_zone_for_settings($decoded_settings);

            $this->validate_apo_zone_ready($decoded_settings);

            if (!$this->save_settings($decoded_settings)) {
                throw new Exception(__('Unable to save Cloudflare APO settings.', 'runcache'));
            }

            wp_send_json_success([
                'message' => $this->get_provider_label() . ' settings saved successfully',
                'data' => $this->get_settings(),
            ]);
        } catch (Exception $exception) {
            $this->logger->error('Failed to save ' . $this->get_provider_label() . ' settings', [
                'error' => $exception->getMessage(),
            ]);
            wp_send_json_error([
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Ensure the selected zone is ready for APO.
     *
     * @param array $settings
     * @return void
     * @throws Exception
     */
    private function validate_apo_zone_ready(array $settings): void
    {
        $token = $this->normalize_api_token($settings['api_token'] ?? '');
        $zone_id = sanitize_text_field($settings['zone_id'] ?? '');
        $auth_type = strtolower(sanitize_text_field($settings['auth_type'] ?? 'token'));
        if (!in_array($auth_type, ['token', 'key'], true)) {
            $auth_type = 'token';
        }
        $account_email = sanitize_email($settings['account_email'] ?? '');

        if ($token === '' || $zone_id === '') {
            throw new Exception(esc_html__('Cloudflare APO API token and Zone ID are required.', 'runcache'));
        }

        if ($auth_type === 'key' && $account_email === '') {
            throw new Exception('Cloudflare account email is required when using an API key');
        }

        $original_token = $this->api_token;
        $original_zone_id = $this->zone_id;
        $original_auth_type = $this->auth_type;
        $original_email = $this->account_email;

        $this->api_token = $token;
        $this->zone_id = $zone_id;
        $this->auth_type = $auth_type;
        $this->account_email = $account_email;

        try {
            $editable = $this->is_apo_editable();
            if (!$editable) {
                throw new Exception(
                    __('Cloudflare APO is not available or not editable for this zone. Please make sure the APO subscription is active in the Cloudflare dashboard.', 'runcache')
                );
            }

            $proxied = $this->is_domain_proxied();
            if (!$proxied) {
                throw new Exception(
                    __('Cloudflare APO requires the site domain to be proxied through Cloudflare. Please enable the orange cloud for the DNS record in the Cloudflare dashboard.', 'runcache')
                );
            }
        } finally {
            $this->api_token = $original_token;
            $this->zone_id = $original_zone_id;
            $this->auth_type = $original_auth_type;
            $this->account_email = $original_email;
        }
    }

    /**
     * User-facing message when credentials are missing during verify().
     *
     * @return string
     */
    protected function get_verify_missing_credentials_message(): string
    {
        return __('Cloudflare APO API token and Zone ID are required.', 'runcache');
    }

    /**
     * User-facing message when the configured zone does not match the site domain.
     *
     * @param string $site_domain
     * @param string $zone_name
     * @return string
     */
    protected function get_verify_zone_mismatch_message(string $site_domain, string $zone_name): string
    {
        return sprintf(
            /* translators: %1$s: site domain; %2$s: selected Cloudflare zone name */
            __('Cloudflare APO verification failed: the selected zone "%2$s" does not match the site domain "%1$s".', 'runcache'),
            $site_domain,
            $zone_name
        );
    }

    /**
     * User-facing success message for verify().
     *
     * @return string
     */
    protected function get_verify_success_message(): string
    {
        return __('Cloudflare APO connection successful.', 'runcache');
    }

    /**
     * Include the APO-enabled flag in the stats dashboard.
     *
     * @param array $settings
     * @return array
     */
    protected function get_extra_stats(array $settings): array
    {
        return [
            'apo_enabled' => (bool) ($settings['apo_enabled'] ?? false),
        ];
    }
}

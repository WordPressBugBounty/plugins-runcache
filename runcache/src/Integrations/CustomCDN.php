<?php

namespace RunCache\Integrations;

use RunCache\Core\Cdn;
use RunCache\Core\Setting;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom CDN integration handler
 */
class CustomCDN
{
    private Logger $logger;
    private Setting $setting;
    private Validator $validator;
    private ?Cdn $cdn = null;
    private string $hostname = '';
    private array $default_settings = [
        'hostname' => '',
        'allowed_extensions' => [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'ico',
            'mp4',
            'css',
            'js',
            'svg',
            'woff',
            'woff2',
            'ttf',
            'eot'
        ],
    ];

/**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();
        $this->validator = new Validator();
        $this->cdn = new Cdn();
    }

    /**
     * Initialize integration hooks
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cdn_providers', [$this, 'provider_registration']);

        add_action('wp_ajax_runcache_get_custom_cdn_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_custom_cdn_settings', [$this, 'ajax_save_settings']);
    }

    /**
     * Register provider on the CDN filter
     *
     * @param array $providers Existing providers
     * @return array
     */
    public function provider_registration(array $providers): array
    {
        $this->logger->info('Registering provider', ['provider' => 'Custom CDN']);
        $providers['custom'] = $this;

        return $providers;
    }

    /**
     * Enable the custom CDN
     *
     * @return bool
     */
    public function enable(): bool
    {
        $settings = $this->get_settings();
        $hostname = $settings['hostname'] ?? '';

        if (empty($hostname)) {
            $this->logger->error('Hostname is required', ['provider' => 'Custom CDN']);
            return false;
        }

        $this->hostname = $hostname;
        $this->logger->info('Enabled', [
            'provider' => 'Custom CDN',
            'hostname' => $hostname,
        ]);

        return true;
    }

    /**
     * Disable the custom CDN
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->logger->info('Disabled', ['provider' => 'Custom CDN']);
        $this->hostname = '';

        return true;
    }

    /**
     * Verify the custom CDN configuration
     *
     * @return bool
     */
    public function verify(): bool
    {
        $settings = $this->get_settings();
        $hostname = $settings['hostname'] ?? '';

        if (empty($hostname)) {
            $this->logger->warning('Verification failed: hostname missing', ['provider' => 'Custom CDN']);
            return false;
        }

        $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
        if (!empty($site_host) && $this->normalize_hostname($hostname) === $this->normalize_hostname($site_host)) {
            $this->logger->warning('Verification failed: hostname matches site host', [
                'provider' => 'Custom CDN',
                'hostname' => $hostname,
            ]);
            return false;
        }

        $this->logger->info('Verification successful', [
            'provider' => 'Custom CDN',
            'hostname' => $hostname,
        ]);

        return true;
    }

    /**
     * Get cache settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $cdn = $this->get_cdn_manager();
        $cdn_settings = $cdn->get_settings();

        if (isset($cdn_settings['provider']) && isset($cdn_settings['provider']['custom'])) {
            $settings = $cdn_settings['provider']['custom'];
        } else {
            $settings = $this->default_settings;
        }

        if (!isset($settings['allowed_extensions']) || !is_array($settings['allowed_extensions'])) {
            $settings['allowed_extensions'] = $this->default_settings['allowed_extensions'];
        }

        if (isset($settings['hostname'])) {
            $settings['hostname'] = $this->sanitize_hostname($settings['hostname']);
        }

        return $settings;
    }

    /**
     * Save cache settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        $should_enable = null;
        if (array_key_exists('enable', $settings)) {
            $should_enable = $this->validator->sanitize_bool($settings['enable']);
        }

        $requested_cdn_type = null;
        if (isset($settings['cdn_type'])) {
            $requested_cdn_type = sanitize_key((string) $settings['cdn_type']);
        }

        $merged_settings = wp_parse_args($settings, $this->default_settings);

        $hostname = $this->sanitize_hostname($merged_settings['hostname']);
        if (empty($hostname)) {
            $this->logger->error('Hostname is required', ['provider' => 'Custom CDN']);
            return false;
        }

        $allowed_extensions = $this->sanitize_allowed_extensions($merged_settings['allowed_extensions']);

        $sanitized_settings = [
            'hostname' => $hostname,
            'allowed_extensions' => $allowed_extensions,
        ];

        $cdn = $this->get_cdn_manager();
        $cdn_settings = $cdn->get_settings();
        if (!isset($cdn_settings['provider']) || !is_array($cdn_settings['provider'])) {
            $cdn_settings['provider'] = [];
        }

        $cdn_settings['provider']['custom'] = $sanitized_settings;

        if ($should_enable === null) {
            $should_enable = true;
        }

        $cdn_settings['enable'] = $should_enable;

        if ($should_enable) {
            $cdn_settings['cdn_type'] = 'custom';
        } else {
            if ($requested_cdn_type !== null) {
                $cdn_settings['cdn_type'] = $requested_cdn_type;
            } elseif (($cdn_settings['cdn_type'] ?? '') === 'custom') {
                $cdn_settings['cdn_type'] = '';
            }
        }

        $result = $cdn->save_settings($cdn_settings);
        if (!$result) {
            $this->logger->error('Failed to persist settings', ['provider' => 'Custom CDN']);
            return false;
        }

        $this->hostname = $hostname;
        $this->logger->info('Settings saved successfully', [
            'provider' => 'Custom CDN',
            'hostname' => $hostname,
        ]);

        return true;
    }

    /**
     * AJAX handler for getting settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success($this->get_settings());
    }

    /**
     * AJAX handler for saving settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();

        $settings = $this->validator->validate_ajax_settings();

        $result = $this->save_settings($settings);

        if (!$result) {
            wp_send_json_error(['message' => 'Failed to save settings']);
        }

        $cdn = $this->get_cdn_manager();
        $current_cdn_settings = $cdn->get_settings();

        wp_send_json_success([
            'message' => 'Custom CDN settings saved successfully',
            'data' => [
                'settings' => $this->get_settings(),
                'state' => [
                    'enable' => (bool) ($current_cdn_settings['enable'] ?? false),
                    'cdn_type' => (string) ($current_cdn_settings['cdn_type'] ?? ''),
                ],
            ],
        ]);
    }

    /**
     * Get the cached hostname
     *
     * @return string
     */
    public function get_hostname(): string
    {
        return $this->hostname;
    }

    /**
     * Sanitize hostname value
     *
     * @param string $hostname Raw hostname value
     * @return string
     */
    private function sanitize_hostname(string $hostname): string
    {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return '';
        }

        $hostname = preg_replace('#^https?://#i', '', $hostname);
        $hostname = rtrim($hostname, '/');

        return sanitize_text_field($hostname);
    }

    /**
     * Get CDN manager instance
     *
     * @return Cdn
     */
    private function get_cdn_manager(): Cdn
    {
        if (!$this->cdn instanceof Cdn) {
            $this->cdn = new Cdn($this->logger, $this->validator);
        }

        return $this->cdn;
    }

    /**
     * Normalize hostname for comparisons
     *
     * @param string $hostname Hostname to normalize
     * @return string
     */
    private function normalize_hostname(string $hostname): string
    {
        $hostname = $this->sanitize_hostname($hostname);

        return strtolower($hostname);
    }

    /**
     * Sanitize allowed extensions list
     *
     * @param mixed $extensions User-provided extensions
     * @return array
     */
    private function sanitize_allowed_extensions($extensions): array
    {
        if (!is_array($extensions)) {
            return $this->default_settings['allowed_extensions'];
        }

        $sanitized = [];
        foreach ($extensions as $extension) {
            $extension = strtolower(trim((string) $extension));
            $extension = ltrim($extension, '.');

            if ($extension !== '' && preg_match('/^[a-z0-9]+$/', $extension)) {
                $sanitized[] = $extension;
            }
        }

        return array_values(array_unique($sanitized));
    }
}

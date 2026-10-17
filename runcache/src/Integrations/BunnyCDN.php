<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\Cdn;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Support\Logger;
use RunCache\Support\Validator;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BunnyCDN cache integration
 */
class BunnyCDN
{
    private Logger $logger;
    private Setting $setting;
    private Validator $validator;
    private Cdn $cdn;
    private array $default_settings = [
        'api_key' => '',
        'pull_zone_id' => '',
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

    public const API_BASE_URL = 'https://api.bunny.net';
    public const ENDPOINT_PULL_ZONES = '/pullzone';
    public const ENDPOINT_PURGE_CACHE = '/cache';

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
     * Initialize integration
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cdn_providers', [$this, 'provider_registration']);
        add_action('wp_ajax_runcache_get_bunnycdn_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_bunnycdn_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_get_bunnycdn_pull_zones', [$this, 'ajax_get_pull_zones']);

        if ($this->cdn->is_enabled() && ($this->cdn->get_settings()['cdn_type'] ?? '') === 'bunnycdn') {
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            // @phpstan-ignore-next-line
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
        }
    }

    /**
     * Register as CDN provider
     *
     * @param array $providers
     * @return array
     */
    public function provider_registration(array $providers): array
    {
        $providers['bunnycdn'] = $this;
        return $providers;
    }

    /**
     * Enable BunnyCDN cache
     *
     * @return bool
     */
    public function enable(): bool
    {
        try {
            $settings = $this->get_settings();
            if (empty($settings['api_key']) || empty($settings['pull_zone_id'])) {
                throw new Exception('BunnyCDN API key and Pull Zone ID are required');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to enable cache', ['provider' => 'BunnyCDN', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Disable BunnyCDN cache
     *
     * @return bool
     */
    public function disable(): bool
    {
        return true;
    }

    /**
     * Verify BunnyCDN integration
     *
     * @return bool
     */
    public function verify(): bool
    {
        try {
            $cdn_settings = $this->cdn->get_settings();
            $provider_settings = $cdn_settings['provider']['bunnycdn'] ?? $this->get_settings();

            if (empty($provider_settings['api_key']) || empty($provider_settings['pull_zone_id'])) {
                $this->logger->warning('Missing required settings', [
                    'provider' => 'BunnyCDN',
                    'has_api_key' => !empty($provider_settings['api_key']),
                    'has_pull_zone_id' => !empty($provider_settings['pull_zone_id'])
                ]);
                return false;
            }

            $response = $this->make_request('GET', self::ENDPOINT_PULL_ZONES . '/' . $provider_settings['pull_zone_id']);

            if (is_wp_error($response)) {
                $this->logger->error('API request failed during verification', [
                    'provider' => 'BunnyCDN',
                    'error' => $response->get_error_message()
                ]);
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (!isset($data['Id']) || $data['Id'] != $provider_settings['pull_zone_id']) {
                $this->logger->error('API response invalid', [
                    'provider' => 'BunnyCDN',
                    'expected_id' => $provider_settings['pull_zone_id'],
                    'received_id' => $data['Id'] ?? 'not_set'
                ]);
                return false;
            }

            return true;

        } catch (Exception $e) {
            $this->logger->error('Verification failed', ['provider' => 'BunnyCDN', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $cdn_settings = $this->cdn->get_settings();

        if (isset($cdn_settings['provider']['bunnycdn'])) {
            return wp_parse_args($cdn_settings['provider']['bunnycdn'], $this->default_settings);
        }

        return [];
    }

    /**
     * Save settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        try {
            $merged_settings = wp_parse_args($settings, $this->default_settings);

            $sanitized_settings = [
                'api_key' => sanitize_text_field($merged_settings['api_key']),
                'pull_zone_id' => sanitize_text_field($merged_settings['pull_zone_id']),
                'hostname' => sanitize_text_field($merged_settings['hostname']),
                'allowed_extensions' => $this->sanitize_allowed_extensions($merged_settings['allowed_extensions']),
            ];

            if (empty($sanitized_settings['api_key'])) {
                throw new Exception('BunnyCDN API key is required');
            }

            $cdn_settings = $this->cdn->get_settings();
            $cdn_settings['provider']['bunnycdn'] = $sanitized_settings;
            $this->cdn->save_settings($cdn_settings);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to save settings', ['provider' => 'BunnyCDN', 'error' => $e->getMessage()]);
            return false;
        }
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
        try {
            $this->validator->validate_ajax_request();

            $settings = $this->validator->validate_ajax_settings();

            $result = $this->save_settings($settings);

            if (!$result) {
                throw new Exception('Failed to save BunnyCDN settings');
            }

            wp_send_json_success(['data' => $this->get_settings()]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save BunnyCDN settings', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to save BunnyCDN settings. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for getting pull zones
     *
     * @return void
     */
    public function ajax_get_pull_zones(): void
    {
        try {
            $this->validator->validate_ajax_request();

            check_ajax_referer('runcache_nonce');

            if (!isset($_POST['api_key'])) {
                throw new Exception('API key is required');
            }

            $api_key = sanitize_text_field(wp_unslash($_POST['api_key']));
            if (empty($api_key)) {
                throw new Exception('API key cannot be empty');
            }

            $response = $this->make_request('GET', self::ENDPOINT_PULL_ZONES, null, $api_key);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            wp_send_json_success(['pull_zones' => $body]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get BunnyCDN pull zones', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to load BunnyCDN pull zones. Please try again.', 'runcache')]);
        }
    }

    /**
     * Purge all cache
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        try {
            $settings = $this->get_settings();
            if (empty($settings['api_key']) || empty($settings['pull_zone_id'])) {
                throw new Exception('BunnyCDN credentials are not configured');
            }

            $endpoint = self::ENDPOINT_PULL_ZONES . '/' . $settings['pull_zone_id'] . self::ENDPOINT_PURGE_CACHE;
            $response = $this->make_request('POST', $endpoint, ['purgeAll' => true], $settings['api_key']);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
        } catch (Exception $exception) {
            $this->logger->error('Failed to purge all cache', ['provider' => 'BunnyCDN', 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Purge post cache
     *
     * @param int $post_id
     * @param string|array $urls
     * @return bool
     */
    public function purge_post_cache(int $post_id, $urls): bool
    {
        $response_body = [];

        try {
            $settings = $this->get_settings();
            if (empty($settings['api_key']) || empty($settings['pull_zone_id'])) {
                throw new Exception('BunnyCDN credentials are not configured');
            }

            $normalized_urls = $this->validator->normalize_url_list($urls);
            if (empty($normalized_urls)) {
                return true;
            }

            $endpoint = self::ENDPOINT_PULL_ZONES . '/' . $settings['pull_zone_id'] . self::ENDPOINT_PURGE_CACHE;
            $response = $this->make_request('POST', $endpoint, ['urls' => $normalized_urls], $settings['api_key']);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true) ?? [];
            $errors_raw = $response_body['errors'] ?? $response_body['Errors'] ?? [];
            $errors = array_filter(array_map('strval', (array) $errors_raw));
            $success_flag = filter_var($response_body['success'] ?? $response_body['Success'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($success_flag === false || !empty($errors)) {
                $error_message = $response_body['message'] ?? implode(' | ', $errors) ?: 'BunnyCDN reported a failed purge';
                throw new Exception($error_message);
            }

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to purge cache for post', [
                'provider' => 'BunnyCDN',
                'post_id' => $post_id,
                'urls' => $normalized_urls ?? [],
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sanitize allowed extensions
     *
     * @param mixed $extensions
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

            if ($extension !== '' && preg_match('/^[a-z0-9*]+$/', $extension)) {
                $sanitized[] = $extension;
            }
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * Make API request
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param string|null $api_key
     * @return WP_Error|array
     */
    private function make_request(string $method, string $endpoint, ?array $data = null, ?string $api_key = null)
    {
        $url = self::API_BASE_URL . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'AccessKey' => $api_key ?? $this->get_settings()['api_key'] ?? '',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($data !== null) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error("Failed to make {$method} request", [
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);

            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = $body['Message'] ?? 'Unknown error occurred';

            $this->logger->error("Failed {$method} request", [
                'url' => $url,
                'code' => $code,
                'message' => $error_message,
            ]);

            return new WP_Error('bunnycdn_api_error', $error_message);
        }

        return $response;
    }
}

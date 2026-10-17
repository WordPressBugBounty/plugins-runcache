<?php

namespace RunCache\Support;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server type detection and information
 */
class Server
{
    private const SERVER_TYPE_TRANSIENT = 'runcache_server_type';

    private Logger $logger;
    private array $default_options;

    /**
     * @param Logger|null $logger
     */
    public function __construct(
        ?Logger $logger = null
    ) {
        $this->logger = $logger ?? new Logger();

        $version = defined('RUNCACHE_VERSION') ? RUNCACHE_VERSION : '1.0';
        $locale = function_exists('get_locale') ? get_locale() : 'en-US';

        $this->default_options = [
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent' => 'RunCache/' . $version . ' (WordPress Plugin)',
            // Header checks never verify TLS; both request paths set this too.
            'sslverify' => false,
            'headers' => [
                'X-Runcache-Internal' => '1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => $locale,
                'Host' => 'localhost'
            ]
        ];
    }

    /**
     * Initialize server detection
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_get_server_type', [$this, 'ajax_get_server_type']);
    }

    /**
     * Check if Apache
     *
     * @param array|null $headers
     * @return bool
     */
    private function is_apache(?array $headers = null): bool
    {
        global $is_apache;
        if (isset($is_apache) && $is_apache) {
            return true;
        }

        $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (stripos($server_software, 'apache') !== false) {
            return true;
        }

        if (function_exists('apache_get_version')) {
            return true;
        }

        if ($headers !== null) {
            $server_header = $headers['Server'] ?? $headers['server'] ?? '';
            if (stripos($server_header, 'apache') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if RunCloud Nginx (not container)
     *
     * @param array|null $headers
     * @return bool
     */
    private function is_runcloud_nginx(?array $headers = null): bool
    {
        if ($headers !== null) {
            $server_header = $headers['Server'] ?? $headers['server'] ?? '';
            if (stripos($server_header, 'nginx-rc') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if Nginx
     *
     * @param array|null $headers
     * @return bool
     */
    private function is_nginx(?array $headers = null): bool
    {
        global $is_nginx;
        if (isset($is_nginx) && $is_nginx) {
            return true;
        }

        $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (stripos($server_software, 'nginx') !== false) {
            return true;
        }

        if ($headers !== null) {
            $server_header = $headers['Server'] ?? $headers['server'] ?? '';
            if (stripos($server_header, 'nginx') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if LiteSpeed
     *
     * @param array|null $headers
     * @return bool
     */
    private function is_litespeed(?array $headers = null): bool
    {
        $server_software = sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (stripos($server_software, 'litespeed') !== false) {
            return true;
        }

        if (stripos($server_software, 'openlitespeed') !== false) {
            return true;
        }

        if (isset($_SERVER['LSAPI_VERSION']) || isset($_SERVER['LSWS_EDITION'])) {
            return true;
        }

        if ($headers !== null) {
            $server_header = strtolower($headers['Server'] ?? $headers['server'] ?? '');
            if (stripos($server_header, 'litespeed') !== false || stripos($server_header, 'openlitespeed') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if running in RunCloud container
     *
     * @param array|null $headers
     * @return bool
     */
    public function is_runcloud_container(?array $headers = null): bool
    {
        if (defined('RCWP_IS_CONTAINERIZED') && RCWP_IS_CONTAINERIZED === true) {
            return true;
        }

        if (strpos(gethostname(), 'RC-CONTAINER') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check if running in Docker environment
     *
     * @return bool
     */
    public function is_docker(): bool
    {
        return !empty(getenv('RUNCACHE_DOCKER')) || $this->is_runcloud_container();
    }

    /**
     * Get the internal host for Docker requests
     * Returns configured host or attempts auto-discovery
     *
     * @return string
     */
    public function get_internal_host(): string
    {
        $internal_host = getenv('RUNCACHE_INTERNAL_HOST');
        if (!empty($internal_host)) {
            $this->logger->info('Using configured Docker internal host', ['host' => $internal_host]);
            return $internal_host;
        }

        if ($this->is_runcloud_container()) {
            $this->logger->info('Using RunCloud container internal host', ['host' => 'host']);
            return "host";
        }

        $docker_services = ['nginx', 'apache', 'webserver', 'web'];
        foreach ($docker_services as $service) {
            if (@gethostbyname($service) !== $service) {
                return $service;
            }
        }

        return '127.0.0.1';
    }

    /**
     * Make a GET request and return response headers
     *
     * @param string $url The URL to request
     * @param array $options Additional request options
     * @param bool $use_public Whether to request the public URL directly instead of the internal loopback
     * @return array|null
     */
    public function get_headers(string $url, array $options = [], bool $use_public = false): ?array
    {
        $url = (new Validator())->sanitize_url($url);

        if (empty($url)) {
            $this->logger->error('Invalid URL provided', ['url' => $url]);
            return null;
        }

        if (!empty($_SERVER['HTTP_X_RUNCACHE_INTERNAL'])) {
            return [];
        }

        $parsed_url = wp_parse_url($url);
        $host = $parsed_url['host'] ?? 'localhost';

        $path = $parsed_url['path'] ?? '/';
        $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $scheme = $parsed_url['scheme'] ?? 'http';

        $options = wp_parse_args($options, $this->default_options);
        $options['headers']['Host'] = $host;

        // This method only serves internal header checks. TLS verification
        // stays off so certificate problems can never break detection.
        if ($use_public) {
            $options['sslverify'] = false;
            $request_url = $url;
            unset($options['headers']['X-Runcache-Internal']);
        } else {
            $is_docker = $this->is_docker();
            $target_host = $is_docker ? $this->get_internal_host() : '127.0.0.1';
            $request_url = $scheme . '://' . $target_host . $path . $query;

            $is_nginx = strpos($url, 'nginx') !== false;
            $redirection = ($is_nginx && $is_docker) ? 0 : 5;
            $options['redirection'] = $redirection;

            // Loopbacks target 127.0.0.1 or a Docker service name. The
            // certificate never matches, so TLS verification stays off.
            $options['sslverify'] = false;
        }

        $response = wp_remote_get($request_url, $options);

        if (is_wp_error($response)) {
            $this->logger->error('WP Remote GET failed', [
                'url' => $request_url,
                'error' => $response->get_error_message()
            ]);
            return [];
        }

        $headers_response = wp_remote_retrieve_headers($response);
        $headers = [];

        foreach ($headers_response as $key => $value) {
            $headers[$key] = $value;
        }

        return $headers;
    }

    /**
     * Get the cached server type
     *
     * Read-only and free to call on any request. Serves the transient value
     * or an empty string. Never performs HTTP and never schedules anything.
     *
     * @return string
     */
    public function get_server_type(): string
    {
        $cached = get_transient(self::SERVER_TYPE_TRANSIENT);

        return is_string($cached) ? $cached : '';
    }

    /**
     * Run server detection and cache the result
     *
     * This is the only method in this class that performs HTTP. It runs only
     * from the runcache-page AJAX endpoint. Order: container constant, then
     * LiteSpeed runtime signals, then one loopback request for the header
     * chain. SERVER_SOFTWARE fallbacks stay as last resort in each check.
     *
     * @return string
     */
    private function refresh_server_type(): string
    {
        $server_type = '';

        // RunCloud container: constant or hostname only, no HTTP needed.
        if ($this->is_runcloud_container()) {
            $server_type = 'nginx-rc';
        }

        // LiteSpeed runtime signals: LSAPI environment only, no HTTP needed.
        if ($server_type === '' && $this->has_litespeed_runtime_signals()) {
            $server_type = 'litespeed';
        }

        if ($server_type === '') {
            // Fetch headers once and pass to all detection methods
            $headers = $this->get_headers(home_url('/'), [
                'timeout' => 10,
                'redirection' => 0
            ]);

            if ($this->is_runcloud_nginx($headers)) {
                $server_type = 'nginx-rc';
            }

            if ($server_type === '' && $this->is_nginx($headers)) {
                $server_type = 'nginx';
            }

            if ($server_type === '' && $this->is_litespeed($headers)) {
                $server_type = 'litespeed';
            }

            if ($server_type === '' && $this->is_apache($headers)) {
                $server_type = 'apache';
            }
        }

        // Success: cache 1h. Failure: cache 5m to avoid retry storms.
        $ttl = $server_type !== '' ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
        set_transient(self::SERVER_TYPE_TRANSIENT, $server_type, $ttl);

        return $server_type;
    }

    /**
     * Check LiteSpeed runtime signals that need no HTTP request
     *
     * Covers the LSAPI environment and the LiteSpeed SAPI name.
     *
     * @return bool
     */
    private function has_litespeed_runtime_signals(): bool
    {
        if (isset($_SERVER['LSAPI_VERSION']) || isset($_SERVER['LSWS_EDITION'])) {
            return true;
        }

        return php_sapi_name() === 'litespeed';
    }

    /**
     * Convert a $_SERVER key to its HTTP header name.
     *
     * PHP stores request headers in $_SERVER with an HTTP_ prefix and underscores.
     * This method reverses that: HTTP_X_DEVICE → X-Device,
     * HTTP_ACCEPT_LANGUAGE → Accept-Language.
     *
     * Keys that do not start with HTTP_ are returned title-cased
     * (e.g. CONTENT_TYPE → Content-Type).
     *
     * @param string $server_key The $_SERVER key (e.g. HTTP_X_DEVICE).
     * @return string The HTTP header name (e.g. X-Device).
     */
    public static function server_var_to_header_name(string $server_key): string
    {
        $name = $server_key;
        if (str_starts_with($name, 'HTTP_')) {
            $name = substr($name, 5);
        }

        $parts = explode('_', $name);
        $parts = array_map(static fn (string $part): string => ucfirst(strtolower($part)), $parts);

        return implode('-', $parts);
    }

    /**
     * AJAX handler for getting server type
     *
     * Serves a warm transient instantly. Only a cold value triggers
     * detection here, on the runcache-page path. Inline HTTP is allowed
     * in this handler only.
     *
     * @return void
     */
    public function ajax_get_server_type(): void
    {
        (new Validator())->validate_ajax_request();

        try {
            $server_type = $this->get_server_type();
            if ($server_type === '') {
                $server_type = $this->refresh_server_type();
            }

            if (empty($server_type)) {
                throw new Exception('Failed to detect server type');
            }

            $config_dir = (new File())->get_config_dir();

            wp_send_json_success([
                'server_type' => $server_type,
                'document_root' => untrailingslashit(ABSPATH),
                'config_dir' => $config_dir !== false ? untrailingslashit($config_dir) : untrailingslashit(ABSPATH),
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'admin_url' => admin_url()
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get server type', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to detect the server type. Please try again.', 'runcache')]);
        }
    }
}

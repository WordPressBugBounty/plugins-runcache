<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\ObjectCache;
use RunCache\Core\PageCache;
use RunCache\Core\Setting;
use RunCache\Support\Dropin;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Server;
use RunCache\Support\Validator;
use RunCache\Integrations\Interfaces\CacheProvider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redis integration handler
 *
 * Handles Redis cache integration with WordPress including:
 * - Cache configuration and settings management
 * - Connection verification and setup
 * - Generated-config channel for drop-in connection settings
 * - Cache purging (full and selective)
 * - AJAX endpoints for admin interface
 */
class Redis implements CacheProvider
{
    protected Logger $logger;
    private Setting $setting;
    private Server $server;
    private ?\Redis $connection = null;
    private ?string $key_prefix = null;

    private const DROPIN_SIGNATURE = 'RunCache WordPress plugin';
    private const REDIS_MARKER = 'Redis-backed page cache drop-in';
    private const LEGACY_REDIS_SIGNATURE = 'RunCache Advanced Cache Drop-in - Redis Version';

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null,
        ?Server $server = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting($this->logger);
        $this->server = $server ?? new Server($this->logger);
    }

    /**
     * Get default Redis settings
     *
     * Uses 'host' as the host when running in a RunCloud container,
     * otherwise falls back to 127.0.0.1.
     *
     * @return array
     */
    private function get_default_settings(): array
    {
        $is_container = $this->server->is_runcloud_container();

        return [
            'host'     => $is_container ? 'host' : '127.0.0.1',
            'port'     => '6379',
            'username' => '',
            'password' => '',
            'key'      => 'runcache:'
        ];
    }

    /**
     * Initialize Redis integration
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cache_providers', [$this, 'provider_registration']);

        add_action('wp_ajax_runcache_get_redis_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_redis_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_test_redis_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_runcache_check_phpredis', [$this, 'ajax_check_phpredis']);

        $cache = new PageCache($this->logger);
        if ($cache->get_cache_type() === 'redis') {
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
            add_action('runcache_after_purge_url', [$this, 'purge_url_cache']);
            add_action('runcache_after_purge_network', [$this, 'purge_network_page_cache']);
        }
    }

    /**
     * Register this class as a cache provider
     *
     * @param array $providers Existing providers array
     * @return array Modified providers array with Redis provider added
     */
    public function provider_registration(array $providers): array
    {
        $providers['redis'] = $this;
        return $providers;
    }

    /**
     * Get RCWP settings from constants
     *
     * @return array
     */
    protected function get_rcwp_settings(): array
    {
        if (!defined('RCWP_REDIS_PASSWORD') || !defined('RCWP_REDIS_DOMAIN')) {
            return [];
        }

        $password_array = constant('RCWP_REDIS_PASSWORD');
        $domain = constant('RCWP_REDIS_DOMAIN');

        return [
            'host' => $this->server->is_runcloud_container() ? 'host' : 'localhost',
            'port' => '6379',
            'key' => $domain,
            'username' => $password_array[0],
            'password' => $password_array[1],
        ];
    }

    /**
     * Get RUNCACHE constant config
     *
     * Password and username are optional. Hand-written constants are
     * the documented override input and always win over stored settings.
     *
     * @return array
     */
    protected function get_constant_config(): array
    {
        if (!defined('RUNCACHE_REDIS_HOST') || (string) constant('RUNCACHE_REDIS_HOST') === '') {
            return [];
        }

        return [
            'host' => constant('RUNCACHE_REDIS_HOST'),
            'port' => defined('RUNCACHE_REDIS_PORT') ? constant('RUNCACHE_REDIS_PORT') : '6379',
            'key' => defined('RUNCACHE_REDIS_KEY') ? constant('RUNCACHE_REDIS_KEY') : 'runcache:',
            'password' => defined('RUNCACHE_REDIS_PASSWORD') ? (string) constant('RUNCACHE_REDIS_PASSWORD') : '',
            'username' => defined('RUNCACHE_REDIS_USERNAME') ? (string) constant('RUNCACHE_REDIS_USERNAME') : '',
        ];
    }

    /**
     * Get Redis settings
     *
     * Hand-written RUNCACHE_REDIS_* constants stay the documented
     * override input, so they win over every other source.
     *
     * @return array
     */
    public function get_settings(): array
    {
        $runcache_config = $this->get_constant_config();
        if (!empty($runcache_config)) {
            return $runcache_config;
        }

        $stored = $this->get_stored_settings();
        if (!empty($stored)) {
            return $stored;
        }

        $rcwp_config = $this->get_rcwp_settings();
        if (!empty($rcwp_config)) {
            $this->save_settings($rcwp_config);
            return $rcwp_config;
        }

        return $this->get_default_settings();
    }

    /**
     * Get the configured Redis connection for the generated config
     * channel, without defaults and without any write side effect.
     *
     * Drop-ins read this channel before WordPress boots, so empty
     * values keep their built-in defaults.
     *
     * @return array
     */
    public function get_connection_config(): array
    {
        $constants = $this->get_constant_config();
        if (!empty($constants)) {
            return $constants;
        }

        $stored = $this->get_stored_settings();
        if (!empty($stored)) {
            return $stored;
        }

        return $this->get_rcwp_settings();
    }

    /**
     * Read the stored provider connection from the page-cache and
     * object-cache settings without writing anything.
     *
     * @return array
     */
    private function get_stored_settings(): array
    {
        $cache = new PageCache($this->logger);
        $cache_settings = $cache->get_settings();

        if (isset($cache_settings['provider']['redis']) && !empty($cache_settings['provider']['redis']['host'])) {
            return $cache_settings['provider']['redis'];
        }

        $object_settings = (new ObjectCache($this->logger))->get_settings();
        if (isset($object_settings['provider']['redis']) && !empty($object_settings['provider']['redis']['host'])) {
            return $object_settings['provider']['redis'];
        }

        return [];
    }

    /**
     * Save Redis settings
     *
     * Drop-ins read the generated config channel before WordPress
     * boots, so wp-config.php is never written.
     *
     * @param array $settings
     * @return bool
     */
    protected function save_settings(array $settings): bool
    {
        // The payload is admin-submitted JSON, so only known fields persist.
        $settings = array_intersect_key(
            $settings,
            array_flip(['host', 'port', 'username', 'password', 'key', 'database'])
        );
        $settings = wp_parse_args($settings, $this->get_default_settings());

        $settings['key'] = $this->normalize_key_prefix((string) ($settings['key'] ?? ''));

        if (empty($settings['host'])) {
            $this->logger->error('Invalid settings', ['provider' => 'Redis', 'error' => 'host is required']);
            return false;
        }

        if (!is_numeric($settings['port'])) {
            $this->logger->error('Invalid settings', ['provider' => 'Redis', 'error' => 'port must be numeric']);
            return false;
        }

        $dropin_installed = true;

        $pagecache = new PageCache($this->logger);
        $cache_settings = $pagecache->get_settings();
        if ($cache_settings['enable'] && $cache_settings['page_cache_type'] == 'redis') {
            $cache_settings['provider']['redis'] = $settings;
            $pagecache->save_settings($cache_settings);
        }

        $objectcache = new ObjectCache($this->logger);
        $cache_settings = $objectcache->get_settings();
        if ($cache_settings['enable']) {
            $cache_settings['provider']['redis'] = $settings;
            $objectcache->save_settings($cache_settings);

            $dropin = new Dropin();
            $dropin_installed = $dropin->install_object_cache_dropin();
        }

        $this->connection = null;
        $this->key_prefix = null;

        // The object-cache path fires no config-rewrite hook, so both
        // drop-ins need the rebuild here.
        $this->setting->write_config_file();

        return $dropin_installed;
    }

    /**
     * Normalize a key prefix to the plugin's key charset.
     *
     * @param string $key Raw prefix from settings or a payload.
     * @return string Prefix with one trailing colon, or the default when empty.
     */
    private function normalize_key_prefix(string $key): string
    {
        $key = trim($key);
        $key = (string) preg_replace('/[^A-Za-z0-9._:-]/', '', $key);
        $key = substr($key, 0, 100);
        $key = rtrim($key, ':');

        if ($key === '') {
            return 'runcache:';
        }

        return $key . ':';
    }

    /**
     * Enable Redis cache
     *
     * The drop-in reads connection settings from the generated config
     * channel at request time, before WordPress loads any other
     * configuration. wp-config.php is never written.
     *
     * @return bool
     */
    public function enable(): bool
    {
        $result = $this->install_dropin();

        if ($result) {
            do_action('runcache_redis_cache_enabled', $this);
        }

        return $result;
    }

    /**
     * Disable Redis cache
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->connection = null;
        $this->key_prefix = null;

        $result = $this->remove_dropin();

        if ($result) {
            do_action('runcache_redis_cache_disabled', $this);
        }

        return $result;
    }

    /**
     * Verify Redis connection
     *
     * @return array|bool
     */
    public function verify(): array|bool
    {
        $settings = $this->get_settings();

        if (empty($settings)) {
            return false;
        }

        // Gate 1: prove the Redis round-trip first. The drop-in's
        // advertised header proves nothing about connectivity.
        try {
            if (!$this->test_connection($settings)) {
                $this->logger->error('Redis connection verification failed', [
                    'provider' => 'Redis',
                    'host' => $settings['host'] ?? '',
                    'port' => $settings['port'] ?? '',
                ]);
                return [
                    'message' => 'Redis connection failed. Check the host, port, username, and password.',
                    'verified' => false
                ];
            }
        } catch (Exception $e) {
            $this->logger->error('Redis connection verification failed', ['error' => $e->getMessage()]);
            return [
                'message' => 'Redis connection verification failed. Please check the settings and try again.',
                'verified' => false
            ];
        }

        // Gate 2: only after connectivity is proven, confirm the drop-in serves.
        $cache = new PageCache($this->logger);
        $response = $cache->request_cache_type();

        return strtolower($response) === 'redis';
    }

    /**
     * Get cache statistics for Redis page cache.
     *
     * Counts keys matching the exact prefix used by
     * includes/runcache-page-redis.php and sums their sizes.
     *
     * @return array
     */
    public function get_stats(): array
    {
        try {
            $redis = $this->get_connection();
            $prefix = $this->get_page_cache_key_prefix();

            $keys = [];
            $iterator = null;
            do {
                $result = $redis->scan($iterator, $prefix . '*', 100);
                $keys = array_merge($keys, $this->normalize_scan_result($iterator, $result));
            } while ($iterator !== 0);

            $keys = array_unique($keys);
            $total_files = 0;
            $total_size = 0;
            $expired_files = 0;

            foreach ($keys as $key) {
                $raw = $redis->get($key);
                if ($raw === false || $raw === null) {
                    continue;
                }

                $total_files++;
                $total_size += strlen((string) $raw);

                $cached = json_decode((string) $raw, true);
                if (is_array($cached) && isset($cached['expires']) && time() > (int) $cached['expires']) {
                    $expired_files++;
                }
            }

            return [
                'total_files' => $total_files,
                'total_size' => $total_size,
                'total_gzipped_size' => 0,
                'expired_files' => $expired_files,
                'cache_hits' => 0,
                'cache_misses' => 0,
                'hit_rate' => 0,
                'compression_ratio' => 0,
                'provider' => 'redis',
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get Redis page cache stats', ['error' => $e->getMessage()]);
            return [
                'total_files' => 0,
                'total_size' => 0,
                'total_gzipped_size' => 0,
                'expired_files' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
                'hit_rate' => 0,
                'compression_ratio' => 0,
                'provider' => 'redis',
            ];
        }
    }

    /**
     * Get the Redis key prefix used by the page-cache drop-in.
     *
     * This mirrors the logic in includes/runcache-page-redis.php
     * and may differ from get_scoped_key_prefix() used for purging.
     *
     * @return string
     */
    protected function get_page_cache_key_prefix(): string
    {
        $host = isset($_SERVER['HTTP_HOST'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']))
            : '';
        if ($host === '') {
            $host = strtolower((string) wp_parse_url(get_site_url(), PHP_URL_HOST));
        }

        // Purge keys must match the keys the drop-in writes, so the
        // precedence mirrors the drop-in resolution.
        $connection = $this->get_connection_config();
        $prefix = defined('RUNCACHE_REDIS_KEY')
            ? rtrim((string) RUNCACHE_REDIS_KEY, ':')
            : rtrim((string) ($connection['key'] ?? 'runcache'), ':');

        $site = strtolower(preg_replace('/:\d+$/', '', $host));
        $site = preg_replace('/[^a-z0-9]/', '-', $site);

        return $prefix . ':' . $site . ':';
    }

    /**
     * Create Redis connection
     *
     * @param array $settings
     * @return \Redis
     */
    protected function create_connection(array $settings): \Redis
    {
        if (!class_exists('\\Redis')) {
            throw new Exception('PHP Redis extension is not installed');
        }

        $host = $settings['host'] ?? '';
        $port = isset($settings['port']) ? (int) $settings['port'] : 6379;

        if ($host === '') {
            throw new Exception('Redis host is not configured');
        }

        $redis = new \Redis();
        if (!$redis->connect($host, $port, 5.0)) {
            throw new Exception('Failed to connect to Redis server');
        }

        $username = $settings['username'] ?? '';
        $password = $settings['password'] ?? '';

        if ($username !== '' || $password !== '') {
            $auth_params = $username !== '' ? [$username] : [];
            if ($password !== '') {
                $auth_params[] = $password;
            }

            if (!$redis->auth($auth_params)) {
                throw new Exception('Redis authentication failed');
            }
        }

        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 1);

        return $redis;
    }

    /**
     * Get Redis connection
     *
     * @return \Redis
     */
    protected function get_connection(): \Redis
    {
        if ($this->connection instanceof \Redis) {
            return $this->connection;
        }

        $settings = $this->get_settings();
        if (empty($settings)) {
            throw new Exception('Redis settings are not configured');
        }

        $this->connection = $this->create_connection($settings);

        return $this->connection;
    }

    /**
     * Get Redis key prefix
     *
     * @param string|null $base_key Optional override, e.g. the prefix submitted for a save.
     * @return string
     */
    protected function get_key_prefix(?string $base_key = null): string
    {
        // A submitted prefix must be proven as-is, so it bypasses the cache.
        if ($base_key !== null && $base_key !== '') {
            return $base_key;
        }

        if ($this->key_prefix !== null) {
            return $this->key_prefix;
        }

        $settings = $this->get_settings();
        $this->key_prefix = $settings['key'] ?? 'runcache:';

        return $this->key_prefix;
    }

    /**
     * Load the shared Redis key mapping used by the page-cache drop-in.
     *
     * @return bool True when the mapping functions are available.
     */
    private function require_redis_key_mapping(): bool
    {
        if (function_exists('runcache_redis_page_url_patterns')) {
            return true;
        }

        $mapping_file = RUNCACHE_PLUGIN_DIR . 'includes/runcache-redis-keys.php';
        if (is_readable($mapping_file)) {
            require_once $mapping_file;
        }

        return function_exists('runcache_redis_page_url_patterns');
    }

    /**
     * Canonical host of the current site, as the drop-in stores it.
     *
     * @return string Host without port, never empty.
     */
    private function get_purge_host(): string
    {
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';

        return $host !== '' ? $host : 'localhost';
    }

    /**
     * Registered path of the current site on a subdirectory network.
     *
     * @return string Path without slashes at the ends, '' otherwise.
     */
    private function get_page_cache_site_path(): string
    {
        if (!Multisite::is_multisite() || is_subdomain_install()) {
            return '';
        }

        if (!function_exists('get_blog_details') || !function_exists('get_current_blog_id')) {
            return '';
        }

        $blog_details = get_blog_details(get_current_blog_id());
        if (!$blog_details instanceof \WP_Site) {
            return '';
        }

        return trim((string) $blog_details->path, '/');
    }

    /**
     * Registered paths of the network's child sites.
     *
     * The main site of a subdirectory network keeps these out of its own
     * purge scope.
     *
     * @return array<int, string> Child site paths without slashes at the ends.
     */
    private function get_child_site_paths(): array
    {
        if (!Multisite::is_multisite() || is_subdomain_install() || !function_exists('get_sites')) {
            return [];
        }

        $paths = [];
        $sites = get_sites(['number' => 9999]);
        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $path = trim((string) $site->path, '/');
            if ($path === '') {
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Get scoped key prefix for current site
     *
     * Mirrors the drop-in key family, so the connection test writes into
     * the namespace the drop-in actually reads. The drop-in repeats the
     * host as the first key path segment.
     *
     * @param string|null $base_key Optional base-prefix override.
     * @return string
     */
    protected function get_scoped_key_prefix(?string $base_key = null): string
    {
        if (!$this->require_redis_key_mapping()) {
            return rtrim($this->get_key_prefix($base_key), ':') . ':';
        }

        $host = runcache_redis_page_sanitize_host($this->get_purge_host());
        $head = runcache_redis_page_key_head($this->get_key_prefix($base_key), $host);
        $root = $head . ':' . $host;

        $site_path = $this->get_page_cache_site_path();
        if ($site_path === '') {
            return $root . ':';
        }

        $safe_segments = runcache_redis_page_sanitize_segments($site_path);
        if ($safe_segments === []) {
            return $root . ':';
        }

        return $root . '/' . implode('/', $safe_segments) . ':';
    }

    /**
     * Scans Redis with the given patterns and deletes the matching keys.
     *
     * SCAN keeps the load bounded and DEL runs in batches. An optional
     * filter drops keys outside the purge scope before deletion.
     *
     * @param \Redis             $redis    Redis connection.
     * @param array<int, string> $patterns Glob patterns to scan.
     * @param callable|null      $filter   Optional fn(string $key): bool scope filter.
     * @return int Number of deleted keys.
     */
    private function scan_and_delete(\Redis $redis, array $patterns, ?callable $filter = null): int
    {
        $deleted = 0;

        foreach ($patterns as $pattern) {
            $iterator = null;

            do {
                $result = $redis->scan($iterator, $pattern, 500);
                $keys = $this->normalize_scan_result($iterator, $result);

                if ($filter !== null && $keys !== []) {
                    $keys = array_values(array_filter($keys, $filter));
                }

                if ($keys === []) {
                    continue;
                }

                foreach (array_chunk($keys, 500) as $batch) {
                    $redis->del($batch);
                    $deleted += count($batch);
                }
            } while ($iterator !== 0);
        }

        return $deleted;
    }

    /**
     * Deletes one URL's page-cache entries in the drop-in's key shape.
     *
     * @param string $url URL to purge.
     * @return int Number of deleted keys, or -1 when the URL is unusable.
     */
    private function purge_url_keys(string $url): int
    {
        if (!$this->require_redis_key_mapping()) {
            $this->logger->error('Shared Redis key mapping is missing', ['provider' => 'Redis']);
            return -1;
        }

        $parsed_url = wp_parse_url($url);
        if ($parsed_url === false || empty($parsed_url['host'])) {
            return -1;
        }

        $redis = $this->get_connection();
        $host = (string) $parsed_url['host'];
        $path = isset($parsed_url['path']) ? (string) $parsed_url['path'] : '';

        $patterns = runcache_redis_page_url_patterns($this->get_key_prefix(), $host, $path);

        return $this->scan_and_delete($redis, $patterns);
    }

    /**
     * Test Redis connection
     *
     * @param array $settings
     * @return bool
     */
    public function test_connection(array $settings): bool
    {
        try {
            $redis = $this->create_connection($settings);

            // Prove the prefix the save will store, not the stored one.
            $submitted_key = isset($settings['key']) ? (string) $settings['key'] : '';
            $key_prefix = $this->get_scoped_key_prefix($submitted_key !== '' ? $submitted_key : null);

            $test_key = $key_prefix . 'test_' . uniqid('', true);
            $test_value = 'test_' . time();

            if (!$redis->set($test_key, $test_value, ['NX', 'EX' => 30])) {
                return false;
            }

            $read_value = $redis->get($test_key);
            if ($read_value !== $test_value) {
                return false;
            }

            $redis->del($test_key);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Redis connection test failed', [
                'provider' => 'Redis',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * AJAX handler for getting Redis settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $validator = new Validator();
        $validator->validate_ajax_request();

        $settings = $this->get_settings();
        $settings['key_locked'] = self::key_prefix_is_constant_locked();

        wp_send_json_success($settings);
    }

    /**
     * Whether a wp-config constant owns the key prefix.
     *
     * Constants win over stored settings, so the form input must not
     * suggest an editable value when one is defined.
     *
     * @return bool
     */
    private static function key_prefix_is_constant_locked(): bool
    {
        if (defined('RUNCACHE_REDIS_KEY')) {
            return true;
        }

        return defined('RCWP_REDIS_PASSWORD') && defined('RCWP_REDIS_DOMAIN');
    }

    /**
     * AJAX handler for saving Redis settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $validator = new Validator();
        $validator->validate_ajax_request();

        $settings = $validator->validate_ajax_settings();

        // Prove the exact value the save will store, so normalize first.
        $settings['key'] = $this->normalize_key_prefix((string) ($settings['key'] ?? ''));

        // A constant owns the prefix, so the saved value mirrors the chain
        // and the constants-versus-saved comparison stays exact.
        if (self::key_prefix_is_constant_locked()) {
            $settings['key'] = $this->normalize_key_prefix((string) ($this->get_settings()['key'] ?? ''));
        }

        if (!$this->test_connection($settings)) {
            wp_send_json_error([
                'message' => 'Redis connection failed. Check the host, port, username, and password.',
            ]);
        }

        $result = $this->save_settings($settings);
        if (!$result) {
            wp_send_json_error(['message' => 'Failed to save Redis settings']);
        }

        wp_send_json_success([
            'message' => 'Redis connection verified and settings saved successfully',
            'data' => $this->get_settings()
        ]);
    }

    /**
     * AJAX handler for testing Redis connection
     *
     * @return void
     */
    public function ajax_test_connection(): void
    {
        $validator = new Validator();
        $validator->validate_ajax_request();

        try {
            $settings = $this->get_settings();
            if (!$this->test_connection($settings)) {
                wp_send_json_error(['message' => 'Redis connection failed. Check the host, port, username, and password.']);
            }

            wp_send_json_success(['message' => 'Redis connection successful']);
        } catch (Exception $e) {
            $this->logger->error('Redis connection test failed', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to test the Redis connection. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for checking PHP Redis extension
     *
     * @return void
     */
    public function ajax_check_phpredis(): void
    {
        $validator = new Validator();
        $validator->validate_ajax_request();

        try {
            $response = [
                'is_available' => false,
                'message' => ''
            ];

            if (!extension_loaded('redis')) {
                $response['message'] = 'PHP Redis extension is not installed. Please install php-redis extension.';
                wp_send_json_success($response);
            }

            if (!class_exists('\Redis')) {
                $response['message'] = 'Redis class not found. Please make sure PHP Redis extension is properly installed.';
                wp_send_json_success($response);
            }

            $required_methods = ['connect', 'auth', 'select', 'set', 'get', 'delete'];
            $missing_methods = [];

            foreach ($required_methods as $method) {
                if (!method_exists('\Redis', $method)) {
                    $missing_methods[] = $method;
                }
            }

            if (!empty($missing_methods)) {
                $response['message'] = 'Required Redis methods missing: ' . implode(', ', $missing_methods) .
                                     '. Please update your PHP Redis extension.';
                wp_send_json_success($response);
            }

            $response['is_available'] = true;
            $response['message'] = 'PHP Redis extension is properly installed and available.';
            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to check Redis extension availability', ['error' => $e->getMessage()]);
            wp_send_json_error([
                'message' => __('Unable to check Redis extension availability. Please try again.', 'runcache')
            ]);
        }
    }

    /**
     * Install Redis drop-in
     *
     * Delegates to the shared drop-in installer, which generates a
     * self-contained advanced-cache.php for the active provider. This keeps
     * one install path for all providers.
     *
     * @return bool
     */
    protected function install_dropin(): bool
    {
        return (new Dropin($this->logger))->install_page_cache_dropin();
    }

    /**
     * Remove Redis drop-in
     *
     * @return bool
     */
    protected function remove_dropin(): bool
    {
        $file = new File();

        $target = WP_CONTENT_DIR . '/advanced-cache.php';

        if (!$file->exists($target)) {
            return true;
        }

        $content = $file->read($target);
        if ($content === false) {
            $this->logger->error('Failed to read advanced-cache.php during cleanup', ['provider' => 'Redis', 'path' => $target]);
            return false;
        }

        $isManaged = strpos($content, self::DROPIN_SIGNATURE) !== false || strpos($content, self::LEGACY_REDIS_SIGNATURE) !== false;

        if (!$isManaged || (strpos($content, self::REDIS_MARKER) === false && strpos($content, self::LEGACY_REDIS_SIGNATURE) === false)) {
            $this->logger->info('advanced-cache.php not created by this integration, skipping removal', ['provider' => 'Redis']);
            return true;
        }

        if (!$file->delete($target)) {
            $this->logger->error('Failed to remove advanced-cache drop-in', ['provider' => 'Redis', 'path' => $target]);
            return false;
        }

        $backupFiles = glob($target . '.backup.*') ?: [];
        if (!empty($backupFiles)) {
            $latestBackup = array_pop($backupFiles);
            if ($latestBackup && $file->exists($latestBackup) && $file->copy($latestBackup, $target)) {
                $this->logger->info('Restored advanced-cache.php from backup after cleanup', ['provider' => 'Redis', 'backup' => $latestBackup]);
            }
        }

        return true;
    }

    /**
     * Purge post cache
     *
     * @param int $post_id
     * @param string|array $urls
     * @return void
     */
    public function purge_post_cache(int $post_id, string|array $urls): void
    {
        $validator = new Validator();
        $urls = $validator->normalize_url_list($urls);

        if (empty($urls)) {
            $this->logger->info('No URLs to purge for post', [
                'provider' => 'Redis',
                'post_id' => $post_id,
            ]);

            return;
        }

        foreach ($urls as $url) {
            try {
                $deleted = $this->purge_url_keys($url);

                if ($deleted < 0) {
                    throw new Exception('Failed to parse URL: ' . sanitize_text_field($url));
                }

                $this->logger->info($deleted > 0 ? 'Cache purged for post' : 'No cache found for post', [
                    'provider' => 'Redis',
                    'post_id' => $post_id,
                    'url' => $url,
                    'keys_count' => $deleted,
                ]);
            } catch (Exception $e) {
                $this->logger->error('Failed to purge cache for post', [
                    'provider' => 'Redis',
                    'post_id' => $post_id,
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Purge cache by URL
     *
     * Deletes the URL's entry and its q_/e_ variant keys. The patterns
     * come from the shared key mapping, so the shapes match the drop-in.
     *
     * @param string $url
     * @return void
     */
    public function purge_url_cache(string $url): void
    {
        if (empty($url)) {
            $this->logger->warning('No URL provided for cache purge', ['provider' => 'Redis']);
            return;
        }

        try {
            $deleted = $this->purge_url_keys($url);

            if ($deleted < 0) {
                $this->logger->error('Failed to parse URL for purge', ['provider' => 'Redis', 'url' => $url]);
                return;
            }

            $this->logger->info($deleted > 0 ? 'Cache purged for URL' : 'No cache found for URL', [
                'provider' => 'Redis',
                'url' => $url,
                'keys_count' => $deleted
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to purge cache for URL', [
                'provider' => 'Redis',
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if Redis settings are valid
     *
     * @return bool
     */
    private function has_valid_settings(): bool
    {
        try {
            $settings = $this->get_settings();
            return !empty($settings);
        } catch (Exception $exception) {
            $this->logger->error('Failed to inspect settings', [
                'provider' => 'Redis',
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Normalizes Redis SCAN results across PhpRedis and Predis.
     *
     * PhpRedis updates the cursor via the by-reference $iterator argument and
     * returns a flat array of keys. Predis (and the raw Redis protocol) return
     * [cursor, [keys]]. This helper extracts the keys and updates the iterator
     * for Predis-style results so the calling loop can terminate.
     *
     * @param int|null $iterator Cursor variable passed by reference.
     * @param array|false $result The value returned by $redis->scan().
     * @return array<int,string> Array of keys (empty if result is invalid).
     */
    protected function normalize_scan_result(&$iterator, $result): array
    {
        if ($result === false) {
            $iterator = 0;
            return [];
        }

        if (!is_array($result)) {
            return [];
        }

        // Predis / protocol-style shape: [cursor (int), keys (array)].
        if (isset($result[0]) && is_int($result[0]) && isset($result[1]) && is_array($result[1])) {
            $iterator = $result[0];
            return array_values(array_filter($result[1], 'is_string'));
        }

        // PhpRedis shape: flat array of keys (cursor already updated by reference).
        return array_values(array_filter($result, 'is_string'));
    }

    /**
     * Check if other cache type is using Redis
     *
     * @return bool
     */
    protected function is_other_cache_using_redis(): bool
    {
        $is_object_cache = $this instanceof \RunCache\Integrations\RedisObjectCache;

        if ($is_object_cache) {
            $pageCache = new PageCache($this->logger);
            $settings = $pageCache->get_settings();

            return !empty($settings['enable'])
                && isset($settings['page_cache_type'])
                && $settings['page_cache_type'] === 'redis';
        } else {
            $objectCache = new ObjectCache($this->logger);
            $settings = $objectCache->get_settings();

            return !empty($settings['enable'])
                && $settings['object_cache_type'] === 'redis';
        }
    }

    /**
     * Purge all Redis page-cache entries for the current site.
     *
     * On the main site of a subdirectory network the child sites' entries
     * stay, so the scan result is filtered against the site list.
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        try {
            if (!$this->require_redis_key_mapping()) {
                $this->logger->error('Shared Redis key mapping is missing', ['provider' => 'Redis']);
                return;
            }

            $redis = $this->get_connection();
            $base_prefix = $this->get_key_prefix();
            $host = $this->get_purge_host();
            $site_path = $this->get_page_cache_site_path();

            $patterns = runcache_redis_page_site_patterns($base_prefix, $host, $site_path);

            $filter = null;
            $child_paths = [];
            if ($site_path === '' && Multisite::is_multisite() && !is_subdomain_install()) {
                $child_paths = $this->get_child_site_paths();
                $filter = static fn (string $key): bool => runcache_redis_page_key_in_site_scope(
                    $key,
                    $base_prefix,
                    $host,
                    $site_path,
                    $child_paths
                );
            }

            $deleted = $this->scan_and_delete($redis, $patterns, $filter);

            $this->logger->info('Cache purged successfully', [
                'provider' => 'Redis',
                'scope' => $site_path === '' ? 'host' : 'site',
                'site_path' => $site_path,
                'excluded_child_sites' => count($child_paths),
                'keys_count' => $deleted,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to purge cache', ['provider' => 'Redis', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Purge every Redis page-cache entry of the whole install.
     *
     * The network purge only; the pattern also covers the object-cache
     * namespace, which the network purge clears anyway.
     *
     * @return void
     */
    public function purge_network_page_cache(): void
    {
        try {
            if (!$this->require_redis_key_mapping()) {
                $this->logger->error('Shared Redis key mapping is missing', ['provider' => 'Redis']);
                return;
            }

            $redis = $this->get_connection();
            $pattern = runcache_redis_page_network_pattern($this->get_key_prefix());

            $deleted = $this->scan_and_delete($redis, [$pattern]);

            $this->logger->info('Network cache purged', [
                'provider' => 'Redis',
                'scope' => 'network',
                'keys_count' => $deleted,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to purge network cache', ['provider' => 'Redis', 'error' => $e->getMessage()]);
        }
    }
}

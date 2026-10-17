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
 * - wp-config.php constants management
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
     * Password and username are optional. update_wp_config() skips them
     * when the Redis server needs no password, so they default to ''.
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
     * @return array
     */
    public function get_settings(): array
    {
        $cache = new PageCache($this->logger);
        $cache_settings = $cache->get_settings();

        // Only accept a saved record with a usable host.
        // An empty record must not block the wp-config constants fallback.
        if (isset($cache_settings['provider']['redis']) && !empty($cache_settings['provider']['redis']['host'])) {
            return $cache_settings['provider']['redis'];
        }

        $runcache_config = $this->get_constant_config();
        if (!empty($runcache_config)) {
            return $runcache_config;
        }

        $rcwp_config = $this->get_rcwp_settings();
        if (!empty($rcwp_config)) {
            $this->save_settings($rcwp_config);
            return $rcwp_config;
        }

        return $this->get_default_settings();
    }

    /**
     * Save Redis settings
     *
     * @param array $settings
     * @return bool
     */
    protected function save_settings(array $settings): bool
    {
        $settings = wp_parse_args($settings, $this->get_default_settings());

        if (empty($settings['key'])) {
            $settings['key'] = 'runcache:';
        }

        if (empty($settings['host'])) {
            $this->logger->error('Invalid settings', ['provider' => 'Redis', 'error' => 'host is required']);
            return false;
        }

        if (!is_numeric($settings['port'])) {
            $this->logger->error('Invalid settings', ['provider' => 'Redis', 'error' => 'port must be numeric']);
            return false;
        }

        $this->update_wp_config($settings);

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
            return $dropin->install_object_cache_dropin();
        }

        $this->connection = null;
        $this->key_prefix = null;

        return true;
    }

    /**
     * Update wp-config.php with Redis constants
     *
     * @param array $settings
     * @return bool
     */
    protected function update_wp_config(array $settings): bool
    {
        $this->logger->info('Start writing wp-config constants', ['provider' => 'Redis']);
        $file = new File();

        $config_path = $file->locate_wp_config();
        if ($config_path === false) {
            throw new Exception('wp-config.php not found');
        }

        $config = $file->read($config_path);
        if ($config === false) {
            throw new Exception('Failed to read wp-config.php');
        }

        $config = $this->remove_redis_constants_from_content($config);

        if (!empty($settings)) {
            $constants = "\n/* RunCache Redis Configuration START */\n";
            $constants .= "define('RUNCACHE_REDIS_HOST', '" . addslashes($settings['host']) . "');\n";
            $constants .= "define('RUNCACHE_REDIS_PORT', '" . addslashes($settings['port']) . "');\n";

            if (!empty($settings['key'])) {
                $constants .= "define('RUNCACHE_REDIS_KEY', '" . addslashes($settings['key']) . "');\n";
            }

            if (!empty($settings['username'])) {
                $constants .= "define('RUNCACHE_REDIS_USERNAME', '" . addslashes($settings['username']) . "');\n";
            }

            if (!empty($settings['password'])) {
                $constants .= "define('RUNCACHE_REDIS_PASSWORD', '" . addslashes($settings['password']) . "');\n";
            }

            $constants .= "\n/* RunCache Redis Configuration END */\n";

            $config = preg_replace_callback(
                '/(\$table_prefix\s*=\s*[^\r\n]+[;\r\n]+)/',
                static fn(array $matches): string => $matches[1] . $constants,
                $config,
                1
            );

            $this->logger->info('Generated wp-config constants block', [
                'provider' => 'Redis',
                'has_host' => !empty($settings['host']),
                'has_port' => !empty($settings['port']),
                'has_password' => !empty($settings['password']),
                'has_username' => !empty($settings['username']),
            ]);
        }

        $write_success = $file->write($config_path, $config);
        if ($write_success === false) {
            throw new Exception('Failed to write wp-config.php');
        }

        $this->logger->info('Finish writing wp-config constants', ['provider' => 'Redis']);
        return true;
    }

    /**
     * Strip RUNCACHE_REDIS_* constants from wp-config.php.
     *
     * @return bool True on success (or constants already absent), false on failure
     */
    public function cleanup_wp_config(): bool
    {
        try {
            $this->update_wp_config([]);
            $this->logger->info('Stripped wp-config constants', ['provider' => 'Redis']);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clean up wp-config constants', [
                'provider' => 'Redis',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove Redis constants from config content
     *
     * @param string $config
     * @return string
     */
    protected function remove_redis_constants_from_content(string $config): string
    {
$this->logger->info('Remove constants from config content', ['provider' => 'Redis']);

        // Remove everything between START and END markers (including the markers themselves)
        $config = preg_replace(
            '/\n?\/\* RunCache Redis Configuration START \*\/.*\/\* RunCache Redis Configuration END \*\/\n?/s',
            '',
            $config
        );

        return $config;
    }

    /**
     * Enable Redis cache
     *
     * Writes the RUNCACHE_REDIS_* constants to wp-config.php before the
     * drop-in is installed, mirroring RedisObjectCache::enable(). The
     * page-cache drop-in reads these constants at request time, before
     * WordPress loads any other configuration.
     *
     * @return bool
     */
    public function enable(): bool
    {
        $settings = $this->get_settings();

        if (!empty($settings['host'])) {
            try {
                $this->update_wp_config($settings);
            } catch (\Throwable $e) {
                // Do not block the drop-in install here: the drop-in falls
                // back to 127.0.0.1:6379 when the constants are missing, so
                // caching can still work when wp-config.php is not writable.
                $this->logger->error('Failed to write Redis page cache constants to wp-config.php', [
                    'provider' => 'Redis',
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
        if (!$this->is_other_cache_using_redis()) {
            $this->update_wp_config([]);
        }

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

        try {
            $this->test_connection($settings);
        } catch (Exception $e) {
            $this->logger->error('Redis connection verification failed', ['error' => $e->getMessage()]);
            return [
                'message' => 'Redis connection verification failed. Please check the settings and try again.',
                'verified' => false
            ];
        }

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

        $prefix = defined('RUNCACHE_REDIS_KEY')
            ? rtrim((string) RUNCACHE_REDIS_KEY, ':')
            : 'runcache';

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
     * @return string
     */
    protected function get_key_prefix(): string
    {
        if ($this->key_prefix !== null) {
            return $this->key_prefix;
        }

        $settings = $this->get_settings();
        $this->key_prefix = $settings['key'] ?? 'runcache:';

        return $this->key_prefix;
    }

    /**
     * Get site identifier for key scoping
     *
     * @return string
     */
    private function get_site_identifier(): string
    {
        $site_url = get_site_url();
        $host = strtolower((string) wp_parse_url($site_url, PHP_URL_HOST));
        $path = trim((string) wp_parse_url($site_url, PHP_URL_PATH), '/');

        if ($host === '') {
            $host = 'localhost';
        }

        $identifier = $host;

        if ($path !== '') {
            $identifier .= ':' . str_replace('/', '-', $path);
        }

        $identifier = preg_replace('/[^a-z0-9:\-]/i', '-', $identifier);
        $identifier = preg_replace('/-+/', '-', $identifier);
        $identifier = trim($identifier, '-:');

        return $identifier;
    }

    /**
     * Get scoped key prefix for current site
     *
     * @return string
     */
    protected function get_scoped_key_prefix(): string
    {
        $base = rtrim($this->get_key_prefix(), ':');
        $identifier = $this->get_site_identifier();

        if ($identifier !== '') {
            return $base . ':' . $identifier . ':';
        }

        return $base . ':';
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

            $key_prefix = $this->get_scoped_key_prefix();

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

        wp_send_json_success($this->get_settings());
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

        try {
            $redis = $this->get_connection();
            $key_prefix = $this->get_scoped_key_prefix();

            foreach ($urls as $url) {
                try {
                    $parsed_url = wp_parse_url($url);
                    if ($parsed_url === false) {
                        throw new Exception('Failed to parse URL: ' . sanitize_text_field($url));
                    }

                    $host = $parsed_url['host'] ?? '';
                    $path = isset($parsed_url['path']) ? rtrim($parsed_url['path'], '/') : '';
                    $key_pattern = $key_prefix . $host . $path . '*';

                    $keys = $redis->keys($key_pattern);
                    if (!empty($keys)) {
                        $redis->del($keys);
                        $this->logger->info('Cache purged for post', [
                            'provider' => 'Redis',
                            'post_id' => $post_id,
                            'url' => $url,
                            'keys_count' => count($keys)
                        ]);
                    } else {
                        $this->logger->info('No cache found for post', [
                            'provider' => 'Redis',
                            'post_id' => $post_id,
                            'url' => $url
                        ]);
                    }
                } catch (Exception $e) {
                    $this->logger->error('Failed to purge cache for post', [
                        'provider' => 'Redis',
                        'post_id' => $post_id,
                        'url' => $url,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to purge cache for post', [
                'provider' => 'Redis',
                'post_id' => $post_id,
                'urls' => $urls,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Purge cache by URL
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
            $redis = $this->get_connection();
            $key_prefix = $this->get_scoped_key_prefix();

            $parsed_url = wp_parse_url($url);
            if ($parsed_url === false) {
                $this->logger->error('Failed to parse URL for purge', ['provider' => 'Redis', 'url' => $url]);
                return;
            }

            $host = $parsed_url['host'] ?? '';
            $path = isset($parsed_url['path']) ? rtrim($parsed_url['path'], '/') : '';
            $key_pattern = $key_prefix . $host . $path . '*';

            $keys = $redis->keys($key_pattern);
            if (!empty($keys)) {
                $redis->del($keys);
                $this->logger->info('Cache purged for URL', [
                    'provider' => 'Redis',
                    'url' => $url,
                    'keys_count' => count($keys)
                ]);
            } else {
                $this->logger->info('No cache found for URL', ['provider' => 'Redis', 'url' => $url]);
            }
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
     * Purge all Redis cache
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        try {
            $redis = $this->get_connection();
            $key_prefix = $this->get_scoped_key_prefix();

            $keys = $redis->keys($key_prefix . '*');
            if (!empty($keys)) {
                $redis->del($keys);
                $this->logger->info('Cache purged successfully', ['provider' => 'Redis', 'keys_count' => count($keys)]);
            } else {
                $this->logger->info('No cache found to purge', ['provider' => 'Redis']);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to purge cache', ['provider' => 'Redis', 'error' => $e->getMessage()]);
        }
    }
}

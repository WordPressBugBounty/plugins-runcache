<?php

namespace RunCache\Core;

use RunCache\Core\PageCache;
use RunCache\Core\Rules;
use RunCache\Integrations\Multisite;
use RunCache\Integrations\Redis;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings storage and retrieval
 */
class Setting
{
    public const OPTION_PREFIX = 'runcache_';
    public const OPTION_SUFFIX = '_settings';

    private Logger $logger;
    private Validator $validator;
    private Setting $setting;

    /**
     * @param Logger|null $logger
     * @param Validator|null $validator
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Validator $validator = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->validator = $validator ?? new Validator();
        $this->setting = $setting ?? $this;
    }

    /**
     * Initialize settings functionality
     *
     * @return void
     */
    public function init(): void
    {
        add_action('runcache_rules_updated', [$this, 'write_config_file']);
        add_action('runcache_purger_updated', [$this, 'write_config_file']);
        add_action('runcache_cache_settings_saved', [$this, 'write_config_file']);
    }

    /**
     * Get full option name with prefix and suffix
     *
     * @param string $key Setting key without prefix/suffix
     * @return string
     */
    private function get_option_name(string $key): string
    {
        return self::OPTION_PREFIX . $key . self::OPTION_SUFFIX;
    }

    /**
     * Get the current site host
     *
     * @return string
     */
    public static function get_site_host(): string
    {
        return wp_parse_url(home_url('/'), PHP_URL_HOST);
    }

    /**
     * Get option value from WordPress options
     *
     * @param string $key Setting key without prefix/suffix
     * @param mixed $default Default value if setting doesn't exist
     * @param bool $useMainSite If true, get option from main site in multisite
     * @return mixed
     */
    public function get(string $key, mixed $default = null, bool $useMainSite = false): mixed
    {
        $option_name = $this->get_option_name($key);

        // If useMainSite is true and we're in multisite, get from main site
        if ($useMainSite && function_exists('is_multisite') && is_multisite()) {
            if (function_exists('get_main_site_id') && function_exists('get_blog_option') && function_exists('get_current_blog_id')) {
                $main_site_id = get_main_site_id();
                if ($main_site_id && $main_site_id !== get_current_blog_id()) {
                    return get_blog_option($main_site_id, $option_name, $default);
                }
            }
        }

        // If this is the main site, return from current site
        if ($useMainSite && function_exists('is_multisite') && is_multisite()) {
            $main_site_id = get_main_site_id();
            if ($main_site_id === get_current_blog_id()) {
                return get_option($option_name, $default);
            }
        }

        if ($key === 'object_cache') {
            wp_cache_delete($option_name, 'options');

            $alloptions = wp_cache_get('alloptions', 'options');
            if ($alloptions && isset($alloptions[$option_name])) {
                wp_cache_delete('alloptions', 'options');
            }
        }

        return get_option($option_name, $default);
    }

    /**
     * Set option value in WordPress options
     *
     * @param string $key Setting key without prefix/suffix
     * @param mixed $value Value to save
     * @param bool $useMainSite If true, set option on main site in multisite
     * @return bool
     */
    public function set(string $key, mixed $value, bool $useMainSite = false): bool
    {
        if ($key === '') {
            return false;
        }

        try {
            $option_name = $this->get_option_name($key);

            // Get current value for comparison
            if ($useMainSite) {
                $current = function_exists('get_blog_option') ? get_blog_option(get_main_site_id(), $option_name, false) : false;
            } else {
                $current = get_option($option_name);
            }

            // Skip if value unchanged
            if (serialize($current) === serialize($value)) {
                return true;
            }

            if ($key === 'object_cache') {
                wp_cache_delete($option_name, 'options');

                $alloptions = wp_cache_get('alloptions', 'options');
                if ($alloptions && isset($alloptions[$option_name])) {
                    wp_cache_delete('alloptions', 'options');
                }
            }

            // update_option() and update_blog_option() auto-create option if not exists
            if ($useMainSite) {
                $result = function_exists('update_blog_option') ? update_blog_option(get_main_site_id(), $option_name, $value) : false;
            } else {
                $result = update_option($option_name, $value, true);
            }

            if ($result === false) {
                $this->logger->error('Failed to update option', [
                    'key' => $key,
                    'option_name' => $option_name,
                ]);

                return false;
            }

            // Prime the cache (also clears stale cache)
            if ($useMainSite) {
                if (function_exists('get_blog_option')) {
                    get_blog_option(get_main_site_id(), $option_name);
                }
            } else {
                get_option($option_name);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Exception updating option', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all settings for a key
     *
     * @param string $key Setting key without prefix/suffix
     * @return array
     */
    public function get_all(string $key = 'settings'): array
    {
        return $this->get($key, []);
    }

    /**
     * Delete all RunCache settings from database
     *
     * @return void
     */
    public function delete_all(): void
    {
        $setting_keys = [
            'page_cache',
            'object_cache',
            'rules',
            'purger',
            'preloader',
            'settings_transfer',
            'cdn',
            'edge',
            'cloudflare',
        ];

        foreach ($setting_keys as $key) {
            $option_name = $this->get_option_name($key);
            delete_option($option_name);
        }
    }

    /**
     * Generate config array for rules
     *
     * @param string $config_key The current site's config map key.
     * @return array
     */
    private function generate_config(string $config_key): array
    {
        $cache = (new PageCache())->get_settings();
        $rules = (new Rules())->get_settings();
        $object_cache = $this->get('object_cache');

        $excluded_urls = [];
        if (!empty($rules['exclude_url_mch'])) {
            if (is_array($rules['exclude_url_mch'])) {
                $excluded_urls = array_values(array_filter(array_map('trim', $rules['exclude_url_mch'])));
            } else {
                $excluded_urls = array_values(array_filter(array_map('trim', explode("\n", $rules['exclude_url_mch']))));
            }
        }

        $excluded_cookies = [];
        if (!empty($rules['exclude_cookie_mch'])) {
            if (is_array($rules['exclude_cookie_mch'])) {
                $excluded_cookies = array_values(array_filter(array_map('trim', $rules['exclude_cookie_mch'])));
            } else {
                $excluded_cookies = array_values(array_filter(array_map('trim', explode("\n", $rules['exclude_cookie_mch']))));
            }
        }

        $excluded_user_agents = [];
        if (!empty($rules['exclude_browser_mch'])) {
            if (is_array($rules['exclude_browser_mch'])) {
                $excluded_user_agents = array_values(array_filter(array_map('trim', $rules['exclude_browser_mch'])));
            } else {
                $excluded_user_agents = array_values(array_filter(array_map('trim', explode("\n", $rules['exclude_browser_mch']))));
            }
        }

        $excluded_ips = [];
        if (!empty($rules['exclude_visitorip_mch'])) {
            if (is_array($rules['exclude_visitorip_mch'])) {
                $excluded_ips = array_values(array_filter(array_map('trim', $rules['exclude_visitorip_mch'])));
            } else {
                $excluded_ips = array_values(array_filter(array_map('trim', explode("\n", $rules['exclude_visitorip_mch']))));
            }
        }

        $ignored_query_params = [];
        if (!empty($rules['ignore_query_mch'])) {
            if (is_array($rules['ignore_query_mch'])) {
                $ignored_query_params = array_values(array_filter(array_map('trim', $rules['ignore_query_mch'])));
            } else {
                $ignored_query_params = array_values(array_filter(array_map('trim', explode("\n", $rules['ignore_query_mch']))));
            }
        }

        $allowed_query_params = [];
        if (!empty($rules['allow_query_mch'])) {
            if (is_array($rules['allow_query_mch'])) {
                $allowed_query_params = array_values(array_filter(array_map('trim', $rules['allow_query_mch'])));
            } else {
                $allowed_query_params = array_values(array_filter(array_map('trim', explode("\n", $rules['allow_query_mch']))));
            }
        }

        $excluded_query_params = [];
        if (!empty($rules['exclude_query_mch'])) {
            if (is_array($rules['exclude_query_mch'])) {
                $excluded_query_params = array_values(array_filter(array_map('trim', $rules['exclude_query_mch'])));
            } else {
                $excluded_query_params = array_values(array_filter(array_map('trim', explode("\n", $rules['exclude_query_mch']))));
            }
        }

        // Drop-ins read connection settings before WordPress boots, so
        // they ride the generated config channel; empty keeps drop-in defaults.
        $redis_connection = (new Redis())->get_connection_config();
        $redis_config = [];
        if (!empty($redis_connection['host'])) {
            $redis_config = [
                'host' => (string) $redis_connection['host'],
                'port' => is_numeric($redis_connection['port'] ?? null) ? (int) $redis_connection['port'] : 6379,
                'key' => (string) ($redis_connection['key'] ?? 'runcache:'),
                'username' => (string) ($redis_connection['username'] ?? ''),
                'password' => (string) ($redis_connection['password'] ?? ''),
            ];
            if (isset($redis_connection['database'])) {
                $redis_config['database'] = (int) $redis_connection['database'];
            }
        }

        // The drop-in uses this prefix verbatim, so the writer owns the site identity.
        $redis_config['key_prefix'] = self::redis_key_prefix_for($config_key, (string) ($redis_connection['key'] ?? 'runcache:'));

        return [
            'cache_enabled' => $cache['enable'],
            // The object-cache drop-in reads this flag before WordPress loads.
            'object_cache_enabled' => is_array($object_cache) ? (bool) ($object_cache['enable'] ?? false) : false,
            'page_cache_type' => $cache['page_cache_type'],
            'gzip_enabled' => true,
            'cache_expiry' => 28800,
            'exclude_cookie_onn' => (bool) $rules['exclude_cookie_onn'],
            'exclude_cookie_mch' => $excluded_cookies,
            'exclude_url_onn' => (bool) $rules['exclude_url_onn'],
            'exclude_url_mch' => $excluded_urls,
            'exclude_query_onn' => (bool) $rules['exclude_query_onn'],
            'exclude_query_mch' => $excluded_query_params,
            'allow_query_onn' => (bool) $rules['allow_query_onn'],
            'allow_query_mch' => $allowed_query_params,
            'ignore_query_onn' => (bool) $rules['ignore_query_onn'],
            'ignore_query_mch' => $ignored_query_params,
            'exclude_browser_onn' => (bool) $rules['exclude_browser_onn'],
            'exclude_browser_mch' => $excluded_user_agents,
            'exclude_visitorip_onn' => (bool) $rules['exclude_visitorip_onn'],
            'exclude_visitorip_mch' => $excluded_ips,
            'exclude_auto_onn' => (bool) $rules['exclude_auto_onn'],
            'cache_key_extra_onn' => (bool) $rules['cache_key_extra_onn'],
            'cache_key_extra_var' => $rules['cache_key_extra_onn'] ? (string) $rules['cache_key_extra_var'] : '',
            'redis' => $redis_config,
        ];
    }

    /**
     * Write the current site's cache settings into the shared config file.
     *
     * A single writer and a per-site key merge keep concurrent saves from
     * losing sibling rows.
     *
     * @return bool True when the write succeeded.
     */
    public function write_config_file(): bool
    {
        $config_key = (new Multisite())->get_config_key();

        $config = $this->generate_config($config_key);
        if ([] === $config) {
            $this->logger->error('Failed to generate config');
            return false;
        }

        $config_file = RUNCACHE_CONFIG_FILE;

        try {
            $file = new File($this->logger);

            $written = $file->locked_update(
                $config_file,
                static function (array $current) use ($config_key, $config): array {
                    $current['sites'][$config_key] = $config;

                    // Sibling rows keep their stale unscoped prefix until
                    // their own next save, so refresh every baked prefix here.
                    foreach ($current['sites'] as $row_key => $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $base_key = (string) ($row['redis']['key'] ?? 'runcache:');
                        $row_host = (string) $row_key;
                        $prefix = self::redis_key_prefix_for($row_host, $base_key);

                        if (!isset($current['sites'][$row_key]['redis']) || !is_array($current['sites'][$row_key]['redis'])) {
                            $current['sites'][$row_key]['redis'] = [];
                        }

                        $current['sites'][$row_key]['redis']['key_prefix'] = $prefix;
                    }

                    return $current;
                }
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to write cache config file', [
                'path' => $config_file,
                'key' => $config_key,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (!$written) {
            $this->logger->error('Failed to atomically write config file', [
                'path' => $config_file,
                'key' => $config_key,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Remove one site's rows from the shared config file.
     *
     * The locked merge keeps concurrent saves from resurrecting or
     * corrupting the map.
     *
     * @param array<int, string> $config_keys Config map keys to remove.
     * @return bool True when the write succeeded (or nothing needed removal).
     */
    public function remove_config_rows(array $config_keys): bool
    {
        $config_keys = array_values(array_filter($config_keys, static function ($config_key): bool {
            return is_string($config_key) && $config_key !== '';
        }));

        if ($config_keys === []) {
            return true;
        }

        $config_file = RUNCACHE_CONFIG_FILE;

        // No file means no rows; do not create one just to remove from it.
        if (!is_file($config_file)) {
            return true;
        }

        try {
            $file = new File($this->logger);

            $written = $file->locked_update(
                $config_file,
                static function (array $current) use ($config_keys): array {
                    if (!isset($current['sites']) || !is_array($current['sites'])) {
                        return $current;
                    }

                    foreach ($config_keys as $config_key) {
                        unset($current['sites'][$config_key]);
                    }

                    return $current;
                }
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to remove site rows from config file', [
                'path' => $config_file,
                'keys' => $config_keys,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (!$written) {
            $this->logger->error('Failed to atomically remove site rows from config file', [
                'path' => $config_file,
                'keys' => $config_keys,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Build the site-scoped object cache key prefix for one config row.
     *
     * Global object-cache groups are network-wide, so the identifier is
     * the network domain on multisite and the row's site host otherwise.
     *
     * @param string $config_key The row's config key, e.g. 'example.com'.
     * @param string $base_key   Raw base key, e.g. 'runcache:'.
     * @return string Key prefix, e.g. 'runcache:example-test:'.
     */
    private static function redis_key_prefix_for(string $config_key, string $base_key): string
    {
        if (Multisite::is_multisite()) {
            $identifier = Multisite::object_cache_identifier();
        } else {
            $host = (string) explode('/', $config_key)[0];
            $identifier = Multisite::normalize_object_cache_host($host);
        }

        return Multisite::object_cache_key_prefix($base_key, $identifier);
    }
}

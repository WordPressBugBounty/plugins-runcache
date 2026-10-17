<?php

namespace RunCache\Core;

use RunCache\Core\PageCache;
use RunCache\Core\Rules;
use RunCache\Integrations\Multisite;
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
     * @return array
     */
    private function generate_config(): array
    {
        $cache = (new PageCache())->get_settings();
        $rules = (new Rules())->get_settings();

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

        return [
            'cache_enabled' => $cache['enable'],
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
            'cache_key_extra_var' => $rules['cache_key_extra_onn'] ? (string) $rules['cache_key_extra_var'] : ''
        ];
    }

    /**
     * Write the current site's cache settings into the shared config file.
     *
     * The file holds one top-level `sites` map keyed by site. Only this
     * method writes it. locked_update() merges only this site's key.
     *
     * @return bool True when the write succeeded.
     */
    public function write_config_file(): bool
    {
        $config_key = (new Multisite())->get_config_key();

        $config = $this->generate_config();
        if ($config === []) {
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
}

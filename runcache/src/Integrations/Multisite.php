<?php

namespace RunCache\Integrations;

use RunCache\Core\Setting;
use RunCache\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multisite Helper Class
 */
class Multisite
{
    private const TYPE_SINGLE_SITE = 'single';
    private const TYPE_SUBDOMAIN = 'subdomain';
    private const TYPE_SUBDIRECTORY = 'subdirectory';

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        private ?Logger $logger = null,
        private ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize multisite hooks
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cache_settings', [$this, 'extend_cache_settings']);
    }

    /**
     * Extend cache settings with multisite information
     *
     * @param array $settings
     * @return array
     */
    public function extend_cache_settings(array $settings): array
    {
        if ($this->is_multisite()) {
            $is_main_site = $this->is_main_site();

            // Get main site cache type for child sites
            $main_site_page_cache_type = '';
            if (!$is_main_site) {
                $main_site_settings = $this->setting->get('page_cache', null, true);
                $main_site_page_cache_type = $main_site_settings['page_cache_type'] ?? '';
            }

            $settings['multisite'] = [
                'is_multisite' => true,
                'type' => $this->get_multisite_type(),
                'is_main_site' => $is_main_site,
                'is_child_site' => !$is_main_site,
                'main_site_page_cache_type' => $main_site_page_cache_type,
            ];
        } else {
            $settings['multisite'] = [
                'is_multisite' => false,
                'type' => 'single',
                'is_main_site' => true,
                'is_child_site' => false,
                'main_site_page_cache_type' => '',
            ];
        }

        return $settings;
    }

    /**
     * Check if current installation is multisite
     *
     * @return bool
     */
    public static function is_multisite(): bool
    {
        return \is_multisite();
    }

    /**
     * Get the multisite type
     *
     * @return string
     */
    public function get_multisite_type(): string
    {
        if (!self::is_multisite()) {
            return self::TYPE_SINGLE_SITE;
        }

        if (\is_subdomain_install()) {
            return self::TYPE_SUBDOMAIN;
        }

        return self::TYPE_SUBDIRECTORY;
    }

    /**
     * Get the current site identifier for subdomain multisite
     *
     * @return string
     */
    public static function get_subdomain_identifier(): string
    {
        $host = Setting::get_site_host();

        $sanitized = preg_replace('/[^a-zA-Z0-9\.-]/', '-', $host);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        $sanitized = trim($sanitized, '-');

        return $sanitized ?: 'localhost';
    }

    /**
     * Get the current site identifier for subdirectory multisite
     *
     * @return string
     */
    public static function get_subdirectory_identifier(): string
    {
        if (function_exists('get_current_blog_id') && function_exists('get_blog_details')) {
            $blog_id = get_current_blog_id();

            if ($blog_id > 1) {
                $blog_details = get_blog_details($blog_id);

                if ($blog_details instanceof \WP_Site) {
                    $site_path = trim($blog_details->path, '/');

                    if (!empty($site_path)) {
                        return sanitize_file_name($site_path);
                    }
                }
            }
        }

        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $path = wp_parse_url($request_uri, PHP_URL_PATH) ?: '/';

        $path_parts = array_filter(explode('/', trim($path, '/')));
        $site_path = array_shift($path_parts) ?? '';

        if (empty($site_path)) {
            return 'main';
        }

        $sanitized = sanitize_file_name($site_path);
        $sanitized = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $sanitized);
        $sanitized = preg_replace('/-+/', '-', $sanitized);
        $sanitized = trim($sanitized, '-');

        return $sanitized ?: 'main';
    }

    /**
     * Get the config map key for the current site.
     *
     * Must stay in sync with runcache_get_config_key() in
     * includes/runcache-config.php. Both sides must produce identical keys.
     *
     * @return string Config key, e.g. 'example.com' or 'example.com/shop'.
     */
    public function get_config_key(): string
    {
        // Host source: canonical site URL. The drop-in uses HTTP_HOST.
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = $this->normalize_config_key_host(is_string($host) ? $host : '');

        // Subdirectory multisite child sites add their first path segment.
        $segment = '';
        if (self::is_multisite() && !\is_subdomain_install()) {
            $segment = $this->normalize_config_key_component($this->get_blog_path_segment());
        }

        if ($segment === '') {
            return $host;
        }

        return $host . '/' . $segment;
    }

    /**
     * First path segment of the current blog on a subdirectory network.
     *
     * @return string Segment without slashes, or '' on the main site.
     */
    private function get_blog_path_segment(): string
    {
        if (!function_exists('get_blog_details') || !function_exists('get_current_blog_id')) {
            return '';
        }

        $blog_details = get_blog_details(get_current_blog_id());
        if (!$blog_details instanceof \WP_Site) {
            return '';
        }

        $path = trim((string) $blog_details->path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);

        return (string) ($segments[0] ?? '');
    }

    /**
     * Normalize one config key component (host or path segment).
     *
     * Must stay in sync with runcache_normalize_config_component() in
     * includes/runcache-config.php.
     *
     * @param string $value Raw component value.
     * @return string Normalized component, possibly empty.
     */
    private function normalize_config_key_component(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    /**
     * Normalize a host for the config key: port and trailing dots removed.
     *
     * Must stay in sync with runcache_normalize_config_host() in
     * includes/runcache-config.php.
     *
     * @param string $host Raw host value.
     * @return string Normalized host, possibly empty.
     */
    private function normalize_config_key_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = rtrim($host, '.');

        return $this->normalize_config_key_component($host);
    }

    /**
     * Get site information for debugging and logging
     *
     * @return array
     */
    public function get_site_info(): array
    {
        $type = $this->get_multisite_type();

        $info = [
            'is_multisite' => self::is_multisite(),
            'type' => $type,
            'config_key' => $this->get_config_key(),
        ];

        switch ($type) {
            case self::TYPE_SUBDOMAIN:
                $info['identifier'] = self::get_subdomain_identifier();
                $info['host'] = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'localhost';
                break;

            case self::TYPE_SUBDIRECTORY:
                $info['identifier'] = self::get_subdirectory_identifier();
                $info['path'] = wp_parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')), PHP_URL_PATH);
                break;

            default:
                $info['identifier'] = 'single';
                break;
        }

        return $info;
    }

    /**
     * Check if current site is the main site of the network
     *
     * @return bool
     */
    public function is_main_site(): bool
    {
        if (!self::is_multisite()) {
            return true;
        }

        return \is_main_site();
    }
}

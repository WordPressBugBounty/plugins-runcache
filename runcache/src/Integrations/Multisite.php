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
     * The identifier derives from the blog only. The removed REQUEST_URI
     * fallback varied per context, so the preload worker missed its queue.
     *
     * @return string
     */
    public static function get_subdirectory_identifier(): string
    {
        if (function_exists('get_current_blog_id') && function_exists('get_blog_details')) {
            $blog_details = get_blog_details(get_current_blog_id());

            if ($blog_details instanceof \WP_Site) {
                $site_path = trim($blog_details->path, '/');

                if (!empty($site_path)) {
                    return sanitize_file_name($site_path);
                }
            }
        }

        return 'main';
    }

    /**
     * Normalize a host to the object cache identifier charset.
     *
     * Maps every character outside [a-z0-9-] to '-', so the identifier
     * matches the drop-in key family shape (example.test -> example-test).
     *
     * @param string $host Raw host value.
     * @return string Normalized identifier, possibly empty.
     */
    public static function normalize_object_cache_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/[^a-z0-9-]+/', '-', $host) ?? '';
        $host = preg_replace('/-+/', '-', $host) ?? '';

        return trim($host, '-');
    }

    /**
     * Build the site-scoped object cache key prefix.
     *
     * Hand-written site-scoped constants must stay intact, so the
     * identifier is appended once and doubled segments collapse.
     *
     * @param string $base_key   Raw base key, e.g. 'runcache:'.
     * @param string $identifier Install identifier, possibly empty.
     * @return string Key prefix, e.g. 'runcache:example-test:'.
     */
    public static function object_cache_key_prefix(string $base_key, string $identifier): string
    {
        $base = rtrim($base_key, ':');
        if ('' === $base) {
            $base = 'runcache';
        }

        if ('' === $identifier) {
            return $base . ':';
        }

        $suffix = ':' . $identifier;
        $suffix_length = strlen($suffix);
        $base_length = strlen($base);
        $identifier_length = strlen($identifier);

        while ($base_length > $identifier_length && substr($base, -$suffix_length) === $suffix) {
            $base = substr($base, 0, -$suffix_length);
            $base_length -= $suffix_length;
        }

        return $base . ':' . $identifier . ':';
    }

    /**
     * Get the install-level identifier for object cache keys.
     *
     * Global groups are network-wide, so one network shares one
     * identifier and the drop-in's blog segment separates the sites.
     *
     * @return string Identifier, possibly empty.
     */
    public static function object_cache_identifier(): string
    {
        $host = '';

        if (self::is_multisite() && function_exists('get_current_site')) {
            $host = (string) get_current_site()->domain;
        } elseif (function_exists('home_url') && function_exists('wp_parse_url')) {
            $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        }

        return self::normalize_object_cache_host($host);
    }

    /**
     * Get the config map key for the current site.
     *
     * Must stay in sync with runcache_get_config_candidates() in
     * includes/runcache-config.php and runcache_object_cache_config_candidates()
     * in includes/object-cache.php. The writer stores the full path, and the
     * readers match it with a longest-first candidate chain.
     *
     * @return string Config key, e.g. 'example.com', 'example.com/shop',
     *                or 'example.com/shop/deep'.
     */
    public function get_config_key(): string
    {
        // The drop-in resolves its key from HTTP_HOST, so this side uses
        // the canonical URL and normalizes to the same charset.
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = $this->normalize_config_key_host(is_string($host) ? $host : '');

        $path = self::config_key_path_for_path((string) $this->get_blog_path());

        if ('' === $path) {
            return $host;
        }

        return $host . '/' . $path;
    }

    /**
     * Build the config map key for one site by domain and registered path.
     *
     * @param string $domain Raw site domain, e.g. 'example.com'.
     * @param string $path   Raw site path, e.g. '/shop/deep/' or '/'.
     * @return string Config key, e.g. 'example.com/shop/deep'.
     */
    public static function config_key_for_site(string $domain, string $path): string
    {
        $instance = new self();

        $host = $instance->normalize_config_key_host($domain);
        if ('' === $host) {
            return '';
        }

        $path_key = self::config_key_path_for_path((string) $path);
        if ('' === $path_key) {
            return $host;
        }

        return $host . '/' . $path_key;
    }

    /**
     * Registered path of the current blog on a subdirectory network.
     *
     * @return string Full path without slashes at the ends, or '' on the main site.
     */
    private function get_blog_path(): string
    {
        if (!function_exists('get_blog_details') || !function_exists('get_current_blog_id')) {
            return '';
        }

        $blog_details = get_blog_details(get_current_blog_id());
        if (!$blog_details instanceof \WP_Site) {
            return '';
        }

        $path = trim((string) $blog_details->path, '/');

        return $path;
    }

    /**
     * Normalize a site path to the config key path part.
     *
     * Each segment normalizes alone, so the '/' separators survive.
     * Must stay in sync with runcache_get_config_candidates() in
     * includes/runcache-config.php.
     *
     * @param string $path Raw path with '/'-separated segments.
     * @return string Normalized path without slashes at the ends, possibly empty.
     */
    private static function config_key_path_for_path(string $path): string
    {
        $instance = new self();

        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ('' === $segment) {
                continue;
            }

            $normalized = $instance->normalize_config_key_component($segment);
            if ('' !== $normalized) {
                $segments[] = $normalized;
            }
        }

        return implode('/', $segments);
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
     * Some clients send FQDN form ('example.com.') or a port (':8080').
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
     * Sanitizes a site path into cache directory segments.
     *
     * Mirrors the file drop-in's segment sanitize, so purge scopes and
     * lifecycle cleanup match the on-disk layout.
     *
     * @param string $path Raw site path with '/'-separated segments.
     * @return array<int, string> Safe segments, possibly empty.
     */
    public static function sanitize_cache_path_segments(string $path): array
    {
        $path = str_replace('\\', '/', trim($path, '/'));

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }

            $segment = preg_replace('/[^a-zA-Z0-9_-]/', '-', $segment) ?? '';
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * Marker-name suffix for one site path.
     *
     * Subdirectory child sites share the host, so their server-level toggle
     * markers carry the site path. One site's provider change then cannot
     * touch a sibling's marker. Segments normalize like cache directory
     * segments, and the whole suffix is lowercase.
     *
     * @param string $path Raw site path, e.g. '/site1/deep/'.
     * @return string '' or '-site1-deep'.
     */
    public static function marker_suffix_for_path(string $path): string
    {
        $segments = self::sanitize_cache_path_segments($path);
        if ($segments === []) {
            return '';
        }

        return '-' . strtolower(implode('-', $segments));
    }

    /**
     * Marker-name suffix for the current site.
     *
     * @return string '' or '-site1-deep'.
     */
    public static function marker_path_suffix(): string
    {
        if (!self::is_multisite() || \is_subdomain_install()
            || !function_exists('get_current_blog_id') || !function_exists('get_blog_details')
        ) {
            return '';
        }

        $blog_details = get_blog_details(get_current_blog_id());
        if (!$blog_details instanceof \WP_Site) {
            return '';
        }

        return self::marker_suffix_for_path((string) $blog_details->path);
    }

    /**
     * Registered child-site paths of a subdirectory network.
     *
     * Each entry carries the raw path for URI matching and the normalized
     * suffix for marker names, so the server templates and the PHP side
     * agree on names.
     *
     * @return array<int, array{regex: string, marker: string}>
     */
    public static function child_site_paths(): array
    {
        if (!self::is_multisite() || \is_subdomain_install() || !function_exists('get_sites')) {
            return [];
        }

        $sites = get_sites(['number' => 500]);
        if (!is_array($sites)) {
            return [];
        }

        $entries = [];
        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $path = trim((string) $site->path, '/');
            if ($path === '') {
                continue;
            }

            $segments = self::sanitize_cache_path_segments($path);
            if ($segments === []) {
                continue;
            }

            $normalized = strtolower(implode('/', $segments));
            if ($normalized === '') {
                continue;
            }

            $entries[$normalized] = [
                // The raw path matches the URI as the site serves it.
                'regex' => preg_quote($path, null),
                'marker' => self::marker_suffix_for_path($path),
            ];
        }

        return array_values($entries);
    }

    /**
     * nginx lines that resolve the per-site marker suffix from the URI.
     *
     * Single-site and subdomain installs yield an empty string, so their
     * generated templates stay unchanged.
     *
     * @return string nginx `if` block lines, possibly empty.
     */
    public static function nginx_marker_site_ifs(): string
    {
        $lines = [];
        foreach (self::child_site_paths() as $entry) {
            $lines[] = 'if ($uri ~* ^/' . $entry['regex'] . '(/|$)) { set $runcache_site_marker "' . $entry['marker'] . '"; }';
        }

        return implode("\n", $lines);
    }

    /**
     * nginx map lines that extend the WooCommerce floor to child-site paths.
     *
     * @return string nginx map lines, possibly empty.
     */
    public static function fastcgi_woo_map_lines(): string
    {
        $lines = [];
        foreach (self::child_site_paths() as $entry) {
            $lines[] = '    ~*^/' . $entry['regex'] . '/(cart|checkout|my-account) 1;';
        }

        return implode("\n", $lines);
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

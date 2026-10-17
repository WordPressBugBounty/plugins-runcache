<?php

namespace RunCache\Core;

use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CDN management and asset rewriting
 */
class Cdn
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings = [
        'enable' => false,
        'cdn_type' => '',
    ];

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
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize CDN functionality
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_get_cdn_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_cdn_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_verify_cdn', [$this, 'ajax_verify_cdn']);
        add_action('runcache_cdn_settings_saved', [$this, 'purge_page_cache_on_settings_change'], 10, 2);

        if ($this->is_enabled()) {
            add_action('init', [$this, 'init_asset_rewriting']);
        }
    }

    /**
     * Get CDN settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $settings = wp_parse_args($this->setting->get('cdn'), $this->default_settings);
        return apply_filters('runcache_cdn_settings', $settings);
    }

    /**
     * Enable CDN
     *
     * @return void
     */
    public function enable(): void
    {
        $settings = $this->get_settings();
        $settings['enable'] = true;
        $settings['cdn_type'] = 'bunnycdn';
        $this->save_settings($settings);
    }

    /**
     * Disable CDN
     *
     * @return void
     */
    public function disable(): void
    {
        $settings = $this->get_settings();
        $settings['enable'] = false;
        $this->save_settings($settings);
    }

    /**
     * Get provider settings
     *
     * @return array
     */
    public function get_provider_settings(): array
    {
        $settings = $this->get_settings();

        if (empty($settings['enable']) || empty($settings['cdn_type'])) {
            return [];
        }

        $cdn_type = $settings['cdn_type'];
        $provider_settings = $settings['provider'][$cdn_type] ?? [];

        if (!is_array($provider_settings)) {
            return [];
        }

        $hostname = $this->normalize_hostname($provider_settings['hostname'] ?? '');
        if (!$this->is_valid_hostname($hostname)) {
            return [];
        }

        $provider_settings['hostname'] = $hostname;

        if (empty($provider_settings['allowed_extensions'])) {
            $provider_settings['allowed_extensions'] = $this->get_default_allowed_extensions();
        }

        return $provider_settings;
    }

    /**
     * Save CDN settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        $existing_settings = $this->get_settings();

        $previous_cdn_type = $existing_settings['cdn_type'];
        $cdn_type = $settings['cdn_type'];

        $result = $this->setting->set('cdn', $settings);

        if ($settings['enable']) {
            if ($previous_cdn_type !== $cdn_type) {
                if (!is_null($previous_cdn_type)) {
                    $previous_provider = $this->get_cdn_provider($previous_cdn_type);
                    if ($previous_provider) {
                        $previous_provider->disable();
                    }
                }

                if (!is_null($cdn_type)) {
                    $new_provider = $this->get_cdn_provider($cdn_type);
                    if ($new_provider) {
                        $new_provider->enable();
                    }
                }
            }
        } else {
            if (!is_null($previous_cdn_type)) {
                $previous_provider = $this->get_cdn_provider($previous_cdn_type);
                if ($previous_provider) {
                    $previous_provider->disable();
                }
            }
        }

        if ($result) {
            do_action('runcache_cdn_settings_saved', $settings, $existing_settings);
        }

        return $result;
    }

    /**
     * Purge page cache when CDN URLs may have changed.
     *
     * @param array $settings Newly saved CDN settings.
     * @param array $previous_settings Previously saved CDN settings.
     * @return void
     */
    public function purge_page_cache_on_settings_change(array $settings, array $previous_settings = []): void
    {
        $enabled_changed = !empty($settings['enable']) !== !empty($previous_settings['enable']);
        $hostname_changed = $this->get_cdn_hostname($settings) !== $this->get_cdn_hostname($previous_settings);

        if (!$enabled_changed && !$hostname_changed) {
            return;
        }

        try {
            if (!(new Purger($this->logger, $this->validator))->purge_all()) {
                $this->logger->warning('Failed to purge page cache after CDN settings change');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to purge page cache after CDN settings change', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the configured CDN hostname for comparison.
     *
     * @param array $settings CDN settings.
     * @return string
     */
    private function get_cdn_hostname(array $settings): string
    {
        $cdn_type = (string) ($settings['cdn_type'] ?? '');
        $provider_settings = $settings['provider'][$cdn_type] ?? [];

        if (!is_array($provider_settings)) {
            return '';
        }

        return $this->normalize_hostname($provider_settings['hostname'] ?? '');
    }

    /**
     * Verify CDN setup
     *
     * @return array
     */
    public function verify_cdn(): array
    {
        $settings = $this->get_settings();

        if (empty($settings['enable']) || empty($settings['cdn_type'])) {
            return [
                'cdn' => [
                    'type' => $this->default_settings['cdn_type'],
                    'verified' => false,
                ],
            ];
        }

        $provider = $this->get_cdn_provider($settings['cdn_type']);
        if (!$provider) {
            return [
                'cdn' => [
                    'type' => $this->default_settings['cdn_type'],
                    'verified' => false,
                ],
            ];
        }

        $verify_result = $provider->verify();
        $verified = is_array($verify_result) ? ($verify_result['verified'] ?? false) : (bool) $verify_result;

        return [
            'cdn' => [
                'type' => $settings['cdn_type'],
                'verified' => $verified,
            ],
        ];
    }

    /**
     * Check if CDN is enabled
     *
     * @return bool
     */
    public function is_enabled(): bool
    {
        $settings = $this->get_settings();

        if (empty($settings['enable']) || empty($settings['cdn_type'])) {
            return false;
        }

        $cdn_type = $settings['cdn_type'];

        if (!isset($settings['provider'][$cdn_type]) || !is_array($settings['provider'][$cdn_type])) {
            return false;
        }

        $provider_settings = $settings['provider'][$cdn_type];

        $hostname = $this->normalize_hostname($provider_settings['hostname'] ?? '');

        return $this->is_valid_hostname($hostname);
    }

    /**
     * Get CDN provider by key
     *
     * @param string $key
     * @return object|null
     */
    public function get_cdn_provider(string $key): ?object
    {
        if (empty($key)) {
            return null;
        }

        $cdn_providers = apply_filters('runcache_cdn_providers', []);

        if (isset($cdn_providers[$key])) {
            return $cdn_providers[$key];
        }

        if (!empty($cdn_providers)) {
            $this->logger->warning('Provider not found', [
                'component' => 'Cdn',
                'key' => $key,
                'available' => array_keys($cdn_providers),
            ]);
        }

        return null;
    }

    /**
     * AJAX handler for getting CDN settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success($this->get_settings());
    }

    /**
     * AJAX handler for saving CDN settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();
        $settings = $this->validator->validate_ajax_settings();
        $result = $this->save_settings($settings);

        if ($result) {
            $message = 'CDN settings saved successfully';
            if (!empty($settings['enable'])) {
                $verification_result = $this->verify_cdn();
                $is_verified = $verification_result['cdn']['verified'];

                if (!$is_verified) {
                    $cdn_type = $settings['cdn_type'] ?? 'bunnycdn';
                    if ($cdn_type === 'bunnycdn') {
                        $message = 'BunnyCDN enabled. Please configure your API credentials.';
                    } elseif ($cdn_type === 'custom') {
                        $message = 'Custom CDN enabled. Please configure your hostname.';
                    }
                } else {
                    $cdn_type = $settings['cdn_type'] ?? 'bunnycdn';
                    $type_names = [
                        'bunnycdn' => 'BunnyCDN',
                        'custom' => 'Custom CDN',
                    ];
                    $message = ($type_names[$cdn_type] ?? ucfirst($cdn_type) . ' CDN') . ' enabled and working';
                }
            } else {
                $message = 'CDN disabled successfully';
            }

            wp_send_json_success([
                'message' => $message,
                'data' => $this->get_settings(),
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to save CDN settings']);
        }
    }

    /**
     * AJAX handler for verifying CDN
     *
     * @return void
     */
    public function ajax_verify_cdn(): void
    {
        $this->validator->validate_ajax_request();
        $result = $this->verify_cdn();

        if (!empty($result['cdn']['verified'])) {
            wp_send_json_success([
                'message' => 'CDN verified successfully',
                'cdn' => $result['cdn'],
            ]);
        } else {
            wp_send_json_error([
                'message' => 'CDN verification failed',
                'cdn' => $result['cdn'],
            ]);
        }
    }

    /**
     * Initialize CDN asset rewriting
     *
     * @return void
     */
    public function init_asset_rewriting(): void
    {
        $priority = 999;

        add_filter('wp_get_attachment_url', [$this, 'rewrite_asset_url'], $priority);
        add_filter('stylesheet_uri', [$this, 'rewrite_asset_url'], $priority);
        add_filter('style_loader_src', [$this, 'rewrite_asset_url'], $priority);
        add_filter('script_loader_src', [$this, 'rewrite_asset_url'], $priority);
        add_filter('content_url', [$this, 'rewrite_content_url'], $priority);
        add_filter('plugins_url', [$this, 'rewrite_content_url'], $priority);
        add_filter('theme_root_uri', [$this, 'rewrite_content_url'], $priority);
        add_filter('includes_url', [$this, 'rewrite_content_url'], $priority);
        add_filter('the_content', [$this, 'rewrite_content_assets'], $priority);
        add_filter('post_thumbnail_html', [$this, 'rewrite_content_assets'], $priority);
        add_filter('embed_oembed_html', [$this, 'rewrite_content_assets'], $priority);
        add_filter('wp_video_shortcode', [$this, 'rewrite_content_assets'], $priority);
        add_filter('wp_audio_shortcode', [$this, 'rewrite_content_assets'], $priority);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_srcset_urls'], $priority, 5);
        add_filter('wp_get_attachment_image_attributes', [$this, 'rewrite_image_attributes'], $priority, 3);
    }

    /**
     * Rewrite asset URL to CDN hostname
     *
     * @param string $url
     * @return string
     */
    public function rewrite_asset_url($url)
    {
        if (empty($url)) {
            return $url;
        }

        if (!$this->is_local_url($url)) {
            return $url;
        }

        if (is_admin() || $this->is_admin_asset($url)) {
            return $url;
        }

        if (!$this->is_enabled()) {
            return $url;
        }

        $settings = $this->get_provider_settings();

        if (empty($settings) || empty($settings['hostname'])) {
            return $url;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return $url;
        }

        $is_wp_content = strpos($path, '/wp-content/') !== false;
        $allowed_extensions = $settings['allowed_extensions'] ?? null;

        if (!$is_wp_content && !$this->is_extension_allowed($url, $allowed_extensions)) {
            return $url;
        }

        $parsed = wp_parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        $path_only = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        $cdn_base = ($scheme !== '' ? $scheme . '://' : '//') . rtrim($settings['hostname'], '/');

        // The returned URL is rendered into HTML attributes (src, href, srcset).
        // Escape it to neutralize any unexpected characters regardless of source.
        return esc_url($cdn_base . $path_only . $query . $fragment);
    }

    /**
     * Rewrite content URL outputs
     *
     * @param string $url
     * @param string $path
     * @return string
     */
    public function rewrite_content_url($url, $path = '')
    {
        // Delegate to rewrite_asset_url(), which applies esc_url() to the result.
        return $this->rewrite_asset_url($url);
    }

    /**
     * Rewrite asset URLs in content
     *
     * This method returns full HTML content, not a single URL. Do not wrap the
     * whole return in esc_url(). esc_url() is for a single URL and would corrupt
     * valid HTML by double-encoding quotes and ampersands.
     *
     * The only inserted value is the CDN hostname. It is sanitized at output time
     * by sanitize_cdn_host(), which allows only letters, digits, hyphens and dots.
     * normalize_hostname() extracts just the host part and is_valid_hostname()
     * validates it with FILTER_VALIDATE_DOMAIN before this runs, so the returned
     * HTML can never receive markup or quote characters from the hostname.
     *
     * @param string $content
     * @return string
     */
    public function rewrite_content_assets($content)
    {
        if (empty($content)) {
            return $content;
        }

        if (is_admin() || !$this->is_enabled()) {
            return $content;
        }

        $settings = $this->get_provider_settings();

        if (empty($settings) || empty($settings['hostname'])) {
            return $content;
        }

        $cdn_host_clean = $this->sanitize_cdn_host($settings['hostname']);
        $domains = $this->get_local_domains();
        $allowed_extensions = $settings['allowed_extensions'] ?? $this->get_default_allowed_extensions();

        if (empty($allowed_extensions)) {
            return $content;
        }

        $extensions_pattern = $this->build_extensions_pattern($allowed_extensions);

        foreach ($domains as $site_domain) {
            $pattern = '/((?:https?:)?\/\/)' . preg_quote($site_domain, '/') . '(\/(?:[^"\'\\s]*\/)?wp-content\/[^"\'\\s]*\.(?:' . $extensions_pattern . '))(\\?[^"\'\\s]*)?/i';
            $content = preg_replace_callback($pattern, function ($matches) use ($cdn_host_clean) {
                $scheme_prefix = $matches[1] ?? '//';
                $path = $matches[2] ?? '';
                $query = $matches[3] ?? '';
                return $scheme_prefix . $cdn_host_clean . $path . $query;
            }, $content);
        }

        return $content;
    }

    /**
     * Rewrite URLs in srcset arrays
     *
     * @param array $sources
     * @param array $size_array
     * @param string $image_src
     * @param array $image_meta
     * @param int $attachment_id
     * @return array
     */
    public function rewrite_srcset_urls($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if (empty($sources)) {
            return $sources;
        }

        if (!$this->is_enabled()) {
            return $sources;
        }

        $settings = $this->get_provider_settings();

        if (empty($settings) || empty($settings['hostname'])) {
            return $sources;
        }

        $site_domain = wp_parse_url(site_url(), PHP_URL_HOST);
        if (empty($site_domain)) {
            return $sources;
        }

        $cdn_host_clean = $this->sanitize_cdn_host($settings['hostname']);

        foreach ($sources as $width => &$source) {
            $original_url = $source['url'];

            if ($this->is_local_url($original_url) && $this->is_extension_allowed($original_url, $settings['allowed_extensions'] ?? null)) {
                $parsed = wp_parse_url($original_url);
                $scheme = $parsed['scheme'] ?? '';
                $path_only = $parsed['path'] ?? '';
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

                $cdn_base = ($scheme !== '' ? $scheme . '://' : '//') . $cdn_host_clean;
                $source['url'] = $cdn_base . $path_only . $query . $fragment;
            }
        }

        return $sources;
    }

    /**
     * Rewrite URLs in image attributes
     *
     * @param array $attr
     * @param mixed $attachment
     * @param mixed $size
     * @return array
     */
    public function rewrite_image_attributes($attr, $attachment, $size)
    {
        if (!isset($attr['srcset']) || empty($attr['srcset'])) {
            return $attr;
        }

        if (!$this->is_enabled()) {
            return $attr;
        }

        $settings = $this->get_provider_settings();

        if (empty($settings) || empty($settings['hostname'])) {
            return $attr;
        }

        $cdn_host_clean = $this->sanitize_cdn_host($settings['hostname']);
        $domains = $this->get_local_domains();
        $allowed_extensions = $settings['allowed_extensions'] ?? $this->get_default_allowed_extensions();

        if (empty($allowed_extensions)) {
            return $attr;
        }

        $extensions = $this->build_extensions_pattern($allowed_extensions);

        foreach ($domains as $site_domain) {
            $pattern = '/((?:https?:)?\/\/)' . preg_quote($site_domain, '/') . '(\/[^"\'\s]*\.(?:' . $extensions . '))/i';
            $attr['srcset'] = preg_replace_callback($pattern, function ($matches) use ($cdn_host_clean) {
                $scheme_prefix = $matches[1] ?? '//';
                $path = $matches[2] ?? '';
                return $scheme_prefix . $cdn_host_clean . $path;
            }, $attr['srcset']);
        }

        return $attr;
    }

    /**
     * Get local site domains
     *
     * @return array
     */
    private function get_local_domains(): array
    {
        $hosts = [
            untrailingslashit(site_url()),
            untrailingslashit(home_url()),
        ];

        $domains = array_map(static function ($url) {
            return str_replace(['https://', 'http://'], '', $url);
        }, $hosts);

        return array_filter(array_unique($domains));
    }

    /**
     * Check if URL is local
     *
     * @param string $url
     * @return bool
     */
    private function is_local_url(string $url): bool
    {
        $site_hosts = [
            wp_parse_url(site_url(), PHP_URL_HOST),
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url('', 'https'), PHP_URL_HOST),
            wp_parse_url(site_url('', 'http'), PHP_URL_HOST),
            wp_parse_url(home_url('', 'https'), PHP_URL_HOST),
            wp_parse_url(home_url('', 'http'), PHP_URL_HOST),
        ];
        $site_hosts = array_filter(array_unique($site_hosts));
        $url_host = wp_parse_url($url, PHP_URL_HOST);

        if (empty($url_host)) {
            return true;
        }

        return in_array($url_host, $site_hosts, true);
    }

    /**
     * Check if URL is an admin asset
     *
     * @param string $url
     * @return bool
     */
    private function is_admin_asset(string $url): bool
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return false;
        }

        $admin_paths = [
            '/wp-admin/',
            '/wp-includes/js/',
            '/wp-includes/css/',
        ];

        foreach ($admin_paths as $admin_path) {
            if (strpos($path, $admin_path) !== false) {
                return true;
            }
        }

        if (strpos($path, '/runcache/') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Check if file extension is allowed
     *
     * @param string $url
     * @param array|null $allowed_extensions
     * @return bool
     */
    private function is_extension_allowed(string $url, ?array $allowed_extensions = null): bool
    {
        if (!$allowed_extensions) {
            $allowed_extensions = $this->get_default_allowed_extensions();
        }

        if (in_array('*', $allowed_extensions, true)) {
            return true;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, array_map('strtolower', $allowed_extensions), true);
    }

    /**
     * Get default allowed extensions
     *
     * @return array
     */
    private function get_default_allowed_extensions(): array
    {
        return [
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
            'eot',
        ];
    }

    /**
     * Build regex-friendly extensions pattern
     *
     * @param array $allowed_extensions
     * @return string
     */
    private function build_extensions_pattern(array $allowed_extensions): string
    {
        if (in_array('*', $allowed_extensions, true)) {
            return '[^\"\'\\s]+';
        }

        return implode('|', array_map('preg_quote', $allowed_extensions));
    }

    /**
     * Normalize hostname
     *
     * @param mixed $hostname
     * @return string
     */
    private function normalize_hostname($hostname): string
    {
        if (!is_string($hostname)) {
            return '';
        }

        $hostname = trim($hostname);
        if ($hostname === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $hostname)) {
            $hostname = 'https://' . $hostname;
        }

        $parts = wp_parse_url($hostname);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return strtolower($parts['host']);
    }

    /**
     * Validate hostname
     *
     * @param string $hostname
     * @return bool
     */
    private function is_valid_hostname(string $hostname): bool
    {
        if ($hostname === '') {
            return false;
        }

        return (bool) filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    /**
     * Sanitize CDN host to strict hostname characters only.
     *
     * This is applied at output time (escape while creating the string) so that
     * the value inserted into rendered HTML can never carry markup or quote
     * characters. Only letters, digits, hyphens and dots are permitted.
     *
     * @param string $hostname
     * @return string
     */
    private function sanitize_cdn_host(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));

        if (preg_match('/^[a-z0-9.-]+$/', $hostname)) {
            return rtrim($hostname, '.');
        }

        return '';
    }
}

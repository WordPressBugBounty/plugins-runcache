<?php

/**
 * Vite Assets Handler
 *
 * Handles dev/prod asset logic for the RunCache WordPress plugin.
 *
 * @package RunCache\Support
 */

namespace RunCache\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ViteAssets
 *
 * Manages Vite development server integration and production asset loading.
 *
 * @package RunCache\Support
 */
class ViteAssets
{
    /**
     * Vite development server URL (null if not in dev mode)
     *
     * @var string|null
     */
    private ?string $vite_url = null;

    /**
     * Plugin URL
     *
     * @var string
     */
    private string $plugin_url;

    /**
     * Plugin directory path
     *
     * @var string
     */
    private string $plugin_dir;

    /**
     * Constructor
     *
     * @param string $plugin_url Plugin URL.
     * @param string $plugin_dir Plugin directory path.
     */
    public function __construct(string $plugin_url, string $plugin_dir)
    {
        $this->plugin_url = rtrim($plugin_url, '/');
        $this->plugin_dir = rtrim($plugin_dir, '/');
        $this->vite_url = $this->detectViteDevUrl();
    }

    /**
     * Check if running in development mode
     *
     * @return bool True if Vite dev server is active.
     */
    public function isDevMode(): bool
    {
        return $this->vite_url !== null;
    }

    /**
     * Detect Vite development server URL
     *
     * Checks NODE_ENV to determine if dev mode should be enabled.
     *
     * @return string|null Vite URL if available, null otherwise.
     */
    private function detectViteDevUrl(): ?string
    {
        $node_env = getenv('NODE_ENV');

        if ($node_env === 'development') {
            return 'http://localhost:5174';
        }

        return null;
    }

    /**
     * Enqueue admin assets
     *
     * Handles both development (Vite) and production asset loading.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueueAdminAssets(string $hook): void
    {
        if (!str_contains($hook, 'runcache')) {
            return;
        }

        // Core admin scripts print http:// URLs when is_ssl() misses the proxy.
        // Blocked scripts remove window.wp, and every app action needs it.
        add_filter('script_loader_src', [$this, 'upgradeSameHostScheme']);
        add_filter('style_loader_src', [$this, 'upgradeSameHostScheme']);
        $this->upgradeAssetBaseUrl();

        if ($this->isDevMode()) {
            $this->enqueueDevAssets();
        } else {
            $this->enqueueProdAssets();
        }

        $this->localizeAjaxData();
    }

    /**
     * Enqueue development assets from Vite server
     *
     * @return void
     */
    private function enqueueDevAssets(): void
    {
        $vite_url = $this->vite_url;
        $version = RUNCACHE_VERSION;

        // Vite HMR client
        wp_enqueue_script(
            'vite-client',
            $vite_url . '/@vite/client',
            [],
            $version,
            false
        );

        // Main app loads from Vite
        wp_enqueue_script(
            'runcache-admin',
            $vite_url . '/resources/js/main.js',
            [],
            $version,
            true
        );

        // Add type="module" to scripts
        add_filter('script_loader_tag', [$this, 'addModuleType'], 10, 2);

        // Set base URL for Vite dev server
        wp_add_inline_script(
            'runcache-admin',
            'window.runcacheBaseUrl = ' . json_encode($vite_url . '/') . ';',
            'before'
        );

        // Dev mode indicator
        wp_add_inline_script(
            'runcache-admin',
            'console.log("🔥 RunCache: Development mode - Using Vite dev server at ' . esc_js($vite_url) . '");',
            'before'
        );
    }

    /**
     * Enqueue production assets
     *
     * @return void
     */
    private function enqueueProdAssets(): void
    {
        $js_file = $this->plugin_dir . '/assets/js/main.js';
        $css_file = $this->plugin_dir . '/assets/css/main.css';

        if (!file_exists($js_file) || !file_exists($css_file)) {
            wp_add_inline_script(
                'jquery',
                'console.warn("⚠️ RunCache: Built assets not found. Run \'npm run build\' to generate production assets.");',
                'after'
            );
            return;
        }

        $js_version = RUNCACHE_VERSION . '.' . md5_file($js_file);
        $css_version = RUNCACHE_VERSION . '.' . md5_file($css_file);

        // Asset URLs follow the visitor scheme to prevent mixed content.
        $asset_url = set_url_scheme($this->plugin_url, $this->request_scheme());

        wp_enqueue_script(
            'runcache-admin',
            $asset_url . '/assets/js/main.js',
            [],
            $js_version,
            true
        );

        // Add type="module" for ES modules
        add_filter('script_loader_tag', [$this, 'addModuleType'], 10, 2);

        wp_enqueue_style(
            'runcache-admin',
            $asset_url . '/assets/css/main.css',
            [],
            $css_version
        );

        wp_add_inline_script(
            'runcache-admin',
            'window.runcacheBaseUrl = ' . json_encode($asset_url . '/assets/') . ';',
            'before'
        );

        wp_add_inline_script(
            'runcache-admin',
            'console.log("📦 RunCache: Production mode - Using built assets");',
            'before'
        );
    }

    /**
     * Localize AJAX data for admin scripts
     *
     * @return void
     */
    private function localizeAjaxData(): void
    {
        // The AJAX URL follows the visitor scheme to prevent mixed content.
        $scheme = $this->request_scheme();

        wp_localize_script('runcache-admin', 'runcacheAjax', [
            'ajaxurl' => set_url_scheme(admin_url('admin-ajax.php'), $scheme),
            'nonce' => wp_create_nonce('runcache_nonce'),
            'siteUrl' => set_url_scheme(home_url('/'), $scheme),
            'strings' => [
                'loading' => __('Loading...', 'runcache'),
                'error' => __('An error occurred', 'runcache'),
                'success' => __('Success!', 'runcache'),
                'preload_started' => __('Background preload started!', 'runcache'),
            ]
        ]);

        // jQuery cleanup
        wp_add_inline_script(
            'runcache-admin',
            '
            if (typeof jQuery !== "undefined") {
                jQuery.noConflict();
            }

            if (window.JQMIGRATE) {
                window.JQMIGRATE.warnings = [];
            }
            ',
            'before'
        );
    }

    /**
     * Get the scheme the visitor used to reach this page.
     * Reads X-Forwarded-Proto and CF-Visitor because is_ssl() misses proxies.
     *
     * @return string 'https' or 'http'
     */
    private function request_scheme(): string
    {
        if (is_ssl()) {
            return 'https';
        }

        $forwarded_proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_PROTO']))
            : '';
        if ($forwarded_proto !== '') {
            // The client scheme comes first in the hop list.
            $first_hop = strtolower(trim(explode(',', $forwarded_proto)[0]));

            if ($first_hop === 'https') {
                return 'https';
            }
        }

        $cf_visitor = isset($_SERVER['HTTP_CF_VISITOR'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_VISITOR']))
            : '';
        if (stripos($cf_visitor, '"https"') !== false) {
            return 'https';
        }

        return 'http';
    }

    /**
     * Upgrade the script and style base URL to the visitor scheme.
     *
     * Concatenated load-scripts.php and load-styles.php URLs come from
     * base_url, so they bypass the src filters.
     *
     * @return void
     */
    private function upgradeAssetBaseUrl(): void
    {
        if ($this->request_scheme() !== 'https') {
            return;
        }

        foreach ([wp_scripts(), wp_styles()] as $dependency_collection) {
            if (isset($dependency_collection->base_url)
                && is_string($dependency_collection->base_url)
                && str_starts_with($dependency_collection->base_url, 'http://')
            ) {
                $dependency_collection->base_url = set_url_scheme($dependency_collection->base_url, 'https');
            }
        }
    }

    /**
     * Upgrade a same-host http asset URL to the visitor scheme.
     *
     * Filters script_loader_src and style_loader_src. Third-party URLs and
     * unknown hosts stay untouched.
     *
     * @param mixed $src Asset URL from the enqueue system.
     * @return string Asset URL for the script or style tag.
     */
    public function upgradeSameHostScheme($src): string
    {
        if (!is_string($src) || $src === '' || $this->request_scheme() !== 'https') {
            return is_string($src) ? $src : '';
        }

        if (!str_starts_with($src, 'http://')) {
            return $src;
        }

        $url_host = wp_parse_url($src, PHP_URL_HOST);
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (!is_string($url_host) || !is_string($site_host)) {
            return $src;
        }

        if (strtolower($url_host) !== strtolower($site_host)) {
            return $src;
        }

        return set_url_scheme($src, 'https');
    }

    /**
     * Add type="module" to script tags
     *
     * @param string $tag    Script tag HTML.
     * @param string $handle Script handle.
     * @return string Modified script tag.
     */
    public function addModuleType($tag, $handle): string
    {
        if ($handle === 'vite-client' || $handle === 'runcache-admin') {
            return str_replace('<script ', '<script type="module" ', $tag);
        }

        return $tag;
    }
}

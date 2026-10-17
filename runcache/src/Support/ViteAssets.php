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

        wp_enqueue_script(
            'runcache-admin',
            $this->plugin_url . '/assets/js/main.js',
            [],
            $js_version,
            true
        );

        // Add type="module" for ES modules
        add_filter('script_loader_tag', [$this, 'addModuleType'], 10, 2);

        wp_enqueue_style(
            'runcache-admin',
            $this->plugin_url . '/assets/css/main.css',
            [],
            $css_version
        );

        wp_add_inline_script(
            'runcache-admin',
            'window.runcacheBaseUrl = ' . json_encode($this->plugin_url . '/assets/') . ';',
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
        wp_localize_script('runcache-admin', 'runcacheAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('runcache_nonce'),
            'siteUrl' => home_url('/'),
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

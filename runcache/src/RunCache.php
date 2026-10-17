<?php

namespace RunCache;

use RunCache\Core\PageCache;
use RunCache\Core\Preloader;
use RunCache\Core\Purger;
use RunCache\Core\Setting;
use RunCache\Support\Dropin;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;
use RunCache\Support\ViteAssets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for RunCache
 */
class RunCache
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private ViteAssets $vite_assets;

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
        $this->vite_assets = new ViteAssets(
            (string)RUNCACHE_PLUGIN_URL,
            (string)RUNCACHE_PLUGIN_DIR
        );
    }

    /**
     * Get list of module classes to initialize
     *
     * @return array<string, string[]>
     */
    public function registered_modules(): array
    {
        return [
            'core' => [
                Core\PageCache::class,
                Core\Stats::class,
                Core\Purger::class,
                Core\Preloader::class,
                Core\Rules::class,
                Core\Setting::class,
                Core\SettingsTransfer::class,
                Core\Cdn::class,
                 Core\Edge::class,
                 Core\ObjectCache::class,

            ],
            'cli' => [
                Cli\RunCache::class,
            ],
            'support' => [
                Support\Server::class,
            ],
            'integrations' => [
                Integrations\PHPCache::class,
                Integrations\Nginx::class,
                Integrations\FastCGI::class,
                Integrations\Apache::class,
                Integrations\LiteSpeed::class,
                Integrations\Redis::class,
                Integrations\RedisObjectCache::class,
                Integrations\Cloudflare::class,
                Integrations\CloudflareApo::class,
                Integrations\BunnyCDN::class,
                Integrations\CustomCDN::class,
                Integrations\Multisite::class,
                Integrations\WooCommerce::class,
            ],
        ];
    }

    /**
     * Initialize plugin
     *
     * @return void
     */
    public function init(): void
    {
        $this->load_modules();

        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 100);
        add_action('upgrader_process_complete', [Dropin::class, 'refresh_on_update'], 10, 2);

        // Admin-post handlers
        add_action('admin_post_runcache_preload_all', [$this, 'handle_preload_all']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_action('admin_notices', [$this, 'display_admin_notices']);
            add_filter('plugin_action_links_' . RUNCACHE_PLUGIN_BASENAME, [$this, 'add_plugin_action_links']);
        }
    }

    /**
     * Load all registered modules
     *
     * @return void
     */
    private function load_modules(): void
    {
        $modules = $this->registered_modules();

        // Core modules: logger + validator + setting
        foreach ($modules['core'] as $class) {
            (new $class($this->logger, $this->validator, $this->setting))->init();
        }

        // CLI modules: logger + setting
        foreach ($modules['cli'] as $class) {
            (new $class($this->logger, $this->setting))->init();
        }

        // Support modules: logger only
        foreach ($modules['support'] as $class) {
            (new $class($this->logger))->init();
        }

        // Integration modules: logger + setting
        foreach ($modules['integrations'] as $class) {
            (new $class($this->logger, $this->setting))->init();
        }
    }

    /**
     * Add admin menu
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        $icon_path = RUNCACHE_PLUGIN_DIR . 'assets/images/runcache-icon.svg';
        $icon_svg = file_exists($icon_path)
            ? 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($icon_path))
            : 'dashicons-performance';

        add_menu_page(
            __('RunCache', 'runcache'),
            __('RunCache', 'runcache'),
            'manage_options',
            'runcache',
            [$this, 'render_admin_page'],
            $icon_svg,
            30
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void
    {
        if (!str_contains($hook, 'runcache')) {
            return;
        }

        $this->vite_assets->enqueueAdminAssets($hook);

        wp_register_style('runcache-admin-wrapper', false, [], RUNCACHE_VERSION);
        wp_enqueue_style('runcache-admin-wrapper');

        wp_add_inline_style(
            'runcache-admin-wrapper',
            '.runcache-loading {' .
            'text-align: center;' .
            'padding: 40px;' .
            'background: #fff;' .
            'border: 1px solid #ccd0d4;' .
            'border-radius: 4px;' .
            '}' .
            '#runcache-admin-wrapper {' .
            'all: initial;' .
            'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;' .
            '}' .
            '#runcache-admin-wrapper * {' .
            'box-sizing: border-box;' .
            '}'
        );

        wp_add_inline_script(
            'runcache-admin',
            '(function() {' .
            'var originalJQuery = window.jQuery;' .
            'window.runcacheCleanup = function() {' .
            'if (originalJQuery) {' .
            'window.jQuery = originalJQuery;' .
            '}' .
            '};' .
            'var originalConsoleError = console.error;' .
            'console.error = function() {' .
            'var args = Array.prototype.slice.call(arguments);' .
            'var message = args.join(" ");' .
            'if (message.indexOf("Cannot read properties of undefined") !== -1 &&' .
            'message.indexOf("init") !== -1 &&' .
            'message.indexOf("load-scripts") !== -1) {' .
            'return;' .
            '}' .
            'originalConsoleError.apply(console, arguments);' .
            '};' .
            '})();',
            'before'
        );
    }

    /**
     * Render admin page
     *
     * @return void
     */
    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'runcache'));
        }

        ?>
        <div class="wrap">
            <div id="runcache-admin-wrapper" style="position: relative; z-index: 1;">
                <div id="runcache-admin-app">
                    <div class="runcache-loading">
                        <p><?php esc_html_e('Loading RunCache admin interface...', 'runcache'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display admin notices
     *
     * @return void
     */
    public function display_admin_notices(): void
    {
        $cache_dir = (new PageCache())->get_cache_directory();
        $file = new File();

        if (!$file->is_writable($cache_dir)) {
            ?>
            <div class="notice notice-error">
                <p>
                    <?php
                    printf(
                        /* translators: %s: cache directory path */
                        esc_html__('RunCache: Cache directory is not writable. Please make sure %s is writable by the web server.', 'runcache'),
                        '<code>' . esc_html($cache_dir) . '</code>'
                    );
            ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Add plugin action links
     *
     * @param array $links
     * @return array
     */
    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=runcache'),
            __('Settings', 'runcache')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Add admin bar menu
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     * @return void
     */
    public function add_admin_bar_menu(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $cache_enabled = $this->is_cache_enabled();

        $wp_admin_bar->add_node([
            'id' => 'runcache',
            'title' => __('RunCache', 'runcache'),
            'href' => admin_url('admin.php?page=runcache'),
            'meta' => [
                'title' => $cache_enabled ? __('Cache Management', 'runcache') : __('Cache Disabled', 'runcache')
            ]
        ]);

        if ($cache_enabled) {
            $current_url = $this->get_current_url_for_purge();
            $wp_admin_bar->add_node([
                'id' => 'runcache-purge-current',
                'parent' => 'runcache',
                'title' => __('Purge this page', 'runcache'),
                'href' => wp_nonce_url(
                    admin_url('admin-post.php?action=runcache_purge_by_url&url=' . urlencode($current_url)),
                    'runcache_purge_by_url'
                ),
                'meta' => [
                    'title' => __('Purge cache for current page/post', 'runcache')
                ]
            ]);
        }

        if ($cache_enabled) {
            $wp_admin_bar->add_node([
                'id' => 'runcache-purge-all',
                'parent' => 'runcache',
                'title' => __('Purge All', 'runcache'),
                'href' => wp_nonce_url(admin_url('admin-post.php?action=runcache_purge_all'), 'runcache_purge_all'),
                'meta' => [
                    'title' => __('Purge entire cache', 'runcache')
                ]
            ]);
        }

        if ($cache_enabled) {
            $wp_admin_bar->add_node([
                'id' => 'runcache-preload-all',
                'parent' => 'runcache',
                'title' => __('Preload All', 'runcache'),
                'href' => wp_nonce_url(admin_url('admin-post.php?action=runcache_preload_all'), 'runcache_preload_all'),
                'meta' => [
                    'title' => __('Start background cache preloading', 'runcache')
                ]
            ]);
        }

        $wp_admin_bar->add_node([
            'id' => 'runcache-settings',
            'parent' => 'runcache',
            'title' => __('Settings', 'runcache'),
            'href' => admin_url('admin.php?page=runcache'),
            'meta' => [
                'title' => __('RunCache Settings', 'runcache')
            ]
        ]);
    }

    /**
     * Handle quick purge from admin bar (legacy)
     *
     * @return void
     */
    public function handle_quick_purge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'runcache'));
        }

        check_admin_referer('runcache_purge');

        try {
            (new Purger())->purge_all();
            add_settings_error(
                'runcache',
                'runcache_purged',
                __('Cache purged successfully.', 'runcache'),
                'success'
            );
        } catch (\Exception $e) {
            add_settings_error(
                'runcache',
                'runcache_purge_failed',
                /* translators: %s: Error message */
                sprintf(__('Failed to purge cache: %s', 'runcache'), $e->getMessage()),
                'error'
            );
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    /**
     * Handle preload action from admin bar
     *
     * @return void
     */
    public function handle_preload_all(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'runcache'));
        }

        check_admin_referer('runcache_preload_all');

        try {
            $preloader = new Preloader();
            $preloader->start_preload();

            add_settings_error(
                'runcache',
                'runcache_preload_started',
                esc_html__('Preload started successfully. URLs are being preloaded in the background.', 'runcache'),
                'success'
            );
        } catch (\Exception $e) {
            add_settings_error(
                'runcache',
                'runcache_preload_failed',
                /* translators: %s: Error message */
                sprintf(__('Failed to start preload: %s', 'runcache'), $e->getMessage()),
                'error'
            );
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    /**
     * Check if cache is enabled
     *
     * Reads the raw page_cache option only, mirroring FastCGI::init().
     * The enriched getter would compute server_type on every page render.
     *
     * @return bool
     */
    private function is_cache_enabled(): bool
    {
        try {
            $settings = $this->setting->get('page_cache');

            return is_array($settings) && !empty($settings['enable']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get current URL for purging
     *
     * @return string
     */
    private function get_current_url_for_purge(): string
    {
        if (is_admin()) {
            $url = wp_get_referer();
            if (empty($url)) {
                $url = home_url('/');
            }
        } else {
            $url = home_url(add_query_arg(null, null));
        }

        return $this->validator->sanitize_url($url) ?: home_url('/');
    }

    /**
     * Plugin activation
     *
     * @return void
     */
    public static function activate(): void
    {
        $cache = new PageCache();

        // Determine whether this is a first install (no stored option yet) or
        // a reactivation (e.g. after a plugin update). On first install we
        // seed the default settings; on reactivation we preserve the existing
        // cache type and only re-enable the cache.
        $raw_settings = get_option('runcache_page_cache_settings');

        if ($raw_settings === false) {
            // First activation: set defaults (PHP is the universal safe default)
            $cache->save_settings([
                'enable' => true,
                'page_cache_type' => 'php',
            ]);
        } else {
            // Reactivation: preserve existing page_cache_type, just re-enable
            $cache->save_settings([
                'enable' => true,
            ]);
        }

        // Ensure infrastructure is in place (idempotent)
        $cache->ensure_cache_directory();
        $cache->enable_wp_cache();

        $dropin = new Dropin();
        $dropin->install_page_cache_dropin();

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     *
     * @return void
     */
    public static function deactivate(): void
    {
        // Remove the recurring filesystem cleanup before the plugin is disabled.
        wp_clear_scheduled_hook('runcache_scheduled_cleanup');

        // Purge cache FIRST (while plugin is still active and hooks are loaded)
        try {
            (new Purger())->purge_all();
        } catch (\Exception $e) {
            // Log but don't fail deactivation
            $logger = new \RunCache\Support\Logger();
            $logger->error('Failed to purge cache during deactivation', [
                'error' => $e->getMessage(),
            ]);
        }

        // Disable cache but PRESERVE the existing cache type so that
        // reactivation restores the same provider instead of resetting to PHP.
        $cache = new PageCache();
        $cache->save_settings([
            'enable' => false,
        ]);

        // Disable the active CDN edge provider.
        try {
            $edge = new \RunCache\Core\Edge();
            $edge_settings = $edge->get_settings();
            if (!empty($edge_settings['enable']) && !empty($edge_settings['edge_type'])) {
                $provider = match ($edge_settings['edge_type']) {
                    \RunCache\Core\Edge::CLOUDFLARE => new \RunCache\Integrations\Cloudflare(),
                    \RunCache\Core\Edge::CLOUDFLARE_APO => new \RunCache\Integrations\CloudflareApo(),
                    default => null,
                };
                if ($provider !== null) {
                    $provider->disable();
                }
                $edge->save_settings(['enable' => false]);
            }
        } catch (\Throwable $e) {
            $logger = new \RunCache\Support\Logger();
            $logger->error('Failed to disable Cloudflare APO during deactivation', [
                'error' => $e->getMessage(),
            ]);
        }

        // Disable WP_CACHE constant in wp-config.php
        $cache->disable_wp_cache();

        // Remove page cache drop-in
        $dropin = new Dropin();
        $dropin->remove_page_cache_dropin();

        // Remove object cache drop-in and strip RUNCACHE_REDIS_* from wp-config.php
        // (constants are only stripped if no other cache is still using Redis
        try {
            (new \RunCache\Integrations\RedisObjectCache())->disable();
        } catch (\Throwable $e) {
            $logger = new \RunCache\Support\Logger();
            $logger->error('Failed to clean up object cache during deactivation', [
                'error' => $e->getMessage(),
            ]);
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall
     *
     * @return void
     */
    public static function uninstall(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        $logger = new \RunCache\Support\Logger();

        // 1. strip RUNCACHE_REDIS_* constants from wp-config.php.
        try {
            (new \RunCache\Integrations\Redis($logger))->cleanup_wp_config();
        } catch (\Throwable $e) {
            $logger->error('uninstall: wp-config.php cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Disable the active cache provider (removes Cloudflare rules / APO toggle).
        // We cannot rely on the runcache_cache_providers filter here because the
        // integration init() hooks do not run during uninstall.
        try {
            $raw_settings = get_option('runcache_page_cache_settings', []);
            $provider_key = is_array($raw_settings) && isset($raw_settings['page_cache_type'])
                ? $raw_settings['page_cache_type']
                : '';

            $provider_class = match ($provider_key) {
                'cloudflare' => \RunCache\Integrations\Cloudflare::class,
                default => null,
            };

            if ($provider_class !== null) {
                (new $provider_class())->disable();
            }

            // Disable the active CDN edge provider from the new edge settings.
            $edge_settings = get_option('runcache_edge_settings', []);
            $edge_type = is_array($edge_settings) ? ($edge_settings['edge_type'] ?? '') : '';
            if (is_array($edge_settings) && !empty($edge_settings['enable']) && $edge_type !== '') {
                $provider = match ($edge_type) {
                    \RunCache\Core\Edge::CLOUDFLARE => new \RunCache\Integrations\Cloudflare(),
                    \RunCache\Core\Edge::CLOUDFLARE_APO => new \RunCache\Integrations\CloudflareApo(),
                    default => null,
                };
                if ($provider !== null) {
                    $provider->disable();
                }
                $edge_settings['enable'] = false;
                $edge_settings['edge_type'] = '';
                update_option('runcache_edge_settings', $edge_settings, true);
            }
        } catch (\Throwable $e) {
            $logger->error('uninstall: provider disable failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Delete all settings from database
        try {
            (new Setting())->delete_all();
        } catch (\Throwable $e) {
            $logger->error('uninstall: settings deletion failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 4. Delete cache directory (removes any remaining files including toggle markers)
        try {
            (new File())->delete(RUNCACHE_CACHE_DIRECTORY, true);
        } catch (\Throwable $e) {
            $logger->error('uninstall: cache directory deletion failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // 5. Delete the shared config file and its lock file. Normal operation
        // never unlinks the lock file. Uninstall is the only deletion point.
        try {
            $file = new File();
            $lock_file = $file->lock_path_for(RUNCACHE_CONFIG_FILE);

            $file->delete(RUNCACHE_CONFIG_FILE);
            $file->delete($lock_file);
        } catch (\Throwable $e) {
            $logger->error('uninstall: config file deletion failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register lifecycle hooks
     *
     * @return void
     */
    public static function register_initial_hooks(): void
    {
        register_activation_hook(RUNCACHE_PLUGIN_FILE, [__CLASS__, 'activate']);
        register_deactivation_hook(RUNCACHE_PLUGIN_FILE, [__CLASS__, 'deactivate']);
        register_uninstall_hook(RUNCACHE_PLUGIN_FILE, [__CLASS__, 'uninstall']);
    }
}

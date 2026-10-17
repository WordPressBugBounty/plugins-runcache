<?php

namespace RunCache\Cli;

use Exception;
use RunCache\Core\Cdn;
use RunCache\Core\ObjectCache;
use RunCache\Core\PageCache;
use RunCache\Core\Preloader;
use RunCache\Core\Purger;
use RunCache\Core\Setting;
use RunCache\Core\Stats;
use RunCache\Integrations\PHPCache;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RunCache registers WP-CLI commands for cache operations
 *
 * This class is responsible for:
 * - Registering command handlers with WP-CLI
 * - Providing cache statistics and management commands
 * - Supporting provider-specific enable/disable workflows
 *
 * @package RunCache
 * @since 1.0.0
 */
class RunCache
{
    /**
     * Valid cache providers
     *
     * @var array<int, string>
     */
    // Keep provider validation aligned with the integrations registered by the plugin
    // so native installers can select the LiteSpeed provider on OLS.
    private static $valid_providers = ['php', 'nginx', 'apache', 'litespeed', 'fastcgi', 'runcloud', 'runcloud-native', 'runcloud-srcache', 'redis'];

    /** @phpstan-ignore property.onlyWritten */
    private Logger $logger;
    /** @phpstan-ignore property.onlyWritten */
    private Setting $setting;

    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize WP-CLI commands
     *
     * @since 1.0.0
     * @return void
     */
    public static function init(): void
    {
        if (defined('WP_CLI') && constant('WP_CLI')) {
            WP_CLI::add_command('runcache', self::class);
        }
    }

    /**
     * Validate and return provider from arguments
     *
     * @param array<int, string> $args Command arguments
     * @return string|null Provider name if valid, null otherwise
     */
    private function validate_provider(array $args): ?string
    {
        if (empty($args[0])) {
            WP_CLI::error('Please specify a cache provider: ' . implode(', ', self::$valid_providers));
            return null;
        }

        $provider = strtolower($args[0]);

        if (!in_array($provider, self::$valid_providers)) {
            WP_CLI::error('Invalid provider. Valid options are: ' . implode(', ', self::$valid_providers));
            return null;
        }

        return $provider;
    }

    /**
     * Get cache statistics
     *
     * ## OPTIONS
     *
     * [--pages]
     * : Only return the number of cached pages
     *
     * [--size]
     * : Only return the cache size
     *
     * [--ratio]
     * : Only return the compression ratio
     *
     * ## EXAMPLES
     *
     *     wp runcache stats
     *     wp runcache stats --pages
     *     wp runcache stats --size
     *     wp runcache stats --ratio
     *
     * @when after_wp_load
     * @subcommand stats
     * @since 1.0.0
     */
    public function stats(array $args, array $assoc_args): void
    {
        try {
            $stats = new Stats();
            $response = $stats->cli_get_stats();

            if (empty($response['success'])) {
                WP_CLI::error('Failed to get cache statistics');
                return;
            }

            if (isset($assoc_args['pages'])) {
                WP_CLI::line($response['data']['cached_pages']);
                return;
            }

            if (isset($assoc_args['size'])) {
                WP_CLI::line($response['data']['cache_size']);
                return;
            }

            if (isset($assoc_args['ratio'])) {
                WP_CLI::line($response['data']['compression_ratio']);
                return;
            }

            WP_CLI::success('Cache Statistics:');
            WP_CLI::line('Cached Pages: ' . $response['data']['cached_pages']);
            WP_CLI::line('Cache Size: ' . $response['data']['cache_size']);
            WP_CLI::line('Compression Ratio: ' . $response['data']['compression_ratio']);
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Preload all URLs in cache
     *
     * This command will preload all URLs in the site into the cache.
     * The preload process runs in the background and may take some time to complete.
     *
     * ## EXAMPLES
     *
     *     wp runcache preload-all
     *
     * @when after_wp_load
     * @subcommand preload-all
     * @since 1.0.0
     */
    public function preload_all(): void
    {
        try {
            $preloader = new Preloader();

            $preloader->start_preload();
            WP_CLI::success('Cache preload has been initiated');
            WP_CLI::line('Preloading URLs in the background. This may take a while...');
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Purge cache (entire site or specific URL) with optional preload
     *
     * This command will purge all cached content or a specific URL.
     * Optionally, it can also start preloading the cache.
     *
     * ## OPTIONS
     *
     * [--target-url=<url>]
     * : Purge cache for a specific URL only.
     *
     * [--preload]
     * : If set, starts preloading after purge.
     *
     * ## EXAMPLES
     *
     *     wp runcache purge
     *     wp runcache purge --target-url=https://example.com/hello-world
     *     wp runcache purge --target-url=https://example.com/hello-world --preload
     *
     * @when after_wp_load
     * @subcommand purge
     * @since 1.0.0
     */
    public function purge( $args = [], $assoc_args = [] ): void
    {
        $url = isset( $assoc_args['target-url'] ) ? esc_url_raw( $assoc_args['target-url'] ) : null;
        $preload = isset( $assoc_args['preload'] );

        try {
            $purger = new Purger();

            if ( $url ) {
                $purged = $purger->purge_by_url($url);

                if ( $purged > 0 ) {
                    WP_CLI::success( sprintf( 'Cache purged for %s', $url ) );
                } else {
                    WP_CLI::success( 'Purge completed' );
                }

                return;

            } else {
                // Purge all cache
                if ( ! $purger->purge_all() ) {
                    WP_CLI::error( 'Failed to purge cache' );
                    return;
                }

                WP_CLI::success( 'Cache has been purged' );

                if ( $preload ) {
                    $preloader = new Preloader();
                    $preloader->start_preload();
                    WP_CLI::success( 'Cache preload has been initiated' );
                    WP_CLI::line( 'Preloading URLs in the background. This may take a while...' );
                }
            }

        } catch ( Exception $e ) {
            WP_CLI::error( $e->getMessage() );
        }
    }

    /**
     * Purge all cache and start preloading
     *
     * This command will purge all cached content and then
     * immediately start preloading the cache with fresh content.
     *
     * ## EXAMPLES
     *
     *     wp runcache purge-and-preload
     *
     * @when after_wp_load
     * @subcommand purge-and-preload
     * @since 1.0.0
     */
    public function purge_and_preload(): void
    {
        WP_CLI::warning( 'The "purge-and-preload" command is deprecated. Use "wp runcache purge --preload" instead.' );
        $this->purge([], ['preload' => true]);

    }

    /**
     * Enable cache provider
     *
     * Enable a specific cache provider (php, nginx, apache, runcloud).
     * For RunCloud provider, additional parameters are required.
     *
     * ## OPTIONS
     *
     * [<provider>]
     * : Optional provider to enable (php, nginx, apache, runcloud)
     *
     * [--blog-id=<id>]
     * : Specify a blog/site ID to enable cache for (required for multisite)
     *
     * ## EXAMPLES
     *
     *     wp runcache enable php
     *     wp runcache enable nginx
     *     wp runcache enable apache
     *     wp runcache enable php --blog-id=1
     *
     * @when after_wp_load
     * @subcommand enable
     * @since 1.0.0
     */
    public function enable(array $args, array $assoc_args = []): void
    {
        $blog_id = null;
        $switched = false;

        // Check for multisite and require blog-id
        if (is_multisite()) {
            $blog_id = isset($assoc_args['blog-id']) ? absint($assoc_args['blog-id']) : null;
            if (empty($blog_id)) {
                WP_CLI::error('This is a multisite installation. Please specify a blog ID using --blog-id=<id>');
                return;
            }
            switch_to_blog($blog_id);
            $switched = true;
        }

        $cache = new PageCache();

        try {
            if (empty($args)) {
                $cache->enable();

                if ($switched) {
                    restore_current_blog();
                }

                return;
            }

            $provider = $this->validate_provider($args);
            if ($provider === null) {
                if ($switched) {
                    restore_current_blog();
                }
                return;
            }

            if ($provider === 'runcloud') {
                WP_CLI::warning('RunCloud sync configuration is not available via CLI.');
            }

            $settings = $cache->get_settings();

            $settings['enable'] = true;
            $settings['page_cache_type'] = $provider;

            if ($provider === 'runcloud') {
                $settings['page_cache_type'] = 'runcloud';
            }

            if (!$cache->save_settings($settings)) {
                WP_CLI::error(sprintf('Failed to enable %s Cache', ucfirst($provider)));

                if ($switched) {
                    restore_current_blog();
                }
                return;
            }

            WP_CLI::success(ucfirst($provider) . ' Cache has been enabled');

            $verification = $cache->verify_cache();

            if ($switched) {
                restore_current_blog();
            }

            if ($verification['verified']) {
                WP_CLI::success(ucfirst($provider) . ' Cache has been verified and is working');
                return;
            }

            WP_CLI::warning(ucfirst($provider) . " Cache has been enabled but verification failed. Run 'wp runcache status' to diagnose.");
        } catch (Exception $e) {
            if ($switched) {
                restore_current_blog();
            }
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Disable cache provider
     *
     * Disable the page cache entirely or a specific cache provider.
     *
     * ## OPTIONS
     *
     * [<provider>]
     * : Optional cache provider to disable (php, nginx, apache, runcloud)
     *
     * [--blog-id=<id>]
     * : Specify a blog/site ID to disable cache for (required for multisite)
     *
     * ## EXAMPLES
     *
     *     wp runcache disable
     *     wp runcache disable php
     *     wp runcache disable nginx
     *     wp runcache disable apache
     *     wp runcache disable runcloud
     *     wp runcache disable php --blog-id=1
     *
     * @when after_wp_load
     * @subcommand disable
     * @since 1.0.0
     */
    public function disable(array $args, array $assoc_args = []): void
    {
        $blog_id = null;
        $switched = false;

        // Check for multisite and require blog-id
        if (is_multisite()) {
            $blog_id = isset($assoc_args['blog-id']) ? absint($assoc_args['blog-id']) : null;
            if (empty($blog_id)) {
                WP_CLI::error('This is a multisite installation. Please specify a blog ID using --blog-id=<id>');
                return;
            }
            switch_to_blog($blog_id);
            $switched = true;
        }

        $cache = new PageCache();

        if (empty($args)) {
            try {
                $cache->disable();
                WP_CLI::success('Page Cache has been disabled');

                if ($switched) {
                    restore_current_blog();
                }
                return;
            } catch (Exception $e) {
                if ($switched) {
                    restore_current_blog();
                }
                WP_CLI::error($e->getMessage());
            }
        }

        $provider = $this->validate_provider($args);
        if ($provider === null) {
            if ($switched) {
                restore_current_blog();
            }
            return;
        }

        try {
            $settings = $cache->get_settings();

            if ($settings['page_cache_type'] !== $provider) {
                WP_CLI::warning(ucfirst($provider) . ' Cache is not currently active');
                if ($switched) {
                    restore_current_blog();
                }
                return;
            }

            $settings['enable'] = false;

            if ($cache->save_settings($settings)) {
                WP_CLI::success(ucfirst($provider) . ' Cache has been disabled');

                (new PHPCache(new \RunCache\Support\Logger(), new Setting()))->enable();

                if ($switched) {
                    restore_current_blog();
                }
                return;
            }

            WP_CLI::error(sprintf('Failed to disable %s Cache', ucfirst($provider)));
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Enable CDN
     *
     * Enables CDN delivery using the current configuration.
     *
     * ## EXAMPLES
     *
     *     wp runcache enable-cdn
     *
     * @when after_wp_load
     * @subcommand enable-cdn
     * @since 1.0.0
     */
    public function enable_cdn(): void
    {
        try {
            (new Cdn())->enable();
            WP_CLI::success('CDN has been enabled');
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Disable CDN
     *
     * Disables CDN delivery while keeping settings intact.
     *
     * ## EXAMPLES
     *
     *     wp runcache disable-cdn
     *
     * @when after_wp_load
     * @subcommand disable-cdn
     * @since 1.0.0
     */
    public function disable_cdn(): void
    {
        try {
            (new Cdn())->disable();
            WP_CLI::success('CDN has been disabled');
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Enable object cache
     *
     * Enable the object cache using the existing configuration.
     *
     * ## EXAMPLES
     *
     *     wp runcache enable-object-cache
     *
     * @when after_wp_load
     * @subcommand enable-object-cache
     * @since 1.0.0
     */
    public function enable_object_cache(): void
    {
        try {
            $enabled = (new ObjectCache())->enable();
            if ($enabled) {
                WP_CLI::success('Object Cache has been enabled');
            } else {
                WP_CLI::error('Object Cache could not be enabled. Check Redis settings and file permissions.');
            }
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Disable object cache
     *
     * Disable the object cache regardless of provider.
     *
     * ## EXAMPLES
     *
     *     wp runcache disable-object-cache
     *
     * @when after_wp_load
     * @subcommand disable-object-cache
     * @since 1.0.0
     */
    public function disable_object_cache(): void
    {
        try {
            (new ObjectCache())->disable();
            WP_CLI::success('Object Cache has been disabled');
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Show cache settings and status
     *
     * Displays current cache configuration and status, including:
     * - Whether cache is enabled
     * - Current cache provider
     * - Cache verification status
     * - Additional provider-specific details
     *
     * ## OPTIONS
     *
     * [--type]
     * : If set, only returns the cache type
     *
     * ## EXAMPLES
     *
     *     wp runcache status
     *     wp runcache status --type
     *
     * @when after_wp_load
     * @subcommand status
     * @since 1.0.0
     */
    public function status(array $args, array $assoc_args): void
    {
        try {
            $cache = new PageCache();
            $settings = $cache->get_settings();

            if (isset($assoc_args['type'])) {
                if (empty($settings['enable'])) {
                    WP_CLI::line('none');
                    return;
                }

                $verification = $cache->verify_cache();
                WP_CLI::line($verification['type']);
                return;
            }

            WP_CLI::success('Cache Settings Status:');

            $enabled = !empty($settings['enable']);
            WP_CLI::line('Cache Enabled: ' . ($enabled ? 'Yes' : 'No'));

            $provider = $settings['page_cache_type'] ?? 'none';
            WP_CLI::line('Current Cache Type: ' . ucfirst($provider));

            if (!$enabled) {
                return;
            }

            $verification = $cache->verify_cache();
            $verified = $verification['verified'];
            $cache_type = $verification['type'];

            WP_CLI::line('Verification Status:');
            WP_CLI::line('- Status: ' . ($verified ? 'Verified' : 'Not Verified'));
            WP_CLI::line('- Cache Type: ' . ucfirst($cache_type));

            WP_CLI::line('Provider Details:');
            switch ($provider) {
                case 'php':
                    WP_CLI::line('- Cache Directory: ' . $cache->get_cache_directory());
                    break;
                case 'nginx':
                case 'apache':
                case 'litespeed':
                case 'runcloud':
                case 'runcloud-native':
                case 'runcloud-srcache':
                    $config_file = $provider === 'apache' ? 'runcache-apache.conf' : 'runcache-nginx.conf';
                    if ($provider === 'nginx') {
                        $config_dir = (new File())->get_config_dir();
                        if ($config_dir !== false) {
                            $config_file = $config_dir . $config_file;
                        }
                    }
                    WP_CLI::line('- Config File: ' . $config_file);
                    break;
            }

            $stats = (new Stats())->cli_get_stats();
            if (!empty($stats['data'])) {
                WP_CLI::line('\nCache Statistics:');
                foreach ($stats['data'] as $key => $value) {
                    WP_CLI::line('- ' . ucwords(str_replace('_', ' ', (string) $key)) . ': ' . $value);
                }
            }
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }


    /**
     * Toggle page cache on/off
     *
     * Enables or disables the page cache based on current status.
     * This command will use the currently configured cache type (PHP, Nginx, Apache, RunCloud).
     *
     * ## EXAMPLES
     *
     *     wp runcache toggle-page-cache
     *
     * @when after_wp_load
     * @subcommand toggle-page-cache
     * @since 1.0.0
     */
    public function toggle_page_cache(): void
    {
        try {
            $cache = new PageCache();
            $settings = $cache->get_settings();

            $status = $settings['enable'] ? 'enabled' : 'disabled';
            $type = $settings['page_cache_type'];

            WP_CLI::line('Current state: ' . ucfirst($type) . ' cache is ' . $status);

            // Toggle the cache on/off, preserving the existing page_cache_type.
            // save_settings() merges partial input with the stored settings, so
            // passing only 'enable' keeps the previously-configured provider.
            if ($settings['enable']) {
                // Cache is currently enabled — toggle it OFF
                $cache->save_settings([
                    'enable' => false,
                ]);
                WP_CLI::success('Page cache has been disabled.');
                return;
            }

            // Cache is currently disabled — toggle it ON (preserve cache type)
            $cache->save_settings([
                'enable' => true,
            ]);
            WP_CLI::success('Page cache has been enabled.');

            $verification = $cache->verify_cache();

            if ($verification['verified']) {
                WP_CLI::success('Cache provider verified successfully');
            } else {
                WP_CLI::warning('Cache enabled but verification pending: ' . ($verification['message'] ?? 'N/A'));
            }
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

}

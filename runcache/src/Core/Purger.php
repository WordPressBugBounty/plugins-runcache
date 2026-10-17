<?php

namespace RunCache\Core;

use Exception;
use RunCache\Integrations\Multisite;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Purge cache management and cache invalidation handling
 */
class Purger
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings = [
        'purge_permalink_onn' => true,
        'purge_theme_switch_onn' => true,
        'purge_general_onn' => true,
        'purge_customize_onn' => false,
        'purge_upgrader_onn' => false,
        'purge_plugin_onn' => false,
        'purge_navmenu_onn' => false,
        'purge_sidebar_onn' => false,
        'purge_widget_onn' => false,
        'homepage_post_onn' => true,
        'homepage_removed_onn' => true,
        'content_publish_onn' => true,
        'content_comment_approved_onn' => true,
        'archives_content_onn' => true,
        'archives_cpt_onn' => true,
        'archives_category_onn' => true,
        'archives_author_onn' => false,
        'archives_feed_onn' => false,
        'schedule_purge_onn' => false,
        'schedule_purge_int' => 12,
        'schedule_purge_unt' => 3600,
        'html_footprint_onn' => false
    ];

    /**
     * Recurrence key registered via the `cron_schedules` filter and used
     * when calling `wp_schedule_event()` for the scheduled purge.
     */
    private const SCHEDULE_KEY = 'runcache_purge_interval';

    /** Cron hook fired for scheduled purge. */
    private const SCHEDULE_HOOK = 'runcache_scheduled_purge';

    /** Cron hook fired for expired filesystem-cache cleanup. */
    private const CLEANUP_HOOK = 'runcache_scheduled_cleanup';

    /** Recurrence key for expired filesystem-cache cleanup. */
    private const CLEANUP_SCHEDULE_KEY = 'runcache_cleanup_hourly';

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
     * Initialize purger functionality
     *
     * @return void
     */
    public function init(): void
    {
        // Register custom cron interval + hook callback before any code that
        // may call `wp_schedule_event()` for the scheduled purge.
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action(self::SCHEDULE_HOOK, [$this, 'purge_all']);
        add_action(self::CLEANUP_HOOK, [$this, 'purge_expired']);
        add_action('init', [$this, 'setup_expired_cleanup'], 20);

        $this->register_ajax_handlers();
        $this->register_admin_post_handlers();
        $this->register_theme_content_hooks();
        $this->register_option_hooks();
        $this->register_post_hooks();
        $this->register_comment_hooks();
        $this->register_conditional_hooks();
    }

    /**
     * Register AJAX handlers
     *
     * @return void
     */
    private function register_ajax_handlers(): void
    {
        add_action('wp_ajax_runcache_purge_all', [$this, 'ajax_purge_all']);
        add_action('wp_ajax_runcache_purge_and_preload', [$this, 'ajax_purge_and_preload']);
        add_action('wp_ajax_runcache_purge_expired', [$this, 'ajax_purge_expired']);
        add_action('wp_ajax_runcache_purge_by_url', [$this, 'ajax_purge_by_url']);
        add_action('wp_ajax_runcache_get_purger_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_purger_settings', [$this, 'ajax_save_settings']);
    }

    /**
     * Register admin_post handlers
     *
     * @return void
     */
    private function register_admin_post_handlers(): void
    {
        add_action('admin_post_runcache_purge_by_url', [$this, 'handle_purge_by_url']);
        add_action('admin_post_runcache_purge_all', [$this, 'handle_purge_all']);
    }

    /**
     * Register theme and content-related hooks
     *
     * @return void
     */
    private function register_theme_content_hooks(): void
    {
        add_action('permalink_structure_changed', [$this, 'handle_permalink_change']);
        add_action('switch_theme', [$this, 'handle_theme_switch']);
        add_action('customize_save_after', [$this, 'handle_customize_save']);
        add_action('upgrader_process_complete', [$this, 'handle_upgrader_complete'], 10, 2);
        add_action('activate_plugin', [$this, 'handle_plugin_toggle']);
        add_action('deactivate_plugin', [$this, 'handle_plugin_toggle']);
    }

    /**
     * Register option update hooks
     *
     * @return void
     */
    private function register_option_hooks(): void
    {
        add_action('wp_update_nav_menu', [$this, 'handle_wp_update_nav_menu']);
        add_action('update_option_sidebars_widgets', [$this, 'handle_update_option_sidebars_widgets']);
        add_action('widget_update', [$this, 'handle_widget_update']);

        $general_options = ['blogname', 'blogdescription', 'siteurl', 'home'];
        foreach ($general_options as $option) {
            add_action("update_option_{$option}", [$this, 'handle_general_option_update']);
        }
    }

    /**
     * Register post-related hooks
     *
     * @return void
     */
    private function register_post_hooks(): void
    {
        add_action('save_post', [$this, 'handle_save_post'], 10, 3);
        add_action('transition_post_status', [$this, 'handle_scheduled_publish'], 10, 3);
        add_action('wp_trash_post', [$this, 'handle_trashed_post']);
        add_action('publish_future_post', [$this, 'handle_publish_future_post'], 20);
    }

    /**
     * Register comment hooks
     *
     * @return void
     */
    private function register_comment_hooks(): void
    {
        $comment_id_hooks = [
            'wp_insert_comment',
            'edit_comment',
            'delete_comment',
            'trash_comment',
            'untrash_comment',
        ];

        foreach ($comment_id_hooks as $hook) {
            add_action($hook, [$this, 'handle_comment_update'], 10, 1);
        }

        // These hooks expose the comment ID/status in different argument positions and types.
        add_action('wp_set_comment_status', [$this, 'handle_comment_status_update'], 10, 1);
        add_action('transition_comment_status', [$this, 'handle_comment_status_transition'], 10, 3);
    }

    /**
     * Register conditionally enabled hooks
     *
     * @return void
     */
    private function register_conditional_hooks(): void
    {
        $settings = $this->get_settings();

        if ($settings['schedule_purge_onn']) {
            $this->setup_scheduled_purge(
                (int) $settings['schedule_purge_int'],
                (string) $settings['schedule_purge_unt']
            );
        } else {
            $this->clear_scheduled_purge();
        }

        add_filter('runcache_purge_and_preload', [$this, 'handle_purge_and_preload']);
    }

    /**
     * Purge cache if the specified setting is enabled
     *
     * @param string $setting_key The setting key to check
     * @param string $log_message The log message
     * @return void
     */
    private function purge_if_enabled(string $setting_key, string $log_message): void
    {
        $settings = $this->get_settings();

        if (!isset($settings[$setting_key]) || !$settings[$setting_key]) {
            return;
        }

        $this->logger->info($log_message);
        $this->purge_all();
    }

    /**
     * Purge all cache
     *
     * Walks the entire cache directory, deletes every cache entry, and returns
     * success only when deletion is verified. This avoids the host/port mismatch
     * that made earlier versions report success while deleting nothing.
     *
     * @return bool
     */
    public function purge_all(): bool
    {
        $multisite = new Multisite();
        $site_info = $multisite->get_site_info();
        $this->logger->info('Purging cache for current site', [
            'site_type' => $site_info['type'],
            'identifier' => $site_info['identifier']
        ]);

        $cache = new PageCache();
        $cache_dir = $cache->get_cache_directory();

        $cleared = $this->clear_cache_directory($cache_dir);

        $cache->ensure_cache_directory();

        do_action('runcache_after_purge_all');

        if (!$cleared['success']) {
            $this->logger->error('Cache purge completed with errors', [
                'deleted_files' => $cleared['deleted_files'],
                'deleted_dirs' => $cleared['deleted_dirs'],
                'errors' => $cleared['errors'],
            ]);
            return false;
        }

        $this->logger->info('Cache purged successfully', [
            'deleted_files' => $cleared['deleted_files'],
            'deleted_dirs' => $cleared['deleted_dirs'],
        ]);

        return true;
    }

    /**
     * Purge network-wide cache
     *
     * @return bool
     */
    public function purge_network(): bool
    {
        if (!current_user_can('manage_network')) {
            $this->logger->error('Unauthorized attempt to purge network cache');
            return false;
        }

        $this->logger->info('Purging network-wide cache');

        $cache = new PageCache();
        $cache_dir = $cache->get_cache_directory();

        $cleared = $this->clear_cache_directory($cache_dir, true);

        $cache->ensure_cache_directory();

        do_action('runcache_after_purge_network');

        if (!$cleared['success']) {
            $this->logger->error('Network-wide cache purge completed with errors', [
                'deleted_files' => $cleared['deleted_files'],
                'deleted_dirs' => $cleared['deleted_dirs'],
                'errors' => $cleared['errors'],
            ]);
            return false;
        }

        $this->logger->info('Network-wide cache purged', [
            'deleted_files' => $cleared['deleted_files'],
            'deleted_dirs' => $cleared['deleted_dirs'],
        ]);

        return true;
    }

    /**
     * Recursively delete cache entries from a directory.
     *
     * Top-level files (e.g. .enabled-* markers and index.html) are preserved
     * by default so a purge does not silently disable the cache provider.
     *
     * @param string $directory Directory to clear.
     * @param bool $remove_top_level_files Whether to also delete top-level files.
     * @return array{deleted_files:int, deleted_dirs:int, errors:array<int,string>, success:bool}
     */
    private function clear_cache_directory(string $directory, bool $remove_top_level_files = false): array
    {
        $result = [
            'deleted_files' => 0,
            'deleted_dirs' => 0,
            'errors' => [],
            'success' => true,
        ];

        $file = new File();

        if (!$file->exists($directory) || !is_dir($directory)) {
            return $result;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $path = $item->getPathname();

                // Preserve top-level files (e.g. .enabled-* markers, index.html)
                // unless this is an explicit full-directory wipe.
                if (!$remove_top_level_files && $iterator->getDepth() === 0 && $item->isFile()) {
                    continue;
                }

                if ($item->isDir()) {
                    if ($file->delete($path, true)) {
                        $result['deleted_dirs']++;
                    } else {
                        $result['errors'][] = "Failed to remove directory: {$path}";
                        $result['success'] = false;
                    }
                } else {
                    if ($file->delete($path)) {
                        $result['deleted_files']++;
                    } else {
                        $result['errors'][] = "Failed to delete file: {$path}";
                        $result['success'] = false;
                    }
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
            $result['success'] = false;
        }

        return $result;
    }

    /**
     * Purge expired cache files
     *
     * @return int
     */
    public function purge_expired(): int
    {
        $cache = new PageCache();
        $cache_dir = rtrim($cache->get_cache_directory(), '/\\') . DIRECTORY_SEPARATOR;
        $scan_dir = $cache_dir;

        if (Multisite::is_multisite()) {
            $scan_dir .= trim($this->get_cache_host(), '/\\') . DIRECTORY_SEPARATOR;
            $this->logger->info('Purging expired cache files for current site');
        } else {
            $this->logger->info('Purging expired cache files (single site)');
        }

        if (!is_dir($scan_dir)) {
            do_action('runcache_after_purge_expired');
            return 0;
        }

        $purged = 0;
        $file = new File();
        $now = time();
        $sentinel_path = wp_normalize_path($cache_dir . 'index.html');

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scan_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file_info) {
                if (!$file_info->isFile() || !str_ends_with($file_info->getFilename(), '.html')) {
                    continue;
                }

                // Never treat the cache-root index.html sentinel as an orphaned entry.
                if (wp_normalize_path($file_info->getPathname()) === $sentinel_path) {
                    continue;
                }

                $file_path = $file_info->getPathname();
                $meta_file = substr($file_path, 0, -5) . '.meta';
                if (!$file->exists($meta_file)) {
                    // Static providers serve by HTML existence, so an orphaned
                    // file must not remain publicly cacheable indefinitely.
                    if ($file->delete($file_path)) {
                        $file->delete($file_path . '.gz');
                        $purged++;
                    }
                    continue;
                }

                $meta_content = $file->read($meta_file);
                $meta = is_string($meta_content) ? json_decode($meta_content, true) : null;
                if (!is_array($meta)) {
                    if ($file->delete($file_path)) {
                        $file->delete($meta_file);
                        $file->delete($file_path . '.gz');
                        $purged++;
                    }
                    continue;
                }

                // Prefer the entry's absolute expiry so cleanup matches the
                // PHP hit path; retain the time/TTL fallback for older entries.
                $expires = isset($meta['expires']) && is_numeric($meta['expires'])
                    ? (int) $meta['expires']
                    : 0;
                if ($expires === 0 && isset($meta['time']) && is_numeric($meta['time'])) {
                    $settings = $this->setting->get('cache', []);
                    $cache_expire = max(1, (int) ($settings['cache_expiry'] ?? 28800));
                    $expires = (int) $meta['time'] + $cache_expire;
                }

                if ($expires === 0 || $now < $expires) {
                    continue;
                }

                // Do not remove metadata first: if the HTML unlink fails,
                // retaining metadata lets the next cleanup retry the stale file.
                if ($file->exists($file_path) && !$file->delete($file_path)) {
                    $this->logger->warning('Failed to delete expired cache file', [
                        'file' => $file_path,
                    ]);
                    continue;
                }

                foreach ([$meta_file, $file_path . '.gz'] as $companion_file) {
                    if ($file->exists($companion_file) && !$file->delete($companion_file)) {
                        $this->logger->warning('Failed to delete expired cache companion file', [
                            'file' => $companion_file,
                        ]);
                    }
                }

                $purged++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scan expired cache files', [
                'directory' => $scan_dir,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Expired cache files purged', ['purged' => $purged]);
        do_action('runcache_after_purge_expired');

        return $purged;
    }

    /**
     * Purge taxonomy archives for a post
     *
     * @param int $post_id
     * @return void
     */
    private function purge_taxonomy_archives(int $post_id): void
    {
        $taxonomies = get_object_taxonomies(get_post_type($post_id));
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $this->logger->info('Purging taxonomy archive', ['taxonomy' => $taxonomy, 'term' => $term]);
                    $this->purge_by_url(get_term_link($term));
                }
            }
        }
    }

    /**
     * Purge homepage cache
     *
     * @return void
     */
    public function purge_homepage(): void
    {
        $home_url = home_url('/');
        if ($this->validator->sanitize_url($home_url) !== false) {
            $this->logger->info('Purging homepage cache', ['url' => $home_url]);
            $this->purge_by_url($home_url);
        }
    }

    /**
     * Purge post type archive
     *
     * @param string $post_type
     * @return void
     */
    private function purge_post_type_archive(string $post_type): void
    {
        $archive_url = get_post_type_archive_link($post_type);
        if ($archive_url) {
            $this->logger->info('Purging post type archive', ['post_type' => $post_type, 'url' => $archive_url]);
            $this->purge_by_url($archive_url);
        }
    }

    /**
     * Purge author archive
     *
     * @param int $author_id
     * @return void
     */
    private function purge_author_archive(int $author_id): void
    {
        $url = get_author_posts_url($author_id);
        if ($url) {
            $this->logger->info('Purging author archive', ['author_id' => $author_id, 'url' => $url]);
            $this->purge_by_url($url);
        }
    }

    /**
     * Purge feeds
     *
     * @return void
     */
    private function purge_feeds(): void
    {
        $feed_url = get_feed_link();
        if ($feed_url) {
            $this->logger->info('Purging feed cache', ['feed_url' => $feed_url]);
            $this->purge_by_url($feed_url);
        }
    }

    /**
     * Purge post cache
     *
     * @param int $post_id
     * @return void
     */
    public function purge_post(int $post_id): void
    {
        $url = get_permalink($post_id);
        if ($url) {
            $this->logger->info('Purging post cache', [
                'post_id' => $post_id,
                'url' => $url
            ]);
            $this->purge_by_url($url);

            do_action('runcache_after_purge_post', $post_id, [$url]);
        }
    }

    /**
     * Purge cache by URL
     *
     * Deletes the base cache file at {host}/{path}/index.html and any variant
     * directories: q_{md5_8} (filtered query string) and e_{md5_8} (extra cache
     * key vars). This is required because cache_key_extra_var and
     * ignore_query_mch / allow_query_mch produce per-variant cache entries at
     * different filesystem paths.
     *
     * @param string $url
     * @return int
     */
    public function purge_by_url(string $url): int
    {
        $cache = new PageCache();
        $cache_dir = $cache->get_cache_directory();
        $purged = 0;

        $cache_path = $this->url_to_cache_path($url, $cache_dir);

        if ($cache_path) {
            $base_dir = rtrim($cache_path, '/');

            // 1. Base path: {host}/{path}/index.html
            $purged += $this->delete_cache_entry($base_dir);

            // 2. Query variants: {host}/{path}/q_{md5_8}/index.html
            //    and combined: {host}/{path}/q_{md5_8}/e_{md5_8}/index.html
            $q_variants = glob($base_dir . '/q_*', GLOB_ONLYDIR);
            if (is_array($q_variants)) {
                foreach ($q_variants as $q_dir) {
                    $purged += $this->delete_cache_entry($q_dir);
                    // Combined q_*/e_*/
                    $e_inside_q = glob($q_dir . '/e_*', GLOB_ONLYDIR);
                    if (is_array($e_inside_q)) {
                        foreach ($e_inside_q as $e_dir) {
                            $purged += $this->delete_cache_entry($e_dir);
                        }
                    }
                }
            }

            // 3. Extra-vars-only variants: {host}/{path}/e_{md5_8}/index.html
            //    (exists when there are no filtered query params but extras are set)
            $e_variants = glob($base_dir . '/e_*', GLOB_ONLYDIR);
            if (is_array($e_variants)) {
                foreach ($e_variants as $e_dir) {
                    $purged += $this->delete_cache_entry($e_dir);
                }
            }

            // 4. Clean up now-empty directories. For the homepage the base
            //    directory IS the host cache root; deleting it recursively
            //    would wipe every cached page on the site (H4). Only remove
            //    the host root for non-homepage URLs.
            $is_homepage = $this->is_homepage_url($url);
            if ($is_homepage && is_dir($base_dir)) {
                $homepage_variants = array_merge(
                    is_array($q_variants) ? $q_variants : [],
                    is_array($e_variants) ? $e_variants : []
                );
                foreach ($homepage_variants as $variant_dir) {
                    if (is_dir($variant_dir)) {
                        $file = new File();
                        $file->delete($variant_dir, true);
                    }
                }
            } elseif (is_dir($base_dir)) {
                $file = new File();
                $file->delete($base_dir, true);
            }

            if ($purged > 0) {
                $this->logger->info('Purged cache by URL', [
                    'url' => $url,
                    'directory' => $base_dir,
                    'entries_deleted' => $purged,
                ]);
            }
        }

        do_action('runcache_after_purge_url', $url);

        return $purged;
    }

    /**
     * Delete a single cache entry: index.html + index.meta + index.html.gz at the given directory.
     *
     * @param string $dir Directory containing the cache entry (e.g. .../q_xxxx or .../e_yyyy).
     * @return int Number of files actually deleted.
     */
    private function delete_cache_entry(string $dir): int
    {
        $file = new File();
        $deleted = 0;
        $index_html = $dir . '/index.html';

        if (!$file->exists($index_html)) {
            return 0;
        }

        if ($file->delete($index_html)) {
            $deleted++;
        }

        $meta_file = $dir . '/index.meta';
        if ($file->exists($meta_file) && $file->delete($meta_file)) {
            $deleted++;
        }

        $gz_file = $index_html . '.gz';
        if ($file->exists($gz_file) && $file->delete($gz_file)) {
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Get the canonical cache host for the current site.
     *
     * Mirrors the host used by the front-end drop-in (runcache-page-php.php)
     * which uses HTTP_HOST verbatim, including the non-default port. This
     * keeps admin-side purge paths aligned with the on-disk layout.
     *
     * @return string
     */
    private function get_cache_host(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
        }

        $home = home_url('/');
        $parsed = wp_parse_url($home);

        if (!$parsed || !isset($parsed['host'])) {
            return Setting::get_site_host();
        }

        $host = $parsed['host'];
        if (!empty($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }

        return $host;
    }

    /**
     * Check if a URL is the site homepage.
     *
     * @param string $url
     * @return bool
     */
    private function is_homepage_url(string $url): bool
    {
        $parsed = wp_parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }

        $path = trim($parsed['path'] ?? '/', '/');
        return $path === '';
    }

    /**
     * Convert URL to cache path
     *
     * @param string $url_pattern
     * @param string $cache_dir
     * @return string|null
     */
    private function url_to_cache_path(string $url_pattern, string $cache_dir): ?string
    {
        $parsed_url = wp_parse_url($url_pattern);

        if (!$parsed_url || !isset($parsed_url['host'])) {
            $this->logger->error('Invalid URL pattern', ['pattern' => $url_pattern]);
            return null;
        }

        $host = $parsed_url['host'];
        if (!empty($parsed_url['port'])) {
            $host .= ':' . (int)$parsed_url['port'];
        }
        $path = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';

        // Reject directory traversal in the URL path.
        if (str_contains($path, '..')) {
            $this->logger->error('Invalid URL path: directory traversal detected', ['pattern' => $url_pattern]);
            return null;
        }

        $cache_path = $cache_dir . $host;

        if (!empty($path)) {
            $cache_path .= '/' . $path;
        }

        // Resolve and verify the cache path stays inside the cache directory.
        $real_cache_dir = realpath($cache_dir) ?: $cache_dir;
        $real_cache_path = realpath($cache_path);
        if ($real_cache_path === false) {
            $real_cache_path = $cache_path;
        }

        if (
            !str_starts_with($real_cache_path, $real_cache_dir . DIRECTORY_SEPARATOR)
            && $real_cache_path !== $real_cache_dir
        ) {
            $this->logger->error('Cache path escapes cache directory', [
                'path' => $cache_path,
                'cache_dir' => $cache_dir,
            ]);
            return null;
        }

        return $cache_path;
    }

    /**
     * Get purger settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $saved_settings = $this->setting->get('purger');
        return wp_parse_args($saved_settings, $this->default_settings);
    }

    /**
     * Get default purger settings
     *
     * @return array
     */
    public function get_default_settings(): array
    {
        return $this->default_settings;
    }

    /**
     * Save purger settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        try {
            $settings = wp_parse_args($settings, $this->default_settings);

            $validated = [];

            $bool_settings = [
                'purge_permalink_onn',
                'purge_theme_switch_onn',
                'purge_general_onn',
                'purge_customize_onn',
                'purge_upgrader_onn',
                'purge_plugin_onn',
                'purge_navmenu_onn',
                'purge_sidebar_onn',
                'purge_widget_onn',
                'homepage_post_onn',
                'homepage_removed_onn',
                'content_publish_onn',
                'content_comment_approved_onn',
                'archives_content_onn',
                'archives_cpt_onn',
                'archives_category_onn',
                'archives_author_onn',
                'archives_feed_onn',
                'schedule_purge_onn',
                'html_footprint_onn'
            ];

            foreach ($bool_settings as $key) {
                $validated[$key] = isset($settings[$key]) ? (bool)$settings[$key] : $this->default_settings[$key];
            }

            $validated['schedule_purge_int'] = isset($settings['schedule_purge_int'])
                ? max(1, (int) $settings['schedule_purge_int'])
                : $this->default_settings['schedule_purge_int'];

            $valid_units = ['60', '3600', '86400'];
            $validated['schedule_purge_unt'] = isset($settings['schedule_purge_unt']) && in_array((string) $settings['schedule_purge_unt'], $valid_units, true)
                ? (string) $settings['schedule_purge_unt']
                : (string) $this->default_settings['schedule_purge_unt'];

            $result = $this->setting->set('purger', $validated);

            if ($result) {
                do_action('runcache_purger_updated', $validated);

                $this->logger->info('Settings saved successfully', ['component' => 'Purger']);
            } else {
                $this->logger->error('Failed to save purger settings');
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to save purger settings', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * AJAX handler for purging all cache
     *
     * @return void
     */
    public function ajax_purge_all(): void
    {
        $this->validator->validate_ajax_request();

        try {
            $this->purge_all();
            wp_send_json_success(['message' => "Cache purged successfully"]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to purge all cache', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to purge the cache. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for purging and preloading cache
     *
     * @return void
     */
    public function ajax_purge_and_preload(): void
    {
        $this->validator->validate_ajax_request();

        try {
            $purge_result = $this->purge_all();

            if (!$purge_result) {
                wp_send_json_error(['message' => 'Failed to purge cache']);
            }

            $preloader = new Preloader();
            $preloader->start_preload();

            wp_send_json_success(['message' => "Cache purged successfully"]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to purge and preload cache', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to purge and preload the cache. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for purging expired cache
     *
     * @return void
     */
    public function ajax_purge_expired(): void
    {
        $this->validator->validate_ajax_request();

        try {
            $purged_count = $this->purge_expired();
            wp_send_json_success(['purged_count' => $purged_count]);
        } catch (Exception $e) {
            $this->logger->error('Failed to purge expired cache', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to purge expired cache. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for purging cache by URL
     *
     * @return void
     */
    public function ajax_purge_by_url(): void
    {
        $this->validator->validate_ajax_request();

        check_ajax_referer('runcache_nonce');

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

        $preload = filter_var(wp_unslash($_POST['preload'] ?? ''), FILTER_VALIDATE_BOOLEAN);

        if (empty($url)) {
            wp_send_json_error(['message' => 'Please provide a valid URL to purge']);
        }

        try {
            $purged = $this->purge_by_url($url);
            $response = [
                'message' => $purged > 0
                    ? sprintf('Purged cache for %s', $url)
                    : 'Purge completed',
                'purged' => $purged
            ];

            if ($preload) {
                $preloader = new Preloader();
                $preloaded = $preloader->preload_url($url);
                $response['preloaded'] = $preloaded;

                if ($preloaded) {
                    $response['message'] = sprintf('Purged and preloaded %s', $url);
                } else {
                    $response['message'] .= '. Preload failed, please check the logs.';
                }
            }

            wp_send_json_success($response);
        } catch (Exception $e) {
            $this->logger->error('Failed to purge cache by URL', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to purge the cache for this URL. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for getting purger settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();

        try {
            $settings = $this->get_settings();

            // Convert arrays to newline-separated strings for frontend textareas
            foreach ($settings as $key => $value) {
                if (str_ends_with($key, '_mch') && is_array($value)) {
                    $settings[$key] = implode("\n", $value);
                }
            }

            wp_send_json_success($settings);
        } catch (Exception $e) {
            $this->logger->error('Failed to get purger settings', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to load purge settings. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for saving purger settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();

        try {
            $settings = $this->validator->validate_ajax_settings();

            $result = $this->save_settings($settings);

            if ($result) {
                $saved_settings = $this->get_settings();

                // Convert arrays to newline-separated strings for frontend textareas
                foreach ($saved_settings as $key => $value) {
                    if (str_ends_with($key, '_mch') && is_array($value)) {
                        $saved_settings[$key] = implode("\n", $value);
                    }
                }

                wp_send_json_success([
                    'message' => 'Settings saved successfully',
                    'settings' => $saved_settings
                ]);
            } else {
                throw new Exception('Failed to save settings');
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to save purger settings', ['error' => $e->getMessage()]);
            wp_send_json_error([
                'message' => __('Unable to save purge settings. Please try again.', 'runcache')
            ]);
        }
    }

    /**
     * Handle purge and preload action
     *
     * @return array
     */
    public function handle_purge_and_preload(): array
    {
        try {
            $this->purge_all();

            $preloader = new Preloader();
            $preloader->start_preload();

            return ['success' => true];
        } catch (Exception $e) {
            $this->logger->error('Failed to purge and preload cache', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => __('Unable to purge and preload the cache. Please try again.', 'runcache'),
            ];
        }
    }

    /**
     * Handle permalink structure change
     *
     * @return void
     */
    public function handle_permalink_change(): void
    {
        $this->purge_if_enabled('purge_permalink_onn', 'Purging cache due to permalink structure change');
    }

    /**
     * Handle permalink option update
     *
     * @param mixed $old_value
     * @param mixed $new_value
     * @return void
     */
    public function handle_permalink_option_update($old_value, $new_value): void
    {
        $this->logger->info('Permalink structure option updated - Old: ' . $old_value . ', New: ' . $new_value);
        $this->purge_if_enabled('purge_permalink_onn', 'Purging cache due to permalink structure option update');
    }

    /**
     * Handle theme switch
     *
     * @return void
     */
    public function handle_theme_switch(): void
    {
        $this->purge_if_enabled('purge_theme_switch_onn', 'Purging cache due to theme switch');
    }

    /**
     * Handle customizer save
     *
     * @return void
     */
    public function handle_customize_save(): void
    {
        $this->purge_if_enabled('purge_customize_onn', 'Purging cache due to customizer save');
    }

    /**
     * Handle upgrader complete
     *
     * @param object $upgrader
     * @param array $options
     * @return void
     */
    public function handle_upgrader_complete(object $upgrader, array $options): void
    {
        // Remove rewrite rules created by versions that supported custom purge URLs.
        $updated_plugins = isset($options['plugins']) && is_array($options['plugins'])
            ? $options['plugins']
            : [];
        if (
            ($options['action'] ?? '') === 'update'
            && ($options['type'] ?? '') === 'plugin'
            && in_array(RUNCACHE_PLUGIN_BASENAME, $updated_plugins, true)
        ) {
            flush_rewrite_rules();
        }

        $settings = $this->get_settings();
        if ($settings['purge_upgrader_onn']) {
            if (isset($options['type']) && in_array($options['type'], ['theme', 'plugin'], true)) {
                $this->logger->info('Purging cache due to theme/plugin update', [
                    'type' => $options['type'],
                    'action' => $options['action'] ?? 'unknown'
                ]);
                $this->purge_all();
            }
        }
    }

    /**
     * Handle plugin toggle
     *
     * @param string $plugin
     * @return void
     */
    public function handle_plugin_toggle(string $plugin): void
    {
        $this->purge_if_enabled('purge_plugin_onn', 'Purging cache due to plugin toggle: ' . $plugin);
    }

    /**
     * Handle nav menu update
     *
     * @return void
     */
    public function handle_wp_update_nav_menu(): void
    {
        $this->purge_if_enabled('purge_navmenu_onn', 'Purging cache due to nav menu update');
    }

    /**
     * Handle sidebar widgets update
     *
     * @return void
     */
    public function handle_update_option_sidebars_widgets(): void
    {
        $this->purge_if_enabled('purge_sidebar_onn', 'Purging cache due to sidebar update');
    }

    /**
     * Handle widget update
     *
     * @return void
     */
    public function handle_widget_update(): void
    {
        $this->purge_if_enabled('purge_widget_onn', 'Purging cache due to widget update');
    }

    /**
     * Handle general option update
     *
     * @param mixed $old_value
     * @return void
     */
    public function handle_general_option_update(mixed $old_value): void
    {
        $this->purge_if_enabled('purge_general_onn', 'Purging cache due to general setting update');
    }

    /**
     * Purge post-related archives based on settings
     *
     * @param \WP_Post $post
     * @param array $settings
     * @return void
     */
    private function purge_post_archives(\WP_Post $post, array $settings): void
    {
        if ($settings['archives_cpt_onn']) {
            $this->purge_post_type_archive($post->post_type);
        }

        if ($settings['archives_category_onn']) {
            $this->purge_taxonomy_archives((int) $post->ID);
        }

        if ($settings['archives_author_onn']) {
            $this->purge_author_archive((int) $post->post_author);
        }

        if ($settings['archives_feed_onn']) {
            $this->purge_feeds();
        }
    }

    /**
     * Purge cache for a post event based on user settings.
     *
     * Shared logic used by both manual save and scheduled auto-publish.
     *
     * @param \WP_Post $post  The post object.
     * @param string   $event Event name for logging.
     * @return void
     */
    private function purge_on_post_event(\WP_Post $post, string $event): void
    {
        $settings = $this->get_settings();

        $this->logger->info("Handling {$event} purge", [
            'post_id' => $post->ID,
            'post_type' => $post->post_type,
        ]);

        if ($settings['content_publish_onn']) {
            $this->purge_post((int) $post->ID);
        }

        if ($settings['homepage_post_onn']) {
            $this->purge_homepage();
        }

        if ($settings['archives_content_onn']) {
            $this->purge_post_archives($post, $settings);
        }
    }

    /**
     * Handle scheduled post auto-publish via the transition_post_status hook.
     *
     * Catches the `future` → `publish` transition during WP-Cron and is one of
     * three layers (alongside handle_save_post and handle_publish_future_post)
     * ensuring the cache is purged when a scheduled post goes live.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Old post status.
     * @param mixed    $post       Post object.
     * @return void
     */
    public function handle_scheduled_publish(string $new_status, string $old_status, $post): void
    {
        if ($new_status !== 'publish' || $old_status !== 'future' || !$post instanceof \WP_Post) {
            return;
        }

        $post_type = get_post_type_object($post->post_type);
        if (!$post_type || !$post_type->public) {
            return;
        }

        $this->purge_on_post_event($post, 'scheduled publish');
    }

    /**
     * Handle scheduled post auto-publish via the dedicated WordPress cron hook.
     *
     * WordPress fires the `publish_future_post` action when processing a
     * scheduled post via WP-Cron. Registered at priority 20 so WordPress's
     * `check_and_publish_future_post()` (priority 10) has already published
     * the post by the time we execute.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function handle_publish_future_post(int $post_id): void
    {
        $post = get_post($post_id);

        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $post_type = get_post_type_object($post->post_type);
        if (!$post_type || !$post_type->public) {
            return;
        }

        $this->purge_on_post_event($post, 'publish future post (cron)');
    }

    /**
     * Handle save post
     *
     * @param int $post_id
     * @param \WP_Post $post
     * @param bool $update
     * @return void
     */
    public function handle_save_post(int $post_id, $post = null, $update = false): void
    {
        $post_status = get_post_status($post_id);

        if ($post_status === 'auto-draft' || $post_status === 'trash' || $post_status === 'future' || wp_is_post_revision($post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!$post) {
            $post = get_post($post_id);
        }

        if (!$post) {
            $this->logger->warning('Could not retrieve post object', ['post_id' => $post_id]);
            return;
        }

        $this->purge_on_post_event($post, 'save post');
    }

    /**
     * Handle trashed post
     *
     * @param int $post_id
     * @return void
     */
    public function handle_trashed_post(int $post_id): void
    {
        $settings = $this->get_settings();

        if ($settings['content_publish_onn']) {
            $this->purge_post($post_id);
        }

        if ($settings['homepage_removed_onn']) {
            $this->purge_homepage();
        }

        $post = get_post($post_id);

        if ($post) {
            $this->purge_post_archives($post, $settings);
        }
    }

    /**
     * Handle comment update
     *
     * @param int $comment_id
     * @return void
     */
    public function handle_comment_update(int $comment_id): void
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $post_id = (int) $comment->comment_post_ID;

        $this->logger->info('Purging comment cache', ['comment_id' => $comment_id, 'post_id' => $post_id]);

        $this->purge_post($post_id);
    }

    /**
     * Handle the comment status hook, which provides the ID as a numeric string.
     *
     * @param string|int $comment_id
     * @return void
     */
    public function handle_comment_status_update($comment_id): void
    {
        if (!is_numeric($comment_id)) {
            return;
        }

        $this->handle_comment_update((int) $comment_id);
    }

    /**
     * Handle a comment status transition, whose comment object is the third argument.
     *
     * @param string $new_status
     * @param string $old_status
     * @param \WP_Comment $comment
     * @return void
     */
    public function handle_comment_status_transition(string $new_status, string $old_status, \WP_Comment $comment): void
    {
        $this->handle_comment_update((int) $comment->comment_ID);
    }

    /**
     * Register the hourly schedule used by expired filesystem-cache cleanup.
     *
     * @param array $schedules Existing cron schedules.
     * @return array
     */
    public function add_cleanup_schedule(array $schedules): array
    {
        $schedules[self::CLEANUP_SCHEDULE_KEY] = [
            'interval' => HOUR_IN_SECONDS,
            'display' => __('RunCache Expired Cache Cleanup (hourly)', 'runcache'),
        ];

        return $schedules;
    }

    /**
     * Ensure the expired filesystem-cache cleanup event exists.
     *
     * This is intentionally separate from the optional full-cache purge. It
     * removes only expired local entries and does not cold-start the whole cache.
     *
     * @return void
     */
    public function setup_expired_cleanup(): void
    {
        add_filter('cron_schedules', [$this, 'add_cleanup_schedule']);

        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, self::CLEANUP_SCHEDULE_KEY, self::CLEANUP_HOOK);
        }
    }

    /**
     * Register the custom cron schedule used by scheduled purge.
     *
     * The interval is recomputed from the current settings every time the
     * `cron_schedules` filter runs, so changing the interval/unit takes
     * effect on the next request without needing a separate reschedule.
     * The schedule is only registered when scheduled purge is enabled.
     *
     * @param array $schedules Existing cron schedules.
     * @return array
     */
    public function add_cron_intervals(array $schedules): array
    {
        $settings = $this->get_settings();

        if (empty($settings['schedule_purge_onn'])) {
            return $schedules;
        }

        $unit = (string) $settings['schedule_purge_unt'];
        $interval = max(1, (int) $settings['schedule_purge_int']);
        $seconds = $interval * (int) $unit;

        $schedules[self::SCHEDULE_KEY] = [
            'interval' => $seconds,
            'display'  => sprintf(
                /* translators: %d: interval in seconds */
                __('RunCache Scheduled Purge (every %d seconds)', 'runcache'),
                $seconds
            ),
        ];

        return $schedules;
    }

    /**
     * Ensure the scheduled purge cron event exists.
     *
     * Leaves an existing event untouched so it advances on its own recurrence
     * interval. Removed by `clear_scheduled_purge()` when toggled off.
     *
     * @param int    $interval
     * @param string $unit
     * @return void
     */
    private function setup_scheduled_purge(int $interval, string $unit): void
    {
        $valid_units = ['60', '3600', '86400'];
        $unit = in_array($unit, $valid_units, true) ? $unit : '3600';
        $interval = max(1, $interval);

        if (!wp_next_scheduled(self::SCHEDULE_HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE_KEY, self::SCHEDULE_HOOK);
        }
    }

    /**
     * Unschedule the scheduled purge cron event if present.
     *
     * @return void
     */
    private function clear_scheduled_purge(): void
    {
        $timestamp = wp_next_scheduled(self::SCHEDULE_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::SCHEDULE_HOOK);
        }
    }

    /**
     * Handle purge-by-URL requests (admin bar + Purger page)
     *
     * @return void
     */
    public function handle_purge_by_url(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'runcache'));
        }

        check_admin_referer('runcache_purge_by_url');

        // Nonce verified via check_admin_referer() above. URL is sanitized
        // inline with esc_url_raw() for PHPCS, then validated by sanitize_url().
        $raw_url = isset($_GET['url']) ? esc_url_raw(wp_unslash($_GET['url'])) : '';
        $sanitized_url = $this->validator->sanitize_url($raw_url);

        if (empty($sanitized_url)) {
            add_settings_error(
                'runcache',
                'runcache_purge_failed',
                esc_html__('Invalid URL provided for purging.', 'runcache'),
                'error'
            );
            wp_safe_redirect(wp_get_referer() ?: home_url());
            exit;
        }

        try {
            $this->purge_by_url($sanitized_url);

            add_settings_error(
                'runcache',
                'runcache_purged',
                sprintf(
                    /* translators: %s: URL that was purged */
                    __('Cache purged successfully for: %s', 'runcache'),
                    $sanitized_url
                ),
                'success'
            );
        } catch (\Exception $e) {
            add_settings_error(
                'runcache',
                'runcache_purge_failed',
                sprintf(
                    /* translators: %1$s: URL; %2$s: error message */
                    __('Failed to purge cache for %1$s: %2$s', 'runcache'),
                    $sanitized_url,
                    $e->getMessage()
                ),
                'error'
            );
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /**
     * Handle purge-all requests (admin bar + Purger page)
     *
     * @return void
     */
    public function handle_purge_all(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'runcache'));
        }

        check_admin_referer('runcache_purge_all');

        try {
            $this->purge_all();

            add_settings_error(
                'runcache',
                'runcache_purged',
                esc_html__('All cache purged successfully.', 'runcache'),
                'success'
            );
        } catch (\Exception $e) {
            add_settings_error(
                'runcache',
                'runcache_purge_failed',
                sprintf(
                    /* translators: %s: error message */
                    __('Failed to purge all cache: %s', 'runcache'),
                    $e->getMessage()
                ),
                'error'
            );
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }
}

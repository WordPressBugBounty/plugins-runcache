<?php

namespace RunCache\Core;

use RunCache\Integrations\Multisite;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Preloader class handles cache preloading functionality
 * 
 * Refactored for large sites with batched URL querying and unified background processing.
 */
class Preloader
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private File $file;
    private ?string $cache_dir = null;
    
    private array $default_settings = [
        'preload_post_onn' => false,
        'preload_speed' => 12,
        'preload_onn' => false,
        'preload_path_onn' => false,
        'preload_path_mch' => [],
        'preload_schedule_onn' => false,
        'preload_schedule_int' => 1,
        'preload_schedule_unt' => 86400,
    ];

    /** Recurrence key registered via the `cron_schedules` filter for scheduled preload. */
    private const SCHEDULE_KEY = 'runcache_preload_interval';

    /** Cron hook fired for scheduled preload. */
    private const SCHEDULE_HOOK = 'runcache_scheduled_preload';

    /** Valid units for `preload_schedule_unt` (seconds). */
    private const SCHEDULE_VALID_UNITS = ['60', '3600', '86400', '604800', '2592000', '31536000'];

    /** How long a processing batch can last before it is considered stuck (seconds). */
    private const STALE_PROCESSING_THRESHOLD = 900; // 15 minutes

    /** How long a queued preload can wait before it is considered stuck (seconds). */
    private const STALE_QUEUED_THRESHOLD = 3600; // 1 hour

    /** Final safety-net age for any queue file (seconds). */
    private const QUEUE_MAX_AGE = 86400; // 24 hours

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
        $this->file = new File();
    }

    /**
     * Initialize preloader functionality
     *
     * @return void
     */
    public function init(): void
    {
        // Register AJAX handlers
        add_action('wp_ajax_runcache_start_preload', [$this, 'ajax_start_preload']);
        add_action('wp_ajax_runcache_get_preload_status', [$this, 'ajax_get_preload_status']);
        add_action('wp_ajax_runcache_get_preloader_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_preloader_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_clear_preload_queue', [$this, 'ajax_clear_preload_queue']);

        // Add custom cron interval for preload processing
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        
        // Register cron handler for batch processing
        add_action('runcache_preload_process', [$this, 'process_batch']);
        
        // Schedule daily cleanup after WordPress has initialized translations.
        add_action('init', [$this, 'setup_scheduled_tasks'], 20);
        add_action('init', [$this, 'setup_scheduled_preload_tasks'], 20);
        add_action('runcache_cleanup_old_queues', [$this, 'cleanup_old_queues']);

        $settings = $this->get_settings();

        // Post-specific preload (calls preload_url directly, bypassing queue)
        if ($settings['preload_post_onn']) {
            add_action('runcache_after_purge_post', [$this, 'handle_post_purged'], 10, 2);
        }

        // Preload after purge all (calls start_preload)
        if ($settings['preload_onn']) {
            add_action('runcache_after_purge_all', [$this, 'handle_all_purged']);
        }

        // Register the scheduled preload callback. Event setup is deferred until
        // init so translated cron labels are not evaluated too early.
        add_action(self::SCHEDULE_HOOK, [$this, 'run_scheduled_preload']);
    }

    // =========================================================================
    // PUBLIC API - Called by external classes
    // =========================================================================

    /**
     * Start preload process (unified entry point for "preload all")
     * 
     * This is the main method called by:
     * - Admin bar "Preload All" button
     * - Plugin page "Preload All" button
     * - After purge-all hook
     * - Scheduled cron
     * 
     * @return array Queue status array
     */
    public function start_preload(): array
    {
        // Check if preload already running
        if ($this->is_queue_active()) {
            return $this->get_queue_status();
        }
        
        // Create new queue
        $queue = $this->create_queue();
        
        // Schedule recurring cron for batch processing (every minute)
        if (!wp_next_scheduled('runcache_preload_process')) {
            wp_schedule_event(time(), 'every_minute', 'runcache_preload_process');
        }
        
        $this->logger->info('Preload initialized', [
            'total_urls' => $queue['total_urls'],
        ]);
        
        return $this->get_queue_status();
    }

    /**
     * Preload a single URL
     *
     * Sends a best-effort, non-blocking warmup request. The caller does not wait
     * for the response body, and a very short timeout caps the synchronous
     * connect/send phase. This keeps admin/cron requests responsive and avoids
     * holding PHP workers while the target page is generated.
     *
     * Called by:
     * - process_batch() during background batch processing
     * - handle_post_purged() for post-specific preload
     * - Purger for single URL preload
     *
     * @param string $url URL to preload
     * @return bool True if the request was dispatched; false on invalid URL
     */
    public function preload_url(string $url): bool
    {
        $validated_url = $this->validator->sanitize_url($url);
        if ($validated_url === false) {
            return false;
        }

        wp_remote_get($validated_url, [
            'timeout' => 1,
            'blocking' => false,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent' => 'RunCache Preloader/' . (defined('RUNCACHE_VERSION') ? RUNCACHE_VERSION : '1.0') . ' (WordPress Plugin)',
            'sslverify' => wp_http_supports(['ssl']),
            'headers' => [
                'X-RunCache-Preload' => '1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => get_locale(),
                'Cache-Control' => 'no-cache',
            ],
        ]);

        return true;
    }

    /**
     * Save preloader settings
     *
     * @param array $settings Settings to save
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        $settings = wp_parse_args($settings, $this->default_settings);

        $validated = [];

        // Boolean settings
        $bool_settings = [
            'preload_post_onn',
            'preload_onn',
            'preload_path_onn',
            'preload_schedule_onn',
        ];

        foreach ($bool_settings as $key) {
            $validated[$key] = isset($settings[$key]) ? (bool)$settings[$key] : $this->default_settings[$key];
        }

        // Integer settings with validation
        $validated['preload_speed'] = isset($settings['preload_speed'])
            ? max(1, min(120, (int)$settings['preload_speed']))
            : $this->default_settings['preload_speed'];

        $validated['preload_schedule_int'] = isset($settings['preload_schedule_int'])
            ? max(1, (int)$settings['preload_schedule_int'])
            : $this->default_settings['preload_schedule_int'];

        // Unit validation
        $valid_units = ['60', '3600', '86400', '604800', '2592000', '31536000'];
        $validated['preload_schedule_unt'] = isset($settings['preload_schedule_unt']) && in_array($settings['preload_schedule_unt'], $valid_units, true)
            ? $settings['preload_schedule_unt']
            : $this->default_settings['preload_schedule_unt'];

        // URL patterns validation
        if (isset($settings['preload_path_mch'])) {
            if (is_array($settings['preload_path_mch'])) {
                // Already an array, sanitize each element
                $patterns = $settings['preload_path_mch'];
            } else {
                // String format (from textarea), convert to array
                $patterns = array_filter(array_map('trim', explode("\n", $settings['preload_path_mch'])));
            }
            $validated_patterns = [];

            foreach ($patterns as $pattern) {
                $validated_pattern = $this->validator->sanitize_url_pattern($pattern);
                if ($validated_pattern !== false) {
                    $validated_patterns[] = $validated_pattern;
                }
            }

            $validated['preload_path_mch'] = array_values($validated_patterns);
        }

        $result = $this->setting->set('preloader', $validated);

        if ($result) {
            do_action('runcache_preloader_updated', $validated);
            $this->logger->info('Settings saved successfully', ['component' => 'Preloader']);
        }

        return $result;
    }

    /**
     * Get default preloader settings
     *
     * @return array
     */
    public function get_default_settings(): array
    {
        return $this->default_settings;
    }

    // =========================================================================
    // QUEUE MANAGEMENT
    // =========================================================================

    /**
     * Check if preload is currently running
     *
     * Stale queues are automatically cleared so a new preload can be started.
     *
     * @return bool
     */
    private function is_queue_active(): bool
    {
        $queue = $this->get_queue();

        if (!$queue) {
            return false;
        }

        // A retained completed/failed queue is a marker, not an active job. Never
        // delete it here - it records last_run_at so the schedule can cool down.
        if (!in_array($queue['status'], ['queued', 'processing'], true)) {
            return false;
        }

        if ($this->is_queue_stale($queue) || $this->is_queue_too_old($queue)) {
            $this->logger->warning('Stale or too-old preload queue detected during active check; clearing', [
                'queue_id' => $queue['queue_id'] ?? 'unknown',
                'status' => $queue['status'] ?? 'unknown',
            ]);
            $this->delete_queue();
            $this->unschedule_preload_cron();
            return false;
        }

        return true;
    }

    /**
     * Get queue filename (static, no queue_id)
     *
     * @return string
     */
    private function get_queue_filename(): string
    {
        $multisite = new Multisite();
        
        if ($multisite->is_multisite()) {
            $identifier = $multisite->get_site_info()['identifier'];
            return "runcache-preload-queue-{$identifier}.json";
        }
        
        return 'runcache-preload-queue.json';
    }

    /**
     * Get current queue from file
     *
     * @return array|null
     */
    private function get_queue(): ?array
    {
        return $this->load_cache_file($this->get_queue_filename());
    }

    /**
     * Save queue to file
     *
     * @param array $queue Queue data
     * @return void
     */
    private function save_queue(array $queue): void
    {
        $this->save_cache_file($this->get_queue_filename(), $queue);
    }

    /**
     * Delete queue file
     *
     * @return void
     */
    private function delete_queue(): void
    {
        $this->delete_cache_file($this->get_queue_filename());
    }

    /**
     * Create a new preload queue
     *
     * @return array
     */
    private function create_queue(): array
    {
        $queue = [
            'queue_id' => 'q_' . time() . '_' . wp_generate_password(6, false),
            'status' => 'queued',
            'last_id' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
            'total_urls' => $this->estimate_total_urls(),
            'preload_speed' => $this->get_preload_speed(),
            'batch_start_time' => null,
            'start_time' => time(),
            'last_processed_time' => 0,
            'end_time' => null,
            'last_run_at' => null,
        ];

        $this->save_queue($queue);

        return $queue;
    }

    /**
     * Complete the queue
     *
     * Marks the queue as completed, records `last_run_at`, unschedules the
     * recurring worker cron and fires the completion hook. Unlike previously,
     * the queue file is retained on disk (status = completed) so the scheduled
     * preload can use `last_run_at` for cooldown and the frontend can stop
     * showing the in-progress state.
     *
     * @param array $queue Queue data
     * @return void
     */
    private function complete_queue(array $queue): void
    {
        $duration = time() - $queue['start_time'];

        $this->logger->info('Preload completed successfully', [
            'processed' => $queue['processed_count'],
            'failed' => $queue['failed_count'],
            'duration_seconds' => round($duration, 2),
        ]);
        
        // Update status to completed. Keep the queue file on disk (do NOT
        // delete it) so downstream consumers can inspect the completion. This
        // also lets the scheduled preload use `last_run_at` to avoid restarting
        // in a tight loop.
        $queue['status'] = 'completed';
        $queue['end_time'] = time();
        $queue['last_run_at'] = time();
        $this->save_queue($queue);
        
        // Unschedule the cron
        $this->unschedule_preload_cron();

        // Trigger completion hook for other modules (e.g., Stats)
        do_action('runcache_after_preload_complete', $queue);
    }

    /**
     * Get queue status for external use
     *
     * @return array|false
     */
    public function get_queue_status(): array|false
    {
        $queue = $this->get_queue();
        
        if (!$queue) {
            return false;
        }
        
        return [
            'queue_id' => $queue['queue_id'],
            'status' => $queue['status'],
            'processed_count' => $queue['processed_count'],
            'failed_count' => $queue['failed_count'],
            'total_urls' => $queue['total_urls'],
            'start_time' => $queue['start_time'],
            'end_time' => $queue['end_time'],
            'duration' => $queue['end_time']
                ? $queue['end_time'] - $queue['start_time']
                : time() - $queue['start_time'],
            'message' => $this->derive_status_message($queue),
        ];
    }

    /**
     * Derive human-readable message from queue data
     *
     * @param array $queue Queue data
     * @return string
     */
    private function derive_status_message(array $queue): string
    {
        $status = $queue['status'];
        
        if ($status === 'queued') {
            return sprintf('Queued ~%d URLs for preloading', $queue['total_urls']);
        }
        
        if ($status === 'processing') {
            return sprintf(
                'Dispatching %d of ~%d URLs (%d failed)',
                $queue['processed_count'],
                $queue['total_urls'],
                $queue['failed_count']
            );
        }
        
        if ($status === 'completed') {
            $duration = $queue['end_time'] - $queue['start_time'];
            return sprintf(
                'Completed: %d URLs dispatched in %s (%d failed)',
                $queue['processed_count'],
                human_time_diff(0, $duration),
                $queue['failed_count']
            );
        }
        
        if ($status === 'failed') {
            return sprintf('Failed after processing %d URLs', $queue['processed_count']);
        }
        
        return 'Unknown status';
    }

    // =========================================================================
    // BATCH PROCESSING
    // =========================================================================

    /**
     * Process a batch of URLs (called by cron)
     * Hooked to: runcache_preload_process
     *
     * @return void
     */
    public function process_batch(): void
    {
        $queue = $this->get_queue();

        if (!$queue || !in_array($queue['status'], ['queued', 'processing'], true)) {
            $this->unschedule_preload_cron();
            return;
        }

        if ($this->is_queue_stale($queue) || $this->is_queue_too_old($queue)) {
            $this->logger->warning('Stale or too-old preload queue detected during batch run; clearing', [
                'queue_id' => $queue['queue_id'] ?? 'unknown',
                'status' => $queue['status'] ?? 'unknown',
            ]);
            $this->delete_queue();
            $this->unschedule_preload_cron();
            return;
        }

        $queue['status'] = 'processing';
        if (empty($queue['batch_start_time'])) {
            $queue['batch_start_time'] = time();
        }
        $queue['last_processed_time'] = time();
        $this->save_queue($queue);

        $preload_speed = max(1, min(120, (int)($queue['preload_speed'] ?? $this->get_preload_speed())));
        $last_id = (int)($queue['last_id'] ?? 0);
        $tax_index = (int)($queue['tax_index'] ?? 0);

        $result = $this->query_batch($last_id, $preload_speed, $tax_index);
        $urls = $result['urls'];
        $next_last_id = $result['last_id'];
        $next_tax_index = $result['tax_index'];

        if (empty($urls)) {
            $this->complete_queue($queue);
            return;
        }

        // Advance cursors before warming — if the process dies, next run
        // resumes from the next cursor position rather than restarting.
        $queue['last_id'] = $next_last_id;
        $queue['tax_index'] = $next_tax_index;
        $this->save_queue($queue);

        $processed_in_batch = 0;

        foreach ($urls as $url) {
            $success = $this->preload_url($url);

            if ($success) {
                $queue['processed_count']++;
            } else {
                $queue['failed_count']++;
                $this->logger->warning('Preloading URL', [
                    'url' => $url,
                    'failed' => $queue['failed_count'],
                    'status' => 'failed',
                ]);
            }

            $queue['last_processed_time'] = time();
            $this->save_queue($queue);

            $processed_in_batch++;

            if ($processed_in_batch % 10 === 0) {
                sleep(3);
            }
        }

        $queue['batch_start_time'] = null;

        if (count($urls) < $preload_speed) {
            $this->complete_queue($queue);
            return;
        }

        $this->save_queue($queue);
    }

    /**
     * Query one batch of preload URLs.
     *
     * Order: homepage + custom paths (first batch only), then posts (by ID
     * DESC), then taxonomy term archives. Homepage/custom join just the first
     * batch; `last_id` tracks posts and `tax_index` tracks term archives, so
     * each URL is walked once.
     *
     * @param int $last_id    Post cursor: fetch posts with ID < last_id. 0 = first batch.
     * @param int $limit      Maximum number of URLs to return.
     * @param int $tax_index  Term-archive cursor: start at this offset.
     * @return array{urls: string[], last_id: int, tax_index: int}
     */
    private function query_batch(int $last_id, int $limit, int $tax_index = 0): array
    {
        $urls = [];

        // Homepage + custom paths, first batch only.
        if ($last_id === 0) {
            $urls[] = home_url('/');

            foreach ($this->get_custom_preload_urls() as $custom_url) {
                $urls[] = $custom_url;
            }
        }

        $remaining = max(0, $limit - count($urls));
        $next_last_id = 0;
        $next_tax_index = $tax_index;

        // Posts first, then term archives fill the rest of the budget.
        if ($remaining > 0) {
            $post_urls = $this->query_post_urls($last_id, $remaining);
            $next_last_id = $post_urls['last_id'];

            foreach ($post_urls['urls'] as $post_url) {
                $urls[] = $post_url;
            }

            $remaining = max(0, $remaining - count($post_urls['urls']));
        }

        if ($remaining > 0) {
            $taxonomy_urls = $this->get_taxonomy_archive_urls();
            $end = min(count($taxonomy_urls), $next_tax_index + $remaining);

            for ($i = $next_tax_index; $i < $end; $i++) {
                $urls[] = $taxonomy_urls[$i];
            }

            $next_tax_index = $end;
        }

        // Let themes/plugins add or remove URLs from this batch.
        $urls = apply_filters('runcache_preload_urls', $urls, $this->get_queue());

        return [
            'urls' => $urls,
            'last_id' => $next_last_id,
            'tax_index' => $next_tax_index,
        ];
    }

    /**
     * Fetch one batch of published post URL IDs, newest first.
     *
     * @param int $last_id Fetch posts with ID < last_id. 0 = newest.
     * @param int $limit   Maximum number of post URLs to return.
     * @return array{urls: string[], last_id: int}
     */
    private function query_post_urls(int $last_id, int $limit): array
    {
        $urls = [];
        $batch_last_id = 0;

        $post_types = get_post_types(['public' => true]);
        $post_types = array_filter($post_types, 'is_post_type_viewable');
        // Skip 'attachment' (its post_status is 'inherit', not 'publish').
        $post_types = array_filter($post_types, static fn(string $pt): bool => $pt !== 'attachment');

        $args = [
            'fields' => 'ids',
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'ID',
            'order' => 'DESC',
            'no_found_rows' => true,
        ];

        // Subsequent batches fetch only posts older than the last cursor.
        if ($last_id > 0) {
            global $wpdb;
            $cursor_filter = static function (string $where) use ($wpdb, $last_id): string {
                return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID < %d", $last_id);
            };
            add_filter('posts_where', $cursor_filter);
        }

        $query = new \WP_Query($args);

        if ($last_id > 0) {
            remove_filter('posts_where', $cursor_filter);
        }

        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $batch_last_id = (int)$post_id; // DESC: ends at the smallest ID
                $url = get_permalink($post_id);
                if ($url) {
                    $urls[] = $url;
                }
            }
        }

        wp_reset_postdata();

        return [
            'urls' => $urls,
            'last_id' => $batch_last_id,
        ];
    }

    /**
     * Collect de-duplicated term-archive URLs for category-style taxonomies.
     *
     * Only public, viewable, hierarchical taxonomies are considered, so
     * category archives (e.g. `category`, WooCommerce `product_cat`) are
     * covered while huge non-hierarchical ones (e.g. `post_tag`) and internal
     * taxonomies (`nav_menu`, `wp_theme`) are skipped.
     *
     * @return array<int, string> Sanitized, unique taxonomy archive URLs.
     */
    private function get_taxonomy_archive_urls(): array
    {
        $taxonomies = get_taxonomies(['public' => true]);
        $taxonomies = array_filter($taxonomies, 'is_taxonomy_viewable');
        $taxonomies = array_filter($taxonomies, static fn(string $tax): bool => is_taxonomy_hierarchical($tax));

        // Skip non-front-end/internal taxonomies.
        $skip = ['nav_menu', 'link_category', 'wp_theme'];
        $taxonomies = array_diff($taxonomies, $skip);

        if (empty($taxonomies)) {
            return [];
        }

        // Non-empty terms only, so empty archives are never warmed.
        $term_ids = get_terms([
            'taxonomy' => $taxonomies,
            'hide_empty' => true,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (is_wp_error($term_ids)) {
            $this->logger->warning('Unable to fetch taxonomy terms for preload', [
                'error' => $term_ids->get_error_message(),
            ]);
            return [];
        }

        $urls = [];
        $homepage_url = home_url('/');

        foreach ($term_ids as $term_id) {
            $term_link = get_term_link((int) $term_id);
            if (is_wp_error($term_link)) {
                continue;
            }

            $sanitized = $this->validator->sanitize_url($term_link);
            if ($sanitized === false || $sanitized === '') {
                continue;
            }

            // Skip term archives that resolve to the homepage.
            if ($sanitized === $homepage_url) {
                continue;
            }

            $urls[] = $sanitized;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Estimate total URLs for progress display.
     *
     * Combines the homepage, custom preload paths, published
     * posts/pages/CPTs, and preloadable taxonomy term archives.
     *
     * @return int
     */
    private function estimate_total_urls(): int
    {
        $total = 1; // Homepage
        $total += count($this->get_custom_preload_urls());

        $post_types = get_post_types(['public' => true]);
        $post_types = array_filter($post_types, 'is_post_type_viewable');
        // Exclude 'attachment' — its post_status is 'inherit', not 'publish'.
        $post_types = array_filter($post_types, static fn(string $pt): bool => $pt !== 'attachment');

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type);
            $total += $counts->publish ?? 0;
        }

        $total += count($this->get_taxonomy_archive_urls());

        return $total;
    }

    /**
     * Get validated custom preload URLs from settings
     *
     * Converts relative paths to full URLs via home_url(), validates them,
     * and removes duplicates.
     *
     * @return array Array of sanitized URL strings
     */
    private function get_custom_preload_urls(): array
    {
        $settings = $this->get_settings();

        if (empty($settings['preload_path_onn']) || empty($settings['preload_path_mch'])) {
            return [];
        }

        $paths = $settings['preload_path_mch'];
        if (!is_array($paths)) {
            $paths = array_filter(array_map('trim', explode("\n", $paths)));
        }

        $urls = [];
        $homepage_url = home_url('/');

        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            // If the user entered a full URL, use it as-is; otherwise make it
            // relative to the site home URL.
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                $url = $path;
            } else {
                $url = home_url($path);
            }

            $validated_url = $this->validator->sanitize_url($url);
            if ($validated_url === false) {
                continue;
            }

            // Avoid preloading the homepage twice if the user entered "/".
            if ($validated_url === $homepage_url) {
                continue;
            }

            if (!in_array($validated_url, $urls, true)) {
                $urls[] = $validated_url;
            }
        }

        return array_values($urls);
    }

    /**
     * Get preload speed from settings, clamped to a safe range.
     *
     * @return int
     */
    private function get_preload_speed(): int
    {
        $settings = $this->get_settings();
        return max(1, min(120, (int)($settings['preload_speed'] ?? 12)));
    }

    /**
     * Check whether a queue appears stuck and should be cleared.
     *
     * - processing + batch_start_time older than 15 minutes -> stuck mid-batch.
     * - queued + start_time older than 1 hour -> cron never picked it up.
     *
     * @param array $queue Queue data
     * @return bool
     */
    private function is_queue_stale(array $queue): bool
    {
        $now = time();

        if ($queue['status'] === 'processing' && !empty($queue['batch_start_time'])) {
            return ($now - (int)$queue['batch_start_time']) > self::STALE_PROCESSING_THRESHOLD;
        }

        if ($queue['status'] === 'queued') {
            $start_time = !empty($queue['start_time']) ? (int)$queue['start_time'] : $now;
            return ($now - $start_time) > self::STALE_QUEUED_THRESHOLD;
        }

        return false;
    }

    /**
     * Check whether a queue file is older than the absolute maximum age.
     *
     * @param array $queue Queue data
     * @return bool
     */
    private function is_queue_too_old(array $queue): bool
    {
        $now = time();
        $start_time = !empty($queue['start_time']) ? (int)$queue['start_time'] : $now;

        return ($now - $start_time) > self::QUEUE_MAX_AGE;
    }

    // =========================================================================
    // HOOK HANDLERS
    // =========================================================================

    /**
     * Handle all cache purged event
     *
     * @return void
     */
    public function handle_all_purged(): void
    {
        $this->start_preload();
    }

    /**
     * Handle post purged event (post-specific, bypasses queue)
     *
     * @param int $post_id Post ID
     * @param string|array $urls URLs associated with the post
     * @return void
     */
    public function handle_post_purged(int $post_id, string|array $urls): void
    {
        $urls = $this->validator->normalize_url_list($urls);

        if (empty($urls)) {
            return;
        }

        foreach ($urls as $url) {
            $this->preload_url($url);
        }
    }

    /**
     * Run scheduled preload (called by runcache_scheduled_preload cron)
     *
     * Enforces a cooldown: if a preload already completed within the current
     * schedule interval (see `last_run_at` on the retained queue file), we skip
     * this fire so a recurring cron does not restart preloading in a tight loop.
     *
     * @return void
     */
    public function run_scheduled_preload(): void
    {
        $queue = $this->get_queue();
        $last_run = (int)(($queue['last_run_at'] ?? 0) ?: 0);
        $interval = $this->get_schedule_interval();

        // If it completed too recently, do nothing this pass.
        if ($last_run > 0 && $interval > 0 && (time() - $last_run) < $interval) {
            return;
        }

        $this->start_preload();
    }

    // =========================================================================
    // SCHEDULED PRELOAD SETUP
    // =========================================================================

    /**
     * Setup scheduled preload event.
     *
     * Leave an existing event untouched so it advances on its own interval.
     * Only schedule when missing; clear when the feature is toggled off.
     *
     * @return void
     */
    private function setup_scheduled_preload(): void
    {
        if (!wp_next_scheduled(self::SCHEDULE_HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE_KEY, self::SCHEDULE_HOOK);
        }
    }

    /**
     * Clear scheduled preload event
     *
     * @return void
     */
    private function clear_scheduled_preload(): void
    {
        $timestamp = wp_next_scheduled(self::SCHEDULE_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::SCHEDULE_HOOK);
        }
    }

    /**
     * Unschedule preload process cron
     *
     * @return void
     */
    private function unschedule_preload_cron(): void
    {
        $timestamp = wp_next_scheduled('runcache_preload_process');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'runcache_preload_process');
        }
    }

    /**
     * Set up the optional scheduled preload event after WordPress initialization.
     *
     * @return void
     */
    public function setup_scheduled_preload_tasks(): void
    {
        $settings = $this->get_settings();
        if ($settings['preload_schedule_onn']) {
            $this->setup_scheduled_preload();
        } else {
            $this->clear_scheduled_preload();
        }
    }

    /**
     * Ensure the daily preload queue cleanup event exists.
     *
     * @return void
     */
    public function setup_scheduled_tasks(): void
    {
        if (!wp_next_scheduled('runcache_cleanup_old_queues')) {
            wp_schedule_event(time(), 'daily', 'runcache_cleanup_old_queues');
        }
    }

    /**
     * Add custom cron intervals
     *
     * @param array $schedules Existing cron schedules
     * @return array
     */
    public function add_cron_intervals(array $schedules): array
    {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'runcache'),
        ];

        // Register a dynamic interval for scheduled preload based on the
        // current settings. The interval is recomputed every time the filter
        // runs, so changes take effect on the next request.
        $settings = $this->get_settings();
        if (!empty($settings['preload_schedule_onn'])) {
            $seconds = $this->get_schedule_interval();

            $schedules[self::SCHEDULE_KEY] = [
                'interval' => $seconds,
                'display'  => sprintf(
                    /* translators: %d: interval in seconds */
                    __('RunCache Scheduled Preload (every %d seconds)', 'runcache'),
                    $seconds
                ),
            ];
        }
        
        return $schedules;
    }

    /**
     * Compute the current scheduled-preload interval in seconds.
     *
     * Mirrors the recurrence registration in add_cron_intervals() so the
     * cooldown check in run_scheduled_preload() always matches the schedule.
     *
     * @return int Interval in seconds (>= 1)
     */
    private function get_schedule_interval(): int
    {
        $settings = $this->get_settings();

        $unit = isset($settings['preload_schedule_unt']) ? (string) $settings['preload_schedule_unt'] : '86400';
        $unit = in_array($unit, self::SCHEDULE_VALID_UNITS, true) ? $unit : '86400';

        $interval = isset($settings['preload_schedule_int']) ? max(1, (int) $settings['preload_schedule_int']) : 1;

        return $interval * (int) $unit;
    }

    // =========================================================================
    // CLEANUP
    // =========================================================================

    /**
     * Cleanup old queue files
     *
     * Removes completed/failed queues older than max_age, plus any stale or
     * ancient queue as a safety net.
     *
     * @param int $max_age Maximum age in seconds (default 24 hours)
     * @return void
     */
    public function cleanup_old_queues(int $max_age = 86400): void
    {
        $queue = $this->get_queue();

        if (!$queue) {
            return;
        }

        $current_time = time();

        // A retained completed queue is an intentional marker (records
        // last_run_at for schedule cooldown) and is overwritten on the next
        // preload, so never delete it here.
        if ($queue['status'] === 'completed') {
            return;
        }

        // Stale or too-old active queues should never survive.
        if ($this->is_queue_stale($queue) || $this->is_queue_too_old($queue)) {
            $this->delete_queue();
            $this->unschedule_preload_cron();
            $this->logger->warning('Cleaned up stale preload queue', [
                'queue_id' => $queue['queue_id'] ?? 'unknown',
                'status' => $queue['status'] ?? 'unknown',
            ]);
            return;
        }

        // Cleanup failed (or other leftover) queues older than max_age.
        if ($queue['status'] === 'failed' &&
            isset($queue['end_time']) &&
            ($current_time - $queue['end_time']) > $max_age) {

            $this->delete_queue();
            $this->logger->info('Cleaned up old preload queue', ['queue_id' => $queue['queue_id']]);
        }
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX handler for starting preload
     *
     * @return void
     */
    public function ajax_start_preload(): void
    {
        $this->validator->validate_ajax_request();
        
        try {
            $status = $this->start_preload();
            
            if ($status) {
                wp_send_json_success([
                    'message' => 'Preload started successfully',
                    'queue_id' => $status['queue_id'],
                    'total_urls' => $status['total_urls'],
                    'status' => $status['status'],
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to start preload']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to start preload', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to start preload. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for getting preload status
     *
     * @return void
     */
    public function ajax_get_preload_status(): void
    {
        $this->validator->validate_ajax_request();
        
        try {
            $status = $this->get_queue_status();
            
            if ($status) {
                wp_send_json_success([
                    'status' => $status['status'],
                    'message' => $status['message'],
                    'queue_id' => $status['queue_id'],
                    'processed_count' => $status['processed_count'],
                    'failed_count' => $status['failed_count'],
                    'total_urls' => $status['total_urls'],
                ]);
            } else {
                wp_send_json_success([
                    'status' => false,
                    'message' => 'No preload in progress',
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to get preload status', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to get preload status. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for clearing preload queue
     *
     * @return void
     */
    public function ajax_clear_preload_queue(): void
    {
        $this->validator->validate_ajax_request();
        
        try {
            // Delete queue immediately
            $this->delete_queue();
            $this->unschedule_preload_cron();
            
            wp_send_json_success(['message' => 'Preload queue cleared successfully']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear preload queue', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => __('Unable to clear the preload queue. Please try again.', 'runcache')]);
        }
    }

    /**
     * AJAX handler for getting preloader settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();

        $settings = $this->get_settings();

        // Convert arrays to newline-separated strings for frontend textareas
        foreach ($settings as $key => $value) {
            if (str_ends_with($key, '_mch') && is_array($value)) {
                $settings[$key] = implode("\n", $value);
            }
        }

        wp_send_json_success($settings);
    }

    /**
     * AJAX handler for saving preloader settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();
        $settings = $this->validator->validate_ajax_settings();

        $result = $this->save_settings($settings);

        if ($result) {
            $settings = $this->get_settings();

            // Convert arrays to newline-separated strings for frontend textareas
            foreach ($settings as $key => $value) {
                if (str_ends_with($key, '_mch') && is_array($value)) {
                    $settings[$key] = implode("\n", $value);
                }
            }

            wp_send_json_success($settings);
        } else {
            wp_send_json_error(['message' => 'Failed to save settings']);
        }
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Get preloader settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $saved_settings = $this->setting->get('preloader');
        return wp_parse_args($saved_settings, $this->default_settings);
    }

    // =========================================================================
    // FILE HELPERS
    // =========================================================================

    /**
     * Get cache directory path
     *
     * @return string
     */
    private function get_cache_directory(): string
    {
        if ($this->cache_dir === null) {
            $this->cache_dir = (new PageCache())->get_cache_directory();
        }
        
        return $this->cache_dir;
    }

    /**
     * Save cache file to disk
     *
     * @param string $filename Filename
     * @param array $data Data to save
     * @return void
     */
    private function save_cache_file(string $filename, array $data): void
    {
        $filepath = $this->get_cache_directory() . $filename;
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $this->file->write($filepath, $json);
    }

    /**
     * Load cache file from disk
     *
     * @param string $filename Filename
     * @return array|null
     */
    private function load_cache_file(string $filename): ?array
    {
        $filepath = $this->get_cache_directory() . $filename;
        
        if (!$this->file->exists($filepath)) {
            return null;
        }
        
        $json = $this->file->read($filepath);
        
        if ($json === false) {
            return null;
        }
        
        $data = json_decode($json, true);
        
        return $data ?: null;
    }

    /**
     * Delete cache file
     *
     * @param string $filename Filename
     * @return void
     */
    private function delete_cache_file(string $filename): void
    {
        $filepath = $this->get_cache_directory() . $filename;
        if ($this->file->exists($filepath)) {
            $this->file->delete($filepath);
        }
    }
}

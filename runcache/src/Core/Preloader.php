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
 * Handles cache preloading. Builds URL batches and warms them in the
 * background.
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
        'preload_exclude_cpt_onn' => false,
        'preload_exclude_cpt_mch' => [],
        'preload_schedule_onn' => false,
        'preload_schedule_int' => 1,
        'preload_schedule_unt' => 86400,
    ];

    /** Scheduler key for the scheduled preload. The cron_schedules filter registers it. */
    private const SCHEDULE_KEY = 'runcache_preload_interval';

    /** Cron hook for scheduled preload. */
    private const SCHEDULE_HOOK = 'runcache_scheduled_preload';

    /** Valid units for `preload_schedule_unt` (seconds). */
    private const SCHEDULE_VALID_UNITS = ['60', '3600', '86400', '604800', '2592000', '31536000'];

    /** How long a processing batch can run before the class flags it as stuck (seconds). */
    private const STALE_PROCESSING_THRESHOLD = 900; // 15 minutes

    /** How long a queued preload can wait before the class flags it as stuck (seconds). */
    private const STALE_QUEUED_THRESHOLD = 3600; // 1 hour

    /** Maximum age for any queue file. Older files are always cleared. */
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

        // Re-arm a lost preload worker event on the hourly maintenance pass
        // so active queues complete even with no dashboard poll running.
        add_action('runcache_scheduled_cleanup', [$this, 'heal_worker_event']);

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
     * Start the preload process. All preload-all actions call this method.
     *
     * @return array Queue status array
     */
    public function start_preload(): array
    {
        // Check if a preload is already running.
        if ($this->is_queue_active()) {
            // Re-arm the worker event. A queue without its worker event looks
            // active but never runs.
            $this->ensure_preload_worker_scheduled();

            return $this->get_queue_status();
        }
        
        // Create new queue
        $queue = $this->create_queue();
        
        // Schedule recurring cron for batch processing (every minute)
        $this->ensure_preload_worker_scheduled();
        
        $this->logger->info('Preload initialized', [
            'total_urls' => $queue['total_urls'],
        ]);
        
        return $this->get_queue_status();
    }

    /**
     * Preload one URL with a short non-blocking request. The caller does
     * not wait for the page response.
     *
     * @param string $url URL to preload
     * @return bool True if the request was dispatched, false on invalid URL
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
            'preload_exclude_cpt_onn',
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
                // A settings save is an actor action, so absolute URLs must
                // stay inside the sites this actor manages.
                if (is_string($pattern) && str_contains($pattern, '://') && !$this->validator->url_in_actor_scope($pattern)) {
                    $this->logger->warning('Preload path rejected: URL outside the actor site scope', [
                        'component' => 'Preloader',
                        'pattern' => $pattern,
                        'user_id' => get_current_user_id(),
                    ]);
                    continue;
                }

                $validated_pattern = $this->validator->sanitize_url_pattern($pattern);
                if ($validated_pattern !== false) {
                    $validated_patterns[] = $validated_pattern;
                }
            }

            $validated['preload_path_mch'] = array_values($validated_patterns);
        }

        // Excluded post type slugs validation. sanitize_key() keeps only
        // lowercase letters, digits, dashes, and underscores per line.
        if (isset($settings['preload_exclude_cpt_mch'])) {
            $slugs = $settings['preload_exclude_cpt_mch'];

            if (!is_array($slugs)) {
                // String format (from textarea), convert to array
                $slugs = array_filter(array_map('trim', explode("\n", (string) $slugs)));
            }

            $validated_slugs = [];

            foreach ($slugs as $slug) {
                if (!is_scalar($slug)) {
                    continue;
                }

                $slug = sanitize_key(trim((string) $slug));

                if ($slug !== '') {
                    $validated_slugs[] = $slug;
                }
            }

            // A hard cap keeps the option size bounded.
            $validated_slugs = array_slice(array_unique($validated_slugs), 0, 100);

            $validated['preload_exclude_cpt_mch'] = array_values($validated_slugs);
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
     * Check if a preload queue is active. The class clears stale queues
     * here, so a new preload can start.
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
     * Save the queue with a queue-ID guard and an atomic write.
     * The class drops a save when the file is gone or holds another ID.
     *
     * @param array $queue Queue data
     * @param bool $force Bypass the queue-ID guard for a new queue
     * @return bool True when the queue state was persisted
     */
    private function save_queue(array $queue, bool $force = false): bool
    {
        if (!$force) {
            $current = $this->get_queue();
            $current_id = is_array($current) ? ($current['queue_id'] ?? null) : null;
            $queue_id = $queue['queue_id'] ?? null;

            if ($current_id !== $queue_id) {
                $this->logger->warning('Dropped stale queue save; the queue was cleared or replaced', [
                    'queue_id' => $queue_id,
                    'on_disk_queue_id' => $current_id,
                ]);

                return false;
            }
        }

        $json = json_encode($queue, JSON_PRETTY_PRINT);

        if ($json === false || $json === '') {
            $this->logger->error('Failed to encode preload queue as JSON', [
                'queue_id' => $queue['queue_id'] ?? null,
            ]);

            return false;
        }

        $filepath = $this->get_cache_directory() . $this->get_queue_filename();

        if (!$this->file->atomic_write($filepath, $json)) {
            $this->logger->error('Failed to write preload queue file', [
                'queue_id' => $queue['queue_id'] ?? null,
                'path' => $filepath,
            ]);

            return false;
        }

        return true;
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
            'custom_index' => 0,
            'tax_index' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'total_urls' => $this->estimate_total_urls(),
            'preload_speed' => $this->get_preload_speed(),
            'batch_start_time' => null,
            'start_time' => time(),
            'last_processed_time' => 0,
            'end_time' => null,
            'last_run_at' => null,
        ];

        // New queue: bypass the guard to replace a cleared or completed file.
        $this->save_queue($queue, true);

        return $queue;
    }

    /**
     * Mark the queue as completed and keep the file. The file records
     * last_run_at for the schedule cooldown.
     *
     * @param array $queue Queue data
     * @return void
     */
    private function complete_queue(array $queue): void
    {
        $duration = time() - $queue['start_time'];

        // Keep the completed queue file on disk. It records last_run_at for
        // the schedule cooldown.
        $queue['status'] = 'completed';
        $queue['end_time'] = time();
        $queue['last_run_at'] = time();

        if (!$this->save_queue($queue)) {
            // A dropped save means this queue copy is no longer the current queue.
            // Do not log completion and do not unschedule the worker event.
            return;
        }

        $this->logger->info('Preload completed successfully', [
            'processed' => $queue['processed_count'],
            'failed' => $queue['failed_count'],
            'duration_seconds' => round($duration, 2),
        ]);

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

        // Automatic recovery: the dashboard polls this method. Re-arm a lost
        // worker event, so an active queue resumes with no user action.
        $this->auto_heal_worker_event($queue);
        
        return [
            'queue_id' => $queue['queue_id'],
            'status' => $queue['status'],
            'processed_count' => $queue['processed_count'],
            'failed_count' => $queue['failed_count'],
            'skipped_count' => (int)($queue['skipped_count'] ?? 0),
            'total_urls' => $this->effective_total_urls($queue),
            'start_time' => $queue['start_time'],
            'end_time' => $queue['end_time'],
            'duration' => $queue['end_time']
                ? $queue['end_time'] - $queue['start_time']
                : time() - $queue['start_time'],
            'message' => $this->derive_status_message($queue),
        ];
    }

    /**
     * Effective total URL count for progress reporting. The count
     * subtracts filter skips and applies a safe minimum.
     *
     * @param array $queue Queue data
     * @return int
     */
    private function effective_total_urls(array $queue): int
    {
        $skipped = (int)($queue['skipped_count'] ?? 0);
        $dispatched = (int)$queue['processed_count'] + (int)$queue['failed_count'];

        return max(1, (int)$queue['total_urls'] - $skipped, $dispatched);
    }

    /**
     * Message suffix for skipped URLs (rule exclusions or filter removals)
     *
     * @param array $queue Queue data
     * @return string
     */
    private function skipped_suffix(array $queue): string
    {
        $skipped = (int)($queue['skipped_count'] ?? 0);

        return $skipped > 0 ? sprintf(', %d skipped', $skipped) : '';
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
            return sprintf(
                'Queued ~%d URLs for preloading',
                $this->effective_total_urls($queue)
            );
        }
        
        if ($status === 'processing') {
            return sprintf(
                'Dispatching %d of ~%d URLs (%d failed%s)',
                $queue['processed_count'],
                $this->effective_total_urls($queue),
                $queue['failed_count'],
                $this->skipped_suffix($queue)
            );
        }
        
        if ($status === 'completed') {
            // Floor the duration at one second. human_time_diff(0, 0) makes
            // WordPress report "57 years" for instant runs.
            $duration = max(1, (int)($queue['end_time'] - $queue['start_time']));
            return sprintf(
                'Completed: %d URLs dispatched in %s (%d failed%s)',
                $queue['processed_count'],
                human_time_diff(0, $duration),
                $queue['failed_count'],
                $this->skipped_suffix($queue)
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
        if (!$this->save_queue($queue)) {
            // The queue was cleared or replaced mid-run, or the queue file
            // is not writable. Do not warm URLs for a dead generation.
            return;
        }

        $preload_speed = max(1, min(120, (int)($queue['preload_speed'] ?? $this->get_preload_speed())));
        $last_id = (int)($queue['last_id'] ?? 0);
        $tax_index = (int)($queue['tax_index'] ?? 0);
        $custom_index = (int)($queue['custom_index'] ?? 0);

        $result = $this->query_batch($last_id, $preload_speed, $tax_index, $custom_index);
        $urls = $result['urls'];
        $next_last_id = $result['last_id'];
        $next_tax_index = $result['tax_index'];
        $next_custom_index = $result['custom_index'];
        $done = (bool)$result['done'];

        // Add URLs removed by the runcache_preload_urls filter to
        // skipped_count. Legacy queue files start from zero.
        $queue['skipped_count'] = (int)($queue['skipped_count'] ?? 0) + (int)($result['skipped'] ?? 0);

        if (empty($urls)) {
            if ($done) {
                // Natural end: every phase is exhausted (posts, customs,
                // term archives).
                $this->complete_queue($queue);
                return;
            }

            // The filter emptied this batch, but phases are still pending.
            // Advance the cursors so the next pass continues.
            $queue['last_id'] = $next_last_id;
            $queue['tax_index'] = $next_tax_index;
            $queue['custom_index'] = $next_custom_index;
            $queue['batch_start_time'] = null;
            $this->save_queue($queue);
            return;
        }

        // Advance cursors before warming. If the process stops, the next pass
        // resumes from the new position, not from the start.
        $queue['last_id'] = $next_last_id;
        $queue['tax_index'] = $next_tax_index;
        $queue['custom_index'] = $next_custom_index;
        if (!$this->save_queue($queue)) {
            return;
        }

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
            if (!$this->save_queue($queue)) {
                // Queue cleared/replaced or unwritable mid-batch: stop
                // warming the rest of this batch.
                break;
            }

            $processed_in_batch++;

            if ($processed_in_batch % 10 === 0) {
                sleep(3);
            }
        }

        $queue['batch_start_time'] = null;

        // Complete on phase exhaustion, not batch size. A short batch must not
        // end the run while later phases are still pending.
        if ($done) {
            $this->complete_queue($queue);
            return;
        }

        $this->save_queue($queue);
    }

    /**
     * Query one batch of preload URLs. Phase order: homepage plus custom
     * paths, then posts, then term archives. `done` marks full exhaustion.
     * `skipped` counts rule-excluded URLs plus filter removals.
     *
     * @param int $last_id     Post cursor: fetch posts with ID < last_id. 0 = first batch.
     * @param int $limit       Maximum number of URLs to return.
     * @param int $tax_index   Term-archive cursor: start at this offset.
     * @param int $custom_index Custom-path cursor: start at this offset.
     * @return array{urls: string[], last_id: int, tax_index: int, custom_index: int, done: bool, skipped: int}
     */
    private function query_batch(int $last_id, int $limit, int $tax_index = 0, int $custom_index = 0): array
    {
        $urls = [];

        $remaining = max(0, $limit);
        $next_last_id = 0;
        $next_tax_index = max(0, $tax_index);
        $next_custom_index = max(0, $custom_index);

        $custom_done = true;
        $posts_done = false;
        $tax_done = false;

        // Old queues have no custom_index and already dispatched the custom
        // paths once. Skip this phase when their post cursor moved.
        $is_legacy_queue = ($custom_index === 0 && $last_id > 0);

        // Phase 1: homepage + custom paths, sliced to the batch budget.
        if (!$is_legacy_queue) {
            $custom_urls = array_merge([home_url('/')], $this->get_custom_preload_urls());

            $custom_end = min(count($custom_urls), $next_custom_index + $remaining);
            for ($i = $next_custom_index; $i < $custom_end; $i++) {
                $urls[] = $custom_urls[$i];
            }

            $next_custom_index = $custom_end;
            $remaining = max(0, $remaining - count($urls));
            $custom_done = $next_custom_index >= count($custom_urls);
        }

        // Phase 2: posts fill the rest of the budget.
        if ($remaining > 0) {
            $post_urls = $this->query_post_urls($last_id, $remaining);
            $next_last_id = $post_urls['last_id'];

            foreach ($post_urls['urls'] as $post_url) {
                $urls[] = $post_url;
            }

            $requested = $remaining;
            $remaining = max(0, $remaining - count($post_urls['urls']));
            // Fewer posts than requested means the post cursor is exhausted.
            $posts_done = count($post_urls['urls']) < $requested;
        }

        // Phase 3: term archives fill whatever budget is left.
        if ($remaining > 0) {
            $taxonomy_urls = $this->get_taxonomy_archive_urls();
            $tax_end = min(count($taxonomy_urls), $next_tax_index + $remaining);

            for ($i = $next_tax_index; $i < $tax_end; $i++) {
                $urls[] = $taxonomy_urls[$i];
            }

            $next_tax_index = $tax_end;
            $tax_done = $tax_end >= count($taxonomy_urls);
        }

        // Drop URLs excluded by cache rules. The cache would never store
        // them, so preloading them is wasted work.
        $excluded_count = 0;
        $exclude_patterns = $this->get_excluded_url_patterns();

        if ($exclude_patterns !== []) {
            $kept = [];

            foreach ($urls as $url) {
                if ($this->is_excluded_url($url, $exclude_patterns)) {
                    $excluded_count++;
                    continue;
                }

                $kept[] = $url;
            }

            $urls = $kept;
        }

        // Let themes/plugins add or remove URLs from this batch.
        $raw_count = count($urls);
        $urls = apply_filters('runcache_preload_urls', $urls, $this->get_queue());

        // Rule-excluded URLs plus URLs the filter removed both count as
        // skipped, so the queue never loses them.
        $skipped = $excluded_count + max(0, $raw_count - count($urls));

        return [
            'urls' => $urls,
            'last_id' => $next_last_id,
            'tax_index' => $next_tax_index,
            'custom_index' => $next_custom_index,
            'done' => $custom_done && $posts_done && $tax_done,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get the excluded post type slugs for the preload all queue. Empty
     * array when the feature is off. Only registered post types count.
     *
     * @return array<int, string>
     */
    private function get_excluded_post_types(): array
    {
        $settings = $this->get_settings();

        if (empty($settings['preload_exclude_cpt_onn'])) {
            return [];
        }

        $slugs = $settings['preload_exclude_cpt_mch'] ?? [];

        if (!is_array($slugs)) {
            // String format (from textarea or import), convert to array
            $slugs = array_filter(array_map('trim', explode("\n", (string) $slugs)));
        }

        $excluded = [];

        foreach ($slugs as $slug) {
            if (!is_scalar($slug)) {
                continue;
            }

            $slug = sanitize_key(trim((string) $slug));

            if ($slug !== '' && post_type_exists($slug)) {
                $excluded[] = $slug;
            }
        }

        return array_values(array_unique($excluded));
    }

    /**
     * Get the post types the preload all queue warms: public, viewable,
     * not attachment, and not excluded by settings.
     *
     * @return array<int, string> Static array of post type names
     */
    private function get_preload_post_types(): array
    {
        $post_types = get_post_types(['public' => true]);
        $post_types = array_filter($post_types, 'is_post_type_viewable');
        // Skip 'attachment' (its post_status is 'inherit', not 'publish').
        $post_types = array_filter($post_types, static fn(string $pt): bool => $pt !== 'attachment');

        $excluded = $this->get_excluded_post_types();

        if ($excluded !== []) {
            $post_types = array_diff($post_types, $excluded);
        }

        return array_values($post_types);
    }

    /**
     * Fetch one batch of published post permalinks, newest first. An
     * exhausted cursor keeps its value and never resets to 0.
     *
     * @param int $last_id Fetch posts with ID < last_id. 0 = newest.
     * @param int $limit   Maximum number of post URLs to return.
     * @return array{urls: string[], last_id: int}
     */
    private function query_post_urls(int $last_id, int $limit): array
    {
        $urls = [];
        $batch_last_id = $last_id;

        $post_types = $this->get_preload_post_types();

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
                $batch_last_id = (int)$post_id; // DESC order: ends at the smallest post ID.
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
     * Collect unique term-archive URLs. Uses public, viewable, and
     * hierarchical taxonomies, so it covers category-style archives only.
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
        $exclude_patterns = $this->get_excluded_url_patterns();

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

            // Cache rules exclude this archive; the cache would never store it.
            if ($this->is_excluded_url($sanitized, $exclude_patterns)) {
                continue;
            }

            $urls[] = $sanitized;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Estimate the total URL count for progress display. Combines the
     * homepage, custom preload paths, posts, pages, and term archives.
     *
     * @return int
     */
    private function estimate_total_urls(): int
    {
        $total = 1; // Homepage
        $total += count($this->get_custom_preload_urls());

        $post_types = $this->get_preload_post_types();

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type);
            $total += $counts->publish ?? 0;
        }

        $total += count($this->get_taxonomy_archive_urls());

        return $total;
    }

    /**
     * Get validated custom preload paths as full URLs. Drop entries that
     * the later queue phases cover, so each URL is preloaded one time.
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

        // Fetch term archive URLs once for the loop below.
        $term_archives = array_flip(
            array_map('untrailingslashit', $this->get_taxonomy_archive_urls())
        );

        $urls = [];
        $homepage_url = home_url('/');
        $exclude_patterns = $this->get_excluded_url_patterns();

        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            // If the user entered a full URL, use it as-is; otherwise make it
            // relative to the site home URL.
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                $url = $path;

                // Cron replays a list an actor saved, so the scope check runs
                // only for a logged-in actor.
                if (get_current_user_id() > 0 && !$this->validator->url_in_actor_scope($url)) {
                    $this->logger->warning('Preload URL skipped: outside the actor site scope', [
                        'component' => 'Preloader',
                        'url' => $url,
                        'user_id' => get_current_user_id(),
                    ]);
                    continue;
                }
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

            // Cache rules exclude this path; the cache would never store it.
            if ($this->is_excluded_url($validated_url, $exclude_patterns)) {
                continue;
            }

            // The post phase warms this URL. Skip it.
            if ($this->is_canonical_post_permalink($validated_url)) {
                continue;
            }

            // The term phase warms this URL. Skip it.
            if (isset($term_archives[untrailingslashit($validated_url)])) {
                continue;
            }

            if (!in_array($validated_url, $urls, true)) {
                $urls[] = $validated_url;
            }
        }

        return array_values($urls);
    }

    /**
     * Check if the URL is a canonical post permalink. Return true only
     * when the post preload phase warms the same URL.
     *
     * @param string $url Validated full URL
     * @return bool True when the post preload phase warms this URL
     */
    private function is_canonical_post_permalink(string $url): bool
    {
        $post_id = url_to_postid($url);

        // No post belongs to this URL.
        if ($post_id === 0) {
            return false;
        }

        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return false;
        }

        // The post phase skips attachments. Keep these URLs.
        if ($post->post_type === 'attachment') {
            return false;
        }

        // Use the effective type list from the post preload query. Slugs
        // excluded by settings no longer match, so custom paths still work.
        if (!in_array($post->post_type, $this->get_preload_post_types(), true)) {
            return false;
        }

        $permalink = get_permalink($post_id);

        if ($permalink === false) {
            return false;
        }

        // Compare without trailing slashes. WordPress sends both slash
        // variants to one cache entry.
        return untrailingslashit($permalink) === untrailingslashit($url);
    }

    /**
     * Get the preload speed from settings, capped to the range 1 to 120.
     *
     * @return int
     */
    private function get_preload_speed(): int
    {
        $settings = $this->get_settings();
        return max(1, min(120, (int)($settings['preload_speed'] ?? 12)));
    }

    /**
     * Get the exclude URL patterns from the cache rules. Empty array when
     * the rule is off, so callers skip all matching work.
     *
     * @return array<int, string>
     */
    private function get_excluded_url_patterns(): array
    {
        $rules = (new Rules($this->logger, $this->validator, $this->setting))->get_settings();

        if (empty($rules['exclude_url_onn']) || empty($rules['exclude_url_mch'])) {
            return [];
        }

        $patterns = $rules['exclude_url_mch'];

        if (is_string($patterns)) {
            $patterns = explode("\n", $patterns);
        }

        // A rule filter may inject junk values. Keep scalar values only,
        // so the cast cannot warn or build a fake "Array" pattern.
        $patterns = array_filter((array) $patterns, 'is_scalar');

        return array_values(array_filter(array_map(static fn($p): string => trim((string) $p), $patterns), static fn(string $p): bool => $p !== ''));
    }

    /**
     * Check one URL (path plus query) with the cache drop-in pattern rules:
     * % is a * wildcard, no wildcard means substring, match ignores case.
     *
     * @param string $url      Full URL
     * @param array  $patterns Exclude URL patterns
     * @return bool
     */
    private function is_excluded_url(string $url, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $uri = (!is_string($path) || $path === '') ? '/' : $path;

        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $uri .= '?' . $query;
        }

        $uri_lower = strtolower($uri);

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }

            $normalized = str_replace('%', '*', $pattern);

            if (fnmatch($normalized, $uri, FNM_CASEFOLD)) {
                return true;
            }

            if (!str_contains($normalized, '*') && str_contains($uri_lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a queue is stuck. Rules: a batch older than 15
     * minutes; no processing progress for 15 minutes; queued over 1 hour.
     *
     * @param array $queue Queue data
     * @return bool
     */
    private function is_queue_stale(array $queue): bool
    {
        $now = time();

        if ($queue['status'] === 'processing') {
            // Stuck mid-batch: a batch started but never finished.
            if (!empty($queue['batch_start_time'])) {
                return ($now - (int)$queue['batch_start_time']) > self::STALE_PROCESSING_THRESHOLD;
            }

            // No batch is running and progress is older than 15 minutes. Before
            // this rule, such idle queues blocked new preloads for 24 hours.
            $last_progress = !empty($queue['last_processed_time'])
                ? (int)$queue['last_processed_time']
                : 0;

            return $last_progress === 0
                || ($now - $last_progress) > self::STALE_PROCESSING_THRESHOLD;
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
     * Run the scheduled preload. Skip this pass when the last run is inside
     * the schedule interval (cooldown check via last_run_at).
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
     * Set up the scheduled preload event. Schedule it only when missing;
     * clear it when the feature is off.
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
     * Make sure the recurring worker event exists. An existing event stays
     * untouched, so custom timing from other code is safe.
     *
     * @return void
     */
    private function ensure_preload_worker_scheduled(): void
    {
        if (!wp_next_scheduled('runcache_preload_process')) {
            wp_schedule_event(time(), 'every_minute', 'runcache_preload_process');
        }
    }

    /**
     * Re-arm a lost worker event for an active queue.
     * Inactive queues never schedule, so a completed marker stays event-free.
     *
     * @param array $queue Queue data
     * @return void
     */
    private function auto_heal_worker_event(array $queue): void
    {
        if (!in_array($queue['status'], ['queued', 'processing'], true)) {
            return;
        }

        if (wp_next_scheduled('runcache_preload_process')) {
            return;
        }

        $this->ensure_preload_worker_scheduled();

        $this->logger->info('Preload worker event re-armed automatically', [
            'queue_id' => $queue['queue_id'],
            'status' => $queue['status'],
            'processed_count' => $queue['processed_count'],
        ]);
    }

    /**
     * Hourly cleanup hook. Re-arms a lost worker event when no dashboard
     * poll is running, so an active queue recovers within one hour.
     *
     * @return void
     */
    public function heal_worker_event(): void
    {
        $queue = $this->get_queue();

        if (!$queue) {
            return;
        }

        $this->auto_heal_worker_event($queue);
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

        // Register a dynamic interval for scheduled preload. The filter
        // recomputes it, so setting changes apply on the next request.
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
     * Compute the scheduled-preload interval in seconds. Matches the
     * recurrence that add_cron_intervals() registers.
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
     * Remove completed or failed queues older than max age. Also clears
     * stale and too-old queues as a final safety check.
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

        // A completed queue is a last_run_at marker. Keep it; the next preload
        // overwrites it.
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
                    'skipped_count' => $status['skipped_count'],
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

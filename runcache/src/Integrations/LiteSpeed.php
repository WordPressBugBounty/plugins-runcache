<?php

namespace RunCache\Integrations;

use RunCache\Core\PageCache;
use RunCache\Core\Rules;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Server;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LiteSpeed cache provider
 */
class LiteSpeed implements CacheProvider
{
    private const HTACCESS_MARKER_BEGIN = '# BEGIN RunCache LiteSpeed';
    private const HTACCESS_MARKER_END = '# END RunCache LiteSpeed';

    private Logger $logger;
    private Setting $setting;

    /**
     * @var bool Whether the current request is cacheable and should be tracked.
     */
    private bool $should_track = false;

    /**
     * @var string The URL to track on shutdown.
     */
    private string $tracked_url = '';

    /**
     * @var int Estimated response size captured at shutdown.
     */
    private int $tracked_size = 0;

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize integration hooks and register provider.
     */
    public function init(): void
    {
        add_filter('runcache_cache_providers', [$this, 'provider_registration']);

        $settings = $this->setting->get('page_cache');
        if (!empty($settings['enable']) && $settings['page_cache_type'] === 'litespeed') {
            add_action('send_headers', [$this, 'do_cache'], 99999);
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
            add_action('runcache_after_purge_url', [$this, 'purge_url_cache']);

            add_action('send_headers', [$this, 'maybe_process_cli_purges'], 100000);
            add_action('shutdown', [$this, 'record_cacheable_page'], 0);
        }
    }

    /**
     * Send cache headers for current page.
     */
    public function do_cache(): void
    {
        if (!function_exists('runcache_should_cache')) {
            require_once RUNCACHE_PLUGIN_DIR . 'includes/runcache-filter.php';
        }

        header('X-RunCache-Type: ' . RUNCACHE_CACHE_TYPE_LITESPEED);

        $should = runcache_should_cache();
        $is_logged_in = is_user_logged_in();

        if (!$should) {
            header('X-RunCache-Status: BYPASS');
            header('X-LiteSpeed-Cache-Control: no-cache');
            $this->set_vary_cookie($is_logged_in);

            // Set extra vary cookie on bypass too so next request hits the right variant.
            $extra_cookie_value = $this->resolve_extra_vary_cookie();
            if ($extra_cookie_value !== null) {
                setcookie(
                    '_runcache_vary',
                    $extra_cookie_value,
                    time() + DAY_IN_SECONDS,
                    '/'
                );
            }
            return;
        }

        $this->should_track = true;

        header_remove('X-RunCache-Status');

        if ($is_logged_in) {
            $this->logger->debug('Setting PRIVATE cache header', ['provider' => 'LiteSpeed']);
            header('X-LiteSpeed-Cache-Control: private');
        } else {
            header('X-LiteSpeed-Cache-Control: public,max-age=3600');
        }

        // Add _runcache_vary cookie when cache_key_extra_var is active.
        $vary_cookies = ['_lscache_vary'];

        $extra_cookie_value = $this->resolve_extra_vary_cookie();
        if ($extra_cookie_value !== null) {
            setcookie(
                '_runcache_vary',
                $extra_cookie_value,
                time() + DAY_IN_SECONDS,
                '/'
            );
            $vary_cookies[] = '_runcache_vary';
            $this->logger->debug('Set extra vary cookie', [
                'provider' => 'LiteSpeed',
                'value' => $extra_cookie_value,
            ]);
        }

        header('X-LiteSpeed-Vary: cookie=' . implode(',cookie=', $vary_cookies));

        $this->set_vary_cookie($is_logged_in);

        $tags = $this->get_tags();
        if (!empty($tags)) {
            $vary_suffix = $is_logged_in ? '_loggedin' : '_guest';
            $tags = array_map(function($tag) use ($vary_suffix) {
                return $tag . $vary_suffix;
            }, $tags);

            header('X-LiteSpeed-Tag: ' . implode(',', $tags));
        }

    }

    /**
     * Set the LiteSpeed vary cookie for cache differentiation.
     *
     * @param bool $is_logged_in Whether the user is logged in.
     */
    private function set_vary_cookie(bool $is_logged_in): void
    {
        if ($is_logged_in) {
            $vary_value = 'logged-in:1';
        } else {
            $vary_value = 'guest_mode:1';
        }

        setcookie('_lscache_vary', $vary_value, time() + DAY_IN_SECONDS, '/');

    }

    /**
     * Hash cache_key_extra_var $_SERVER values into a vary cookie value.
     * @return string|null
     */
    private function resolve_extra_vary_cookie(): ?string
    {
        $rules = (new Rules())->get_settings();
        if (empty($rules['cache_key_extra_onn']) || empty($rules['cache_key_extra_var'])) {
            return null;
        }

        $extras = [];
        foreach (explode(',', $rules['cache_key_extra_var']) as $var) {
            $var = trim($var);
            if ($var !== '' && isset($_SERVER[$var])) {
                $extras[$var] = sanitize_text_field(wp_unslash($_SERVER[$var]));
            }
        }

        if (empty($extras)) {
            return null;
        }

        ksort($extras);

        return substr(md5(json_encode($extras)), 0, 8);
    }

    /**
     * Register this class as a cache provider.
     *
     * @param array $providers
     * @return array
     */
    public function provider_registration(array $providers): array
    {
        $providers['litespeed'] = $this;

        return $providers;
    }

    /**
     * Enable LiteSpeed cache.
     *
     * @return bool
     */
    public function enable(): bool
    {
        if (!$this->install_htaccess()) {
            $this->logger->error('Failed to install LiteSpeed .htaccess rules', ['provider' => 'LiteSpeed']);
            return false;
        }

        // Clear any stale cache entries that may have been created by a
        // previous provider (e.g. PHP) before LiteSpeed rules took effect.
        // In a web/AJAX context the purge header cannot be sent on the
        // current response, so use the transient + homepage-request mechanism
        // to replay the purge header on the next PHP-executed request.
        $this->delete_all_stats();
        $this->trigger_cli_purge('*', 'purge_on_enable', []);

        $this->logger->info('Cache enabled successfully', ['provider' => 'LiteSpeed']);
        return true;
    }

    /**
     * Disable LiteSpeed cache.
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->purge_all_cache();

        if (!$this->remove_htaccess()) {
            $this->logger->error('Failed to remove LiteSpeed .htaccess rules', ['provider' => 'LiteSpeed']);
            return false;
        }

        $this->logger->info('Cache disabled successfully', ['provider' => 'LiteSpeed']);

        return true;
    }

    /**
     * Install the LiteSpeed cache enable block into .htaccess.
     *
     * The block is prepended so LiteSpeed sees it before WordPress rewrite
     * rules. Existing RunCache LiteSpeed blocks are removed first to keep the
     * operation idempotent. A one-time backup of the original .htaccess is
     * created before the first modification.
     *
     * @return bool
     */
    private function install_htaccess(): bool
    {
        $file = new File();
        $source = RUNCACHE_PLUGIN_DIR . '/includes/runcache-page-litespeed.conf';
        $htaccess = $this->get_htaccess_path();

        if (!$file->exists($source)) {
            $this->logger->error('LiteSpeed config source not found', [
                'provider' => 'LiteSpeed',
                'source' => $source,
            ]);
            return false;
        }

        $block = $file->read($source);
        if ($block === false) {
            $this->logger->error('Failed to read LiteSpeed config source', [
                'provider' => 'LiteSpeed',
                'source' => $source,
            ]);
            return false;
        }

        $content = '';
        if ($file->exists($htaccess)) {
            $content = $file->read($htaccess);
            if ($content === false) {
                $this->logger->error('Failed to read .htaccess file', [
                    'provider' => 'LiteSpeed',
                    'path' => $htaccess,
                ]);
                return false;
            }

            if (!$this->backup_htaccess($htaccess)) {
                $this->logger->warning('Failed to create .htaccess backup', [
                    'provider' => 'LiteSpeed',
                    'path' => $htaccess,
                ]);
                // Continue anyway; permissions to write will be checked next.
            }
        }

        $content = $this->remove_htaccess_block($content);
        $new_content = rtrim($block) . "\n\n" . ltrim($content);

        if (!$file->is_writable($htaccess)) {
            $this->logger->error('.htaccess file is not writable', [
                'provider' => 'LiteSpeed',
                'path' => $htaccess,
            ]);
            return false;
        }

        if (!$file->atomic_write($htaccess, $new_content)) {
            $this->logger->error('Failed to write .htaccess file', [
                'provider' => 'LiteSpeed',
                'path' => $htaccess,
            ]);
            return false;
        }

        $this->logger->debug('LiteSpeed .htaccess rules installed', [
            'provider' => 'LiteSpeed',
            'path' => $htaccess,
        ]);

        return true;
    }

    /**
     * Remove the LiteSpeed cache enable block from .htaccess.
     *
     * @return bool
     */
    private function remove_htaccess(): bool
    {
        $file = new File();
        $htaccess = $this->get_htaccess_path();

        if (!$file->exists($htaccess)) {
            return true;
        }

        $content = $file->read($htaccess);
        if ($content === false) {
            $this->logger->error('Failed to read .htaccess file', [
                'provider' => 'LiteSpeed',
                'path' => $htaccess,
            ]);
            return false;
        }

        $new_content = $this->remove_htaccess_block($content);
        if ($new_content === $content) {
            // Nothing to remove.
            return true;
        }

        if (!$file->is_writable($htaccess)) {
            $this->logger->error('.htaccess file is not writable', [
                'provider' => 'LiteSpeed',
                'path' => $htaccess,
            ]);
            return false;
        }

        if (!$file->atomic_write($htaccess, $new_content)) {
            $this->logger->error('Failed to write .htaccess file', [
                'provider' => 'LiteSpeed',
                'path' => $htaccess,
            ]);
            return false;
        }

        $this->logger->debug('LiteSpeed .htaccess rules removed', [
            'provider' => 'LiteSpeed',
            'path' => $htaccess,
        ]);

        return true;
    }

    /**
     * Remove the RunCache LiteSpeed block from .htaccess content.
     *
     * @param string $content
     * @return string
     */
    private function remove_htaccess_block(string $content): string
    {
        $pattern = '/(?:\r?\n)?' . preg_quote(self::HTACCESS_MARKER_BEGIN, '/') . '.*?' . preg_quote(self::HTACCESS_MARKER_END, '/') . '(?:\r?\n)?/s';
        $cleaned = preg_replace($pattern, '', $content);

        return is_string($cleaned) ? $cleaned : $content;
    }

    /**
     * Create a one-time backup of the original .htaccess file.
     *
     * @param string $htaccess
     * @return bool
     */
    private function backup_htaccess(string $htaccess): bool
    {
        $file = new File();
        $backup_path = $htaccess . '.runcache-original.bak';

        if ($file->exists($backup_path)) {
            return true;
        }

        return $file->copy($htaccess, $backup_path, false);
    }

    /**
     * Get the path to the site's .htaccess file.
     *
     * @return string
     */
    private function get_htaccess_path(): string
    {
        return ABSPATH . '.htaccess';
    }

    /**
     * Verify LiteSpeed cache configuration.
     *
     * @return bool
     */
    public function verify(): bool
    {
        $cache = new PageCache();
        $response = $cache->request_cache_type();

        return $response === strtolower(RUNCACHE_CACHE_TYPE_LITESPEED);
    }

    /**
     * Purge all LiteSpeed cache for the site.
     */
    public function purge_all_cache(): void
    {
        $this->send_purge_header('*', 'purge_all');
        $this->delete_all_stats();
    }

    /**
     * Purge cache for a specific post.
     *
     * @param int $post_id
     * @param array $urls
     */
    public function purge_post_cache(int $post_id, array $urls = []): void
    {
        $this->logger->debug('Purging cache for post', ['provider' => 'LiteSpeed', 'post_id' => $post_id]);

        $post_tags = ['post_' . $post_id, 'type_' . get_post_type($post_id)];

        $categories = get_the_category($post_id);
        if ($categories) {
            foreach ($categories as $cat) {
                $post_tags[] = 'cat_' . $cat->term_id;
            }
        }

        $post_tags = array_merge($post_tags, $this->get_taxonomy_tags_for_post($post_id, 'post_tag'));

        // Add vary suffixes for both guest and logged-in variants
        // since we don't know which visitor state cached the page
        $purge_tags = [];
        foreach ($post_tags as $tag) {
            $purge_tags[] = $tag . '_guest';
            $purge_tags[] = $tag . '_loggedin';
        }

        $purge_value = implode(',', $purge_tags);

        $this->send_purge_header($purge_value, 'purge_post', [
            'post_id' => $post_id
        ]);

        // Also remove the stats marker for the post's canonical URL.
        $post_url = get_permalink($post_id);
        if ($post_url) {
            $this->delete_stats_for_url($post_url);
        }

        $this->logger->debug('Purge headers sent', ['provider' => 'LiteSpeed', 'tags' => $purge_value]);
    }

    /**
     * Purge cache for a specific URL.
     *
     * @param string $url
     */
    public function purge_url_cache(string $url): void
    {
        $this->logger->debug('Purging cache for URL', ['provider' => 'LiteSpeed', 'url' => $url]);

        // Try to resolve URL to post ID and purge by tags
        $post_id = url_to_postid($url);
        if ($post_id) {
            $this->logger->debug('Resolved URL to post, using tag-based purge', ['provider' => 'LiteSpeed', 'url' => $url, 'post_id' => $post_id]);
            $this->purge_post_cache($post_id);
            return;
        }

        // Fallback to URL hash for non-post URLs
        $this->logger->debug('URL not resolved to post, using URL hash purge', ['provider' => 'LiteSpeed', 'url' => $url]);
        $this->send_purge_header('url,' . md5($url), 'purge_url', [
            'url' => $url
        ]);

        $this->delete_stats_for_url($url);
    }

    /**
     * Send purge header via transient (for CLI) or directly (for web)
     *
     * @param string $value The purge header value
     * @param string $context Context for logging (e.g., 'purge_all', 'purge_post')
     * @param array $extra Additional parameters
     * @return void
     */
    private function send_purge_header(string $value, string $context = '', array $extra = []): void
    {
        $header = 'X-LiteSpeed-Purge: ' . $value;

        if (defined('WP_CLI') && constant('WP_CLI')) {
            $this->trigger_cli_purge($value, $context, $extra);
            return;
        }

        // Web context: send immediately (normal HTTP response)
        header($header);
        $this->logger->debug('Purge header sent', ['provider' => 'LiteSpeed', 'header' => $header]);
    }

    /**
     * Trigger purge via transient + homepage request (used by CLI)
     *
     * @param string $value The purge header value
     * @param string $context Context for logging
     * @param array $extra_params Additional parameters
     * @return void
     */
    private function trigger_cli_purge(string $value, string $context, array $extra_params = []): void
    {
        // Store in transient
        $existing = get_transient('runcache_cli_purge');
        if (!$existing || !is_array($existing)) {
            $existing = [];
        }

        $existing[] = [
            'header' => 'X-LiteSpeed-Purge: ' . $value,
            'context' => $context,
        ];

        set_transient('runcache_cli_purge', $existing, 60);

        $this->logger->debug('Stored CLI purge in transient', [
            'provider' => 'LiteSpeed',
            'header' => $value,
            'context' => $context
        ]);

        // Make GET request to homepage with a unique query param to force OLS
        // to pass the request to PHP (cache miss). Without this, OLS may serve
        // the cached homepage directly, bypassing PHP and preventing
        // maybe_process_cli_purges() from ever running.
        $cache_buster = time() . '_' . wp_rand();
        $purge_url = add_query_arg('runcache_purge_trigger', $cache_buster, home_url('/'));

        $response = wp_remote_get($purge_url, [
            'timeout' => 15,
            'sslverify' => false,
            'user-agent' => 'RunCache CLI Purge Request/1.0',
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->error('Failed to trigger homepage purge', [
                'provider' => 'LiteSpeed',
                'error' => $error_message
            ]);

            if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
                \WP_CLI::warning('LiteSpeed cache purge failed: ' . $error_message);
            }
            return;
        }

        $this->logger->debug('Homepage purge request completed', ['provider' => 'LiteSpeed']);
    }

    /**
     * Process CLI purges on next HTTP request
     *
     * Called from send_headers hook via admin-init or wp_loaded.
     * Checks transient for pending CLI purges and sends headers.
     *
     * @return void
     */
    public function maybe_process_cli_purges(): void
    {
        $purges = get_transient('runcache_cli_purge');
        if (empty($purges) || !is_array($purges)) {
            return;
        }

        // Delete transient immediately to prevent double-processing
        delete_transient('runcache_cli_purge');

        // Send no-cache to ensure purge headers are processed correctly
        header('X-LiteSpeed-Cache-Control: no-cache');

        foreach ($purges as $purge) {
            if (!empty($purge['header'])) {
                header($purge['header']);
                $this->logger->debug('Sent CLI purge header', [
                    'provider' => 'LiteSpeed',
                    'header' => $purge['header'],
                    'context' => $purge['context'] ?? '',
                ]);
            }
        }

        $this->logger->debug('Processed CLI purge queue', ['provider' => 'LiteSpeed']);
    }

    /**
     * Generate cache tags for current page.
     *
     * @return array
     */
    private function get_tags(): array
    {
        $tags = array('general');

        if (is_front_page()) {
            $tags[] = 'front';
        } elseif (is_home()) {
            $tags[] = 'home';
        } elseif (is_singular()) {
            $tags[] = 'post_' . get_the_ID();
            $tags[] = 'type_' . get_post_type();

            $categories = get_the_category();
            if ($categories) {
                foreach ($categories as $cat) {
                    $tags[] = 'cat_' . $cat->term_id;
                }
            }

            $tags = array_merge($tags, $this->get_taxonomy_tags('post_tag'));
        } elseif (is_archive()) {
            $tags[] = 'archive';

            if (is_author()) {
                $tags[] = 'author_' . get_the_author_meta('ID');
            } elseif (is_category()) {
                $cat = get_queried_object();
                $tags[] = 'cat_' . $cat->term_id;
            } elseif (is_tag()) {
                $tag = get_queried_object();
                $tags[] = 'tag_' . $tag->term_id;
            } elseif (is_date()) {
                $tags[] = 'date';
            }
        } elseif (is_search()) {
            $tags[] = 'search';
        }

        return array_unique($tags);
    }

    /**
     * Get taxonomy tags for current post.
     *
     * @param string $taxonomy
     * @return array
     */
    private function get_taxonomy_tags(string $taxonomy): array
    {
        $tags = array();
        $terms = get_the_terms(get_the_ID(), $taxonomy);
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $tags[] = $taxonomy . '_' . $term->term_id;
            }
        }
        return $tags;
    }

    /**
     * Get taxonomy tags for a specific post.
     *
     * @param int $post_id
     * @param string $taxonomy
     * @return array
     */
    private function get_taxonomy_tags_for_post(int $post_id, string $taxonomy): array
    {
        $tags = [];
        $terms = get_the_terms($post_id, $taxonomy);
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $tags[] = $taxonomy . '_' . $term->term_id;
            }
        }
        return $tags;
    }

    /**
     * Record a cacheable page for stats tracking.
     *
     * Runs on shutdown after the page has been rendered. If the request was
     * flagged as cacheable in do_cache(), write a tiny marker file containing
     * the estimated response size.
     *
     * @return void
     */
    public function record_cacheable_page(): void
    {
        if (!$this->should_track) {
            return;
        }

        $url = home_url(add_query_arg([]));
        $this->tracked_url = $url;
        $this->tracked_size = (int) ob_get_length();
        if ($this->tracked_size < 0) {
            $this->tracked_size = 0;
        }

        $path = $this->get_stats_file_path($url);
        $settings = $this->setting->get('page_cache', []);
        $cache_ttl = (int) ($settings['cache_expiry'] ?? 28800);

        // Fast path: already tracked and fresh.
        if (file_exists($path) && (time() - filemtime($path)) < $cache_ttl) {
            return;
        }

        $dir = dirname($path);
        $file = new File();
        if (!$file->exists($dir) && !$file->mkdir($dir)) {
            return;
        }

        @file_put_contents($path, (string) $this->tracked_size, LOCK_EX);
    }

    /**
     * Get cache statistics for LiteSpeed.
     *
     * Counts tracked marker files and prunes stale entries.
     *
     * @return array
     */
    public function get_stats(): array
    {
        $stats_dir = $this->get_stats_directory();
        $total_files = 0;
        $total_size = 0;

        $settings = $this->setting->get('page_cache', []);
        $cache_ttl = (int) ($settings['cache_expiry'] ?? 28800);
        $cutoff = time() - $cache_ttl;

        if (is_dir($stats_dir)) {
            $top_levels = scandir($stats_dir);
            if (is_array($top_levels)) {
                foreach ($top_levels as $top) {
                    if ($top === '.' || $top === '..') {
                        continue;
                    }
                    $top_dir = $stats_dir . '/' . $top;
                    if (!is_dir($top_dir)) {
                        continue;
                    }

                    $second_levels = scandir($top_dir);
                    if (!is_array($second_levels)) {
                        continue;
                    }

                    foreach ($second_levels as $second) {
                        if ($second === '.' || $second === '..') {
                            continue;
                        }
                        $second_dir = $top_dir . '/' . $second;
                        if (!is_dir($second_dir)) {
                            continue;
                        }

                        $files = scandir($second_dir);
                        if (!is_array($files)) {
                            continue;
                        }

                        foreach ($files as $file) {
                            if ($file === '.' || $file === '..') {
                                continue;
                            }
                            $path = $second_dir . '/' . $file;
                            if (!is_file($path)) {
                                continue;
                            }

                            // Prune stale entries (1× TTL).
                            if (filemtime($path) < $cutoff) {
                                (new File())->delete($path);
                                continue;
                            }

                            $total_files++;
                            $raw_size = (new File())->wpread($path);
                            $total_size += is_string($raw_size) ? (int) $raw_size : 0;
                        }
                    }
                }
            }
        }

        return [
            'total_files' => $total_files,
            'total_size' => $total_size,
            'total_gzipped_size' => 0,
            'expired_files' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_rate' => 0,
            'compression_ratio' => 0,
            'provider' => 'litespeed',
        ];
    }

    /**
     * Get the stats directory for the current site host.
     *
     * @return string
     */
    private function get_stats_directory(): string
    {
        $cache_dir = defined('RUNCACHE_CACHE_DIRECTORY')
            ? RUNCACHE_CACHE_DIRECTORY
            : WP_CONTENT_DIR . '/cache/runcache/';

        $host = Setting::get_site_host();

        return wp_normalize_path($cache_dir . '/' . $host . '/litespeed');
    }

    /**
     * Resolve the marker file path for a URL.
     *
     * @param string $url
     * @return string
     */
    private function get_stats_file_path(string $url): string
    {
        $host = wp_parse_url($url, PHP_URL_HOST) ?: Setting::get_site_host();
        $md5 = md5($url);
        $cache_subdir = substr($md5, -1) . '/' . substr($md5, -3, 2);
        $cache_filename = substr($md5, 0, -3);

        $cache_dir = defined('RUNCACHE_CACHE_DIRECTORY')
            ? RUNCACHE_CACHE_DIRECTORY
            : WP_CONTENT_DIR . '/cache/runcache/';

        return wp_normalize_path(
            $cache_dir . '/' . $host . '/litespeed/' . $cache_subdir . '/' . $cache_filename
        );
    }

    /**
     * Delete all tracked stats files.
     *
     * @return void
     */
    private function delete_all_stats(): void
    {
        $stats_dir = $this->get_stats_directory();
        if (!is_dir($stats_dir)) {
            return;
        }

        $file = new File();
        $file->delete($stats_dir, true);
    }

    /**
     * Delete the stats marker for a specific URL.
     *
     * @param string $url
     * @return void
     */
    private function delete_stats_for_url(string $url): void
    {
        $path = $this->get_stats_file_path($url);
        (new File())->delete($path);
    }
}

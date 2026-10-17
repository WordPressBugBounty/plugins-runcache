<?php

namespace RunCache\Core;

use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Integrations\PHPCache;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

class Stats
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;

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
     * Initialize stats functionality
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_get_stats', [$this, 'ajax_get_stats']);

        add_filter('runcache_get_stats', [$this, 'cli_get_stats']);
        add_action('runcache_after_purge_all', [$this, 'clear_cache']);
        add_action('runcache_after_purge_network', [$this, 'clear_cache']);
        add_action('runcache_after_purge_expired', [$this, 'clear_cache']);
        add_action('runcache_after_purge_url', [$this, 'clear_cache']);
        add_action('runcache_after_purge_post', [$this, 'clear_cache']);
        add_action('runcache_after_preload_complete', [$this, 'clear_cache']);
        add_action('runcache_cache_settings_saved', [$this, 'clear_cache']);
    }

    /**
     * Get cache statistics
     *
     * Uses the active cache provider's get_stats() implementation. Falls back
     * to filesystem scan for the PHP provider when the active provider cannot
     * return stats.
     *
     * @return array
     */
    public function get_stats(): array
    {
        $cache_key = 'runcache_stats';
        $cached_stats = get_transient($cache_key);
        if ($cached_stats !== false) {
            return $cached_stats;
        }

        // Allow the active cache provider to replace filesystem-based stats entirely.
        $provider_stats = apply_filters('runcache_stats_provider', null);
        if (is_array($provider_stats)) {
            set_transient($cache_key, $provider_stats, MINUTE_IN_SECONDS);
            return $provider_stats;
        }

        $page_cache = new PageCache($this->logger, $this->validator, $this->setting);
        $provider = $page_cache->get_cache_provider($page_cache->get_cache_type());

        if ($provider instanceof CacheProvider) {
            $stats = $provider->get_stats();
            if (is_array($stats) && !empty($stats)) {
                set_transient($cache_key, $stats, MINUTE_IN_SECONDS);
                return $stats;
            }
        }

        // Fallback: scan the PHP filesystem cache.
        $stats = (new PHPCache($this->logger, $this->setting))->get_stats();
        set_transient($cache_key, $stats, MINUTE_IN_SECONDS);

        return $stats;
    }

    /**
     * Clear stats cache
     *
     * @return void
     */
    public function clear_cache(): void
    {
        delete_transient('runcache_stats');
    }

    /**
     * Get stats for CLI/filter usage
     *
     * @return array
     */
    public function cli_get_stats(): array
    {
        $stats = $this->get_stats();

        $cache_size = size_format($stats['total_size'] ?? 0);
        if (!empty($stats['provider']) && isset($stats['cache_size_label'])) {
            $cache_size = $stats['cache_size_label'];
        }

        return [
            'success' => true,
            'data' => [
                'cached_pages' => $stats['total_files'] ?? 0,
                'cache_size' => $cache_size,
                'compression_ratio' => number_format(($stats['compression_ratio'] ?? 0), 1) . '%'
            ]
        ];
    }

    /**
     * AJAX handler for getting stats
     *
     * @return void
     */
    public function ajax_get_stats(): void
    {
        $this->validator->validate_ajax_request();

        $stats = $this->get_stats();

        if (!empty($stats['provider'])) {
            wp_send_json_success([
                'cached_pages' => $stats['total_files'] ?? 0,
                'cache_size' => $stats['cache_size_label'] ?? size_format($stats['total_size'] ?? 0),
                'compression_ratio' => number_format(($stats['compression_ratio'] ?? 0), 1) . '%',
                'provider' => $stats['provider'],
                'apo_enabled' => $stats['apo_enabled'] ?? null,
                'zone_id' => $stats['zone_id'] ?? null,
            ]);
        }

        wp_send_json_success([
            'cached_pages' => $stats['total_files'],
            'cache_size' => size_format($stats['total_size']),
            'compression_ratio' => number_format(($stats['compression_ratio'] ?? 0), 1) . '%'
        ]);
    }
}

<?php

namespace RunCache\Integrations\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

interface CacheProvider
{
    public function provider_registration(array $providers): array;

    public function enable();

    public function disable();

    public function verify();

    /**
     * Return cache statistics for the active provider.
     *
     * The returned array must contain the following keys:
     * - total_files
     * - total_size
     * - total_gzipped_size
     * - expired_files
     * - cache_hits
     * - cache_misses
     * - hit_rate
     * - compression_ratio
     *
     * Providers that cannot return byte measurements may include
     * cache_size_label and provider keys instead/as well.
     *
     * @return array
     */
    public function get_stats(): array;
}
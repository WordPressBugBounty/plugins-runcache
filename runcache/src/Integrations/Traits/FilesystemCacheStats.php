<?php

namespace RunCache\Integrations\Traits;

use RunCache\Core\Setting;
use RunCache\Support\File;

/**
 * Shared filesystem-cache statistics scanner.
 *
 * Used by providers that store cached pages as files on disk
 * (php, nginx, apache). Extracted from src/Core/Stats.php so each
 * provider can own its own get_stats() implementation.
 */
trait FilesystemCacheStats
{
    /**
     * Scan the PHP-style page-cache directory and compute statistics.
     *
     * @param string $provider_key Value for the 'provider' key in the returned array.
     * @return array
     */
    protected function get_filesystem_stats(string $provider_key = ''): array
    {
        $cache_key = 'runcache_provider_stats_' . $provider_key;
        $cached_stats = get_transient($cache_key);
        if ($cached_stats !== false && is_array($cached_stats)) {
            return $cached_stats;
        }

        $cache_directory = defined('RUNCACHE_CACHE_DIRECTORY')
            ? RUNCACHE_CACHE_DIRECTORY
            : WP_CONTENT_DIR . '/cache/runcache/';

        $file = new File();
        $cache_files = [];

        if ($file->exists($cache_directory)) {
            $cache_files = $this->get_cache_files($cache_directory, $file);
        }

        $settings = $this->get_cache_settings();
        $cache_expire = $settings['cache_expiry'] ?? 28800;

        $total_files = count($cache_files);
        $total_size = 0;
        $total_gzipped_size = 0;
        $expired_files = 0;

        foreach ($cache_files as $cache_file) {
            $size = $file->size($cache_file);
            if ($size < 0) {
                $size = 0;
            }
            $total_size += $size;

            $meta_file = $this->resolve_meta_file($cache_file);
            if ($meta_file !== '' && $file->exists($meta_file)) {
                $meta_content = $file->read($meta_file);
                $meta = $meta_content ? json_decode($meta_content, true) : null;

                if (is_array($meta) && isset($meta['time'])) {
                    $gzip_size = isset($meta['gzip_size']) && is_numeric($meta['gzip_size'])
                        ? (int) $meta['gzip_size']
                        : 0;
                    $total_gzipped_size += $gzip_size;

                    if ((time() - (int) $meta['time']) > $cache_expire) {
                        $expired_files++;
                    }
                }
            }
        }

        $data = [
            'total_files' => $total_files,
            'total_size' => $total_size,
            'total_gzipped_size' => $total_gzipped_size,
            'expired_files' => $expired_files,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_rate' => 0,
            'compression_ratio' => $total_gzipped_size > 0
                ? round(($total_size - $total_gzipped_size) / $total_size * 100, 2)
                : 0,
        ];

        if ($provider_key !== '') {
            $data['provider'] = $provider_key;
        }

        set_transient($cache_key, $data, MINUTE_IN_SECONDS);

        return $data;
    }

    /**
     * Recursively collect cache .html files from a directory.
     *
     * @param string $directory
     * @param File $file
     * @param bool $is_root True on the initial call from the cache root scan.
     * @return array
     */
    private function get_cache_files(string $directory, File $file, bool $is_root = true): array
    {
        $cache_files = [];

        $files = $file->dirlist($directory, false, true);
        if (!is_array($files)) {
            return $cache_files;
        }

        foreach ($files as $filename => $file_info) {
            if (!is_array($file_info)) {
                continue;
            }

            $type = $file_info['type'] ?? '';

            if ($type === 'f') {
                // Skip the root index.html sentinel; cache entries always live under site subdirectories.
                if ($is_root && $filename === 'index.html') {
                    continue;
                }

                if (pathinfo($filename, PATHINFO_EXTENSION) === 'html') {
                    $cache_files[] = wp_normalize_path($directory . '/' . $filename);
                }
            } elseif ($type === 'd') {
                $subdir_files = $this->get_cache_files($directory . '/' . $filename, $file, false);
                $cache_files = array_merge($cache_files, $subdir_files);
            }
        }

        return $cache_files;
    }

    /**
     * Resolve the sibling .meta file for a given .html cache file.
     *
     * @param string $cache_file
     * @return string
     */
    private function resolve_meta_file(string $cache_file): string
    {
        if (!str_ends_with($cache_file, '.html')) {
            return '';
        }

        return substr($cache_file, 0, -5) . '.meta';
    }

    /**
     * Get page-cache settings with a sane fallback.
     *
     * @return array
     */
    private function get_cache_settings(): array
    {
        if (property_exists($this, 'setting') && $this->setting instanceof Setting) {
            return $this->setting->get('page_cache', []);
        }

        return (new Setting())->get('page_cache', []);
    }
}

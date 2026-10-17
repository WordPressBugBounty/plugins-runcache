<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\PageCache;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FastCGI integration handler
 */
class FastCGI implements CacheProvider
{
    private Logger $logger;
    private Setting $setting;

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
     * Initialize integration
     *
     * Sets up WordPress hooks and filters for:
     * - Cache provider registration
     * - Cache purge hooks
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cache_providers', [$this, 'provider_registration']);

        $settings = $this->setting->get('page_cache');
        if (!empty($settings['enable']) && $settings['page_cache_type'] === 'fastcgi') {
            add_action('send_headers', [$this, 'do_cache'], 99999);
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
            add_action('runcache_after_purge_url', [$this, 'purge_url_cache']);
        }
    }

    /**
     * Send cache headers for current page
     *
     * @return void
     */
    public function do_cache(): void
    {
        if (!function_exists('runcache_should_cache')) {
            require_once RUNCACHE_PLUGIN_DIR . 'includes/runcache-filter.php';
        }

        // header('X-RunCache-Type: nginx fastcgi');

        $should = runcache_should_cache();

        // FastCGI must bypass before nginx stores the upstream response.
        if (!$should) {
            header('X-RunCache-Bypass: 1');
            header('Cache-Control: no-cache, must-revalidate');
            return;
        }
    }

    /**
     * Register this class as a cache provider
     *
     * @param array $providers
     * @return array
     */
    public function provider_registration(array $providers): array
    {
        $providers['fastcgi'] = $this;

        return $providers;
    }

    /**
     * Enable FastCGI caching
     *
     * @return bool
     */
    public function enable(): bool
    {
        try {
            $result = $this->install_dropin();

            if ($result) {
                $this->ensure_fastcgi_cache_directory();
                do_action('runcache_fastcgi_cache_enabled', $this);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to enable cache', ['provider' => 'FastCGI', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Disable FastCGI caching
     *
     * @return bool
     */
    public function disable(): bool
    {
        try {
            $result = $this->remove_dropin();
            if ($result) {
                do_action('runcache_fastcgi_cache_disabled', $this);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to disable cache', ['provider' => 'FastCGI', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Verify FastCGI cache configuration
     *
     * @return bool
     */
    public function verify(): bool
    {
        $cache = new PageCache();
        $response = $cache->request_cache_type();

        return $response === strtolower(RUNCACHE_CACHE_TYPE_FASTCGI);
    }

    /**
     * Get cache statistics for FastCGI cache.
     *
     * FastCGI cache files are MD5-hashed with no extension under:
     * {RUNCACHE_CACHE_DIRECTORY}/{host}/fastcgi/{last char}/{2 chars}/{remaining md5}
     *
     * @return array
     */
    public function get_stats(): array
    {
        $cache_key = 'runcache_fastcgi_stats';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $cache_dir = RUNCACHE_CACHE_DIRECTORY;
        $site_host = Setting::get_site_host();
        $fastcgi_dir = wp_normalize_path($cache_dir . $site_host . '/fastcgi');

        $file = new File();
        $total_files = 0;
        $total_size = 0;

        if ($file->exists($fastcgi_dir)) {
            $total_files = $this->count_files_recursive($fastcgi_dir, $file, $total_size);
        }

        $data = [
            'total_files' => $total_files,
            'total_size' => $total_size,
            'total_gzipped_size' => 0,
            'expired_files' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_rate' => 0,
            'compression_ratio' => 0,
            'provider' => 'fastcgi',
        ];

        set_transient($cache_key, $data, MINUTE_IN_SECONDS);

        return $data;
    }

    /**
     * Recursively count files and sum sizes, skipping marker files.
     *
     * @param string $directory
     * @param File $file
     * @param int $total_size
     * @return int
     */
    private function count_files_recursive(string $directory, File $file, int &$total_size): int
    {
        $count = 0;

        $files = $file->dirlist($directory, false, true);
        if (!is_array($files)) {
            return $count;
        }

        foreach ($files as $filename => $file_info) {
            if (!is_array($file_info)) {
                continue;
            }

            $type = $file_info['type'] ?? '';

            if ($type === 'd') {
                $count += $this->count_files_recursive($directory . '/' . $filename, $file, $total_size);
            } elseif ($type === 'f' && $filename !== 'index.html') {
                $count++;
                $total_size += $file->size($directory . '/' . $filename);
            }
        }

        return $count;
    }

    /**
     * Install FastCGI cache configuration files
     *
     * @return bool
     */
    public function install_dropin(): bool
    {
        $file = new File();

        $config_dir = $file->ensure_config_dir();
        if ($config_dir === false) {
            $this->logger->error('Failed to create config directory', ['provider' => 'FastCGI']);
            return false;
        }

        $configs = [
            'runcache-fastcgi-config.conf',
            'runcache-fastcgi-cache.conf',
        ];

        $site_name = $this->get_site_name();
        $cache_zone = 'runcache_' . $site_name . '_fastcgi';
        $site_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : wp_parse_url(get_site_url(), PHP_URL_HOST);

        foreach ($configs as $config) {
            $config_source = RUNCACHE_PLUGIN_DIR . 'includes/' . $config;
            $config_target = $config_dir . $config;

            if (!$file->exists($config_source)) {
                $this->logger->error('Source config file not found', ['provider' => 'FastCGI', 'path' => $config_source]);
                return false;
            }

            $config_content = $file->read($config_source);
            if ($config_content === false) {
                $this->logger->error('Failed to read config file', ['provider' => 'FastCGI', 'path' => $config_source]);
                return false;
            }

            $replacements = [
                '{{CACHE_ZONE}}' => $cache_zone,
                '{{SITE_CACHE_VAR}}' => $site_name,
                '{{HOST}}' => $site_host,
                '{{DOCUMENT_ROOT}}' => ABSPATH,
            ];

            $config_content = str_replace(array_keys($replacements), array_values($replacements), $config_content);

            $write_success = $file->write($config_target, $config_content);
            if (!$write_success) {
                $this->logger->error('Failed to write config file', ['provider' => 'FastCGI', 'path' => $config_target]);
                return false;
            }
        }

        return true;
    }

    /**
     * Remove FastCGI cache configuration files
     *
     * @return bool
     */
    public function remove_dropin(): bool
    {
        $file = new File();

        $configs = [
            'runcache-fastcgi-config.conf' => 'runcache-fastcgi-config-disabled.conf',
            'runcache-fastcgi-cache.conf' => 'runcache-fastcgi-cache-disabled.conf',
        ];

        $config_dir = $file->ensure_config_dir();
        if ($config_dir === false) {
            $this->logger->error('Failed to create config directory', ['provider' => 'FastCGI']);
            return false;
        }

        foreach ($configs as $target_name => $source_name) {
            $source = RUNCACHE_PLUGIN_DIR . 'includes/' . $source_name;
            $config_target = $config_dir . $target_name;

            if (!$file->exists($source)) {
                $this->logger->error('Source disabled config file not found', ['provider' => 'FastCGI', 'path' => $source]);
                return false;
            }

            $disabled_config = $file->read($source);
            if ($disabled_config === false) {
                $this->logger->error('Failed to read disabled config file', ['provider' => 'FastCGI', 'path' => $source]);
                return false;
            }

            $write_success = $file->write($config_target, $disabled_config);
            if (!$write_success) {
                $this->logger->error('Failed to write disabled config file', ['provider' => 'FastCGI', 'path' => $config_target]);
                return false;
            }
        }

        return true;
    }

    /**
     * Format site name for nginx configuration
     *
     * @return string
     */
    private function get_site_name(): string
    {
        $site_name = get_bloginfo('name');
        $formatted = strtolower($site_name);
        $formatted = preg_replace('/[^a-z0-9\s-]/', '', $formatted);
        $formatted = preg_replace('/\s+/', '_', $formatted);
        $formatted = preg_replace('/-+/', '_', $formatted);

        return substr($formatted, 0, 30);
    }

    /**
     * Ensure FastCGI cache directory exists
     *
     * Creates the FastCGI-specific cache directory structure.
     * Directory structure: RUNCACHE_CACHE_DIRECTORY/{site_host}/fastcgi/
     *
     * @since 1.0.0
     * @return bool True on success, false on failure
     */
    protected function ensure_fastcgi_cache_directory(): bool
    {
        $file = new File();

        $cache_dir = RUNCACHE_CACHE_DIRECTORY;
        $site_host = Setting::get_site_host();
        $fastcgi_dir = $cache_dir . $site_host . '/fastcgi/';

        if (!is_dir($fastcgi_dir)) {
            $created = $file->mkdir($fastcgi_dir);

            if (!$created) {
                $this->logger->error('Failed to create cache directory', [
                    'provider' => 'FastCGI',
                    'path' => $fastcgi_dir,
                ]);
                return false;
            }

            $index_content = '<!-- Silence is golden - RunCache FastCGI protected directory -->';
            $file->write($fastcgi_dir . 'index.html', $index_content);
        }

        return true;
    }

    /**
     * Purge all FastCGI cache for the site
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        $file = new File();

        $this->logger->info('Purging all cache for site', ['provider' => 'FastCGI']);

        $cache_dir = RUNCACHE_CACHE_DIRECTORY;
        $site_host = Setting::get_site_host();
        $fastcgi_dir = $cache_dir . $site_host . '/fastcgi/';

        if ($file->exists($fastcgi_dir)) {
            $file->delete($fastcgi_dir, true);
        }

        $this->ensure_fastcgi_cache_directory();
    }

    /**
     * Purge FastCGI cache for a specific post
     *
     * @param int $post_id
     * @param array $urls
     * @return void
     */
    public function purge_post_cache(int $post_id, array $urls = []): void
    {
        $validator = new Validator();
        $urls = $validator->normalize_url_list($urls);

        if (empty($urls)) {
            $this->logger->info('No URLs to purge', [
                'provider' => 'FastCGI',
                'post_id' => $post_id,
            ]);

            return;
        }

        foreach ($urls as $url) {
            $this->delete_cache_file($url);
        }
    }

    /**
     * Purge FastCGI cache for a specific URL
     *
     * @param string $url
     * @return void
     */
    public function purge_url_cache(string $url): void
    {
        $this->logger->info('Purging cache for URL', [
            'provider' => 'FastCGI',
            'url' => $url,
        ]);

        $this->delete_cache_file($url);
    }

    /**
     * Delete cache file directly from filesystem
     *
     * @param string $url
     * @return bool
     */
    protected function delete_cache_file(string $url): bool
    {
        $file = new File();

        $parsed_url = wp_parse_url($url);

        if ($parsed_url === false || !isset($parsed_url['path'])) {
            $this->logger->warning('Invalid URL for cache deletion', [
                'provider' => 'FastCGI',
                'url' => $url,
            ]);
            return false;
        }

        $path = $parsed_url['path'];
        $host = $parsed_url['host'] ?? '';

        if (empty($host)) {
            return false;
        }

        $request_uri = $path;
        if (isset($parsed_url['query']) && $parsed_url['query'] !== '') {
            $request_uri .= '?' . $parsed_url['query'];
        }

        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'https';

        $schemes_to_try = ['https', 'http'];

        if ($scheme === 'http') {
            $schemes_to_try = ['http', 'https'];
        }

        $deleted_at_least_one = false;

        foreach ($schemes_to_try as $current_scheme) {
            $cache_key = $current_scheme . 'GET' . $host . $request_uri;
            $md5 = md5($cache_key);

            $cache_subdir = substr($md5, -1) . '/' . substr($md5, -3, 2);
            $cache_filename = $md5;

            $fastcgi_dir = RUNCACHE_CACHE_DIRECTORY . $host . '/fastcgi/';
            $cache_file = $fastcgi_dir . $cache_subdir . '/' . $cache_filename;

            if ($file->exists($cache_file)) {
                $deleted = $file->delete($cache_file);

                if ($deleted) {
                    $this->logger->info('Cache file deleted', [
                        'provider' => 'FastCGI',
                        'url' => $url,
                        'scheme' => $current_scheme,
                        'file' => $cache_file,
                    ]);
                    $deleted_at_least_one = true;
                } else {
                    $this->logger->warning('Failed to delete cache file', [
                        'provider' => 'FastCGI',
                        'url' => $url,
                        'scheme' => $current_scheme,
                        'file' => $cache_file,
                    ]);
                }
            }
        }

        return $deleted_at_least_one;
    }
}

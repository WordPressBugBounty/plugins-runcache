<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\PageCache;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Integrations\Traits\FilesystemCacheStats;
use RunCache\Support\Dropin;
use RunCache\Support\File;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nginx integration handler
 */
class Nginx implements CacheProvider
{
    use FilesystemCacheStats;

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
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cache_providers', [$this, 'provider_registration']);
    }

    /**
     * Register this class as a cache provider
     *
     * @param array $providers
     * @return array
     */
    public function provider_registration(array $providers): array
    {
        $providers['nginx'] = $this;

        return $providers;
    }

    /**
     * Enable Nginx caching
     *
     * @return bool
     */
    public function enable(): bool
    {
        try {
            $dropin = new Dropin($this->logger, $this->setting);
            if (!$dropin->install_page_cache_dropin()) {
                $this->logger->error('Failed to install page cache drop-in', ['provider' => 'Nginx']);
                return false;
            }

            $result = $this->copy_config();

            if ($result) {
                do_action('runcache_nginx_cache_enabled', $this);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to enable cache', ['provider' => 'Nginx', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Disable Nginx caching
     *
     * @return bool
     */
    public function disable(): bool
    {
        try {
            $result = $this->clear_config();

            if ($result) {
                do_action('runcache_nginx_cache_disabled', $this);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Failed to disable cache', ['provider' => 'Nginx', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Verify Nginx cache configuration
     *
     * @return bool
     */
    public function verify(): bool
    {
        $cache = new PageCache();
        $response = $cache->request_cache_type();

        return $response === strtolower(RUNCACHE_CACHE_TYPE_NGINX);
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        return $this->get_filesystem_stats('nginx');
    }

    /**
     * Copy Nginx configuration file
     *
     * @return bool
     */
    protected function copy_config(): bool
    {
        $file = new File();

        $config_dir = $file->ensure_config_dir();
        if ($config_dir === false) {
            $this->logger->error('Failed to create config directory', ['provider' => 'Nginx']);
            return false;
        }

        $source = RUNCACHE_PLUGIN_DIR . 'includes/runcache-page-nginx.conf';
        $target = $config_dir . 'runcache-nginx.conf';

        if (!$file->exists($source)) {
            $this->logger->error('Source config file not found', ['provider' => 'Nginx', 'path' => $source]);
            return false;
        }

        $nginx_config = $file->read($source);

        if ($nginx_config === false) {
            $this->logger->error('Failed to read config file', ['provider' => 'Nginx', 'path' => $source]);
            return false;
        }

        $write_success = $file->write($target, $nginx_config);

        if (!$write_success) {
            $this->logger->error('Failed to write config file', ['provider' => 'Nginx', 'path' => $target]);
            return false;
        }

        return true;
    }

    /**
     * Clear Nginx configuration file
     *
     * @return bool
     */
    protected function clear_config(): bool
    {
        $file = new File();

        $config_dir = $file->ensure_config_dir();
        if ($config_dir === false) {
            $this->logger->error('Failed to create config directory', ['provider' => 'Nginx']);
            return false;
        }

        $source = RUNCACHE_PLUGIN_DIR . 'includes/runcache-page-nginx-disabled.conf';
        $target = $config_dir . 'runcache-nginx.conf';

        if (!$file->exists($source)) {
            $this->logger->error('Source disabled config file not found', ['provider' => 'Nginx', 'path' => $source]);
            return false;
        }

        $disabled_config = $file->read($source);

        if ($disabled_config === false) {
            $this->logger->error('Failed to read disabled config file', ['provider' => 'Nginx', 'path' => $source]);
            return false;
        }

        $write_success = $file->write($target, $disabled_config);

        if (!$write_success) {
            return false;
        }

        return true;
    }

    /**
     * Purge cache for a list of URLs
     *
     * @param int $post_id
     * @param array $urls
     * @return bool
     */
    public function purge_post_cache(int $post_id, array $urls): bool
    {
        $validator = new Validator();
        $urls = $validator->normalize_url_list($urls);

        if (empty($urls)) {
            $this->logger->info('No URLs to purge for post', [
                'provider' => 'Nginx',
                'post_id' => $post_id,
            ]);

            return true;
        }

        foreach ($urls as $url) {
            $this->logger->info('Purging cache for post', [
                'provider' => 'Nginx',
                'post_id' => $post_id,
                'url' => $url,
            ]);
        }

        return true;
    }
}

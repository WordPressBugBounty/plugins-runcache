<?php

namespace RunCache\Integrations;

use RunCache\Core\PageCache;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Integrations\Traits\FilesystemCacheStats;
use RunCache\Support\Dropin;
use RunCache\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP Cache integration handler
 */
class PHPCache implements CacheProvider
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
     * Initialize cache functionality
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
        $providers['php'] = $this;

        return $providers;
    }

    /**
     * Enable PHP caching
     * Ensures the page cache drop-in file is installed
     *
     * @return bool
     */
    public function enable(): bool
    {
        $dropin = new Dropin($this->logger, $this->setting);
        return $dropin->install_page_cache_dropin();
    }

    /**
     * Disable PHP caching
     * Drop-in is managed at plugin activation/deactivation, not here
     *
     * @return bool
     */
    public function disable(): bool
    {
        return true;
    }

    /**
     * Verify PHP cache configuration
     *
     * @return bool
     */
    public function verify(): bool
    {
        $cache = new PageCache();
        $response = $cache->request_cache_type();

        return $response === strtolower(RUNCACHE_CACHE_TYPE_PHP);
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        return $this->get_filesystem_stats('php');
    }

    /**
     * Install PHP page-cache drop-in
     *
     * Delegates to the Dropin helper so other providers can
     * request a PHP-backed cache without duplicating logic.
     *
     * @return bool
     */
    public function install_dropin(): bool
    {
        $dropin = new Dropin($this->logger, $this->setting);
        return $dropin->install_page_cache_dropin();
    }

    /**
     * Remove PHP page-cache drop-in
     *
     * Delegates to the Dropin helper so other providers can
     * request removal of a PHP-backed cache without duplicating logic.
     *
     * @return bool
     */
    public function remove_dropin(): bool
    {
        $dropin = new Dropin($this->logger, $this->setting);
        return $dropin->remove_page_cache_dropin();
    }
}
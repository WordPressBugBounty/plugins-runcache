<?php

namespace RunCache\Integrations;

use RunCache\Core\PageCache;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Integrations\Traits\FilesystemCacheStats;
use RunCache\Support\File;
use RunCache\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Apache integration handler
 */
class Apache implements CacheProvider
{
    use FilesystemCacheStats;

    private const HTACCESS_MARKER_BEGIN = '# BEGIN RunCache';
    private const HTACCESS_MARKER_END = '# END RunCache';

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
        $providers['apache'] = $this;

        return $providers;
    }

    /**
     * Enable Apache cache
     *
     * @return bool
     */
    public function enable(): bool
    {
        try {
            $result = $this->install_dropin();

            if ($result) {
                do_action('runcache_apache_cache_enabled', $this);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to enable cache', ['provider' => 'Apache', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Disable Apache cache
     *
     * @return bool
     */
    public function disable(): bool
    {
        try {
            $result = $this->remove_dropin();

            if ($result) {
                do_action('runcache_apache_cache_disabled', $this);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to disable cache', ['provider' => 'Apache', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Verify Apache cache configuration
     *
     * @return bool
     */
    public function verify(): bool
    {
        $enable = $this->enable();

        if (!$enable) {
            return false;
        }

        $cache = new PageCache();
        $response = $cache->request_cache_type();

        return $response === strtolower(RUNCACHE_CACHE_TYPE_APACHE);
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        return $this->get_filesystem_stats('apache');
    }

    /**
     * Install Apache configuration to .htaccess
     *
     * @return bool
     */
    protected function install_dropin(): bool
    {
        // we still need advanced-cache.php to generate cache file
        $php = new PHPCache($this->logger, $this->setting);
        if (!$php->install_dropin()) {
            return false;
        }

        $file = new File();
        $source = RUNCACHE_PLUGIN_DIR . '/includes/runcache-page-apache.conf';
        $htaccess = ABSPATH . '.htaccess';

        if (!$file->exists($source)) {
            $this->logger->error('Source config file not found', ['provider' => 'Apache', 'source' => $source]);
            return false;
        }

        $apache_config = $file->read($source);

        if ($apache_config === false) {
            $this->logger->error('Failed to read config file', ['provider' => 'Apache', 'source' => $source]);
            return false;
        }

        $htaccess_content = '';

        if ($file->exists($htaccess)) {
            $htaccess_content = $file->read($htaccess);

            if ($htaccess_content === false) {
                $this->logger->error('Failed to read .htaccess file', ['path' => $htaccess]);
                return false;
            }

            $htaccess_content = $this->remove_htaccess_block($htaccess_content);
            $htaccess_content = $this->remove_legacy_orphans($htaccess_content);
        }

        $new_content = $apache_config . "\n\n" . ltrim($htaccess_content);

        $write_success = $file->atomic_write($htaccess, $new_content);
        if (!$write_success) {
            $this->logger->error('Failed to write to .htaccess file', ['path' => $htaccess]);
            return false;
        }

        return true;
    }

    /**
     * Remove Apache configuration from .htaccess
     *
     * @return bool
     */
    protected function remove_dropin(): bool
    {
        $php = new PHPCache($this->logger, $this->setting);
        if (!$php->remove_dropin()) {
            return false;
        }

        $file = new File();
        $htaccess = ABSPATH . '.htaccess';

        if (!$file->exists($htaccess)) {
            return true;
        }

        $htaccess_content = $file->read($htaccess);

        if ($htaccess_content === false) {
            $this->logger->error('Failed to read .htaccess file', ['path' => $htaccess]);
            return false;
        }

        $new_content = $this->remove_htaccess_block($htaccess_content);
        $new_content = $this->remove_legacy_orphans($new_content);
        $new_content = ltrim($new_content, "\r\n");
        $new_content = preg_replace('/\n{3,}/', "\n\n", $new_content);

        $write_success = $file->atomic_write($htaccess, $new_content);

        if (!$write_success) {
            $this->logger->error('Failed to update .htaccess file', ['path' => $htaccess]);
            return false;
        }

        return true;
    }

    /**
     * Remove the RunCache Apache block from .htaccess content.
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
     * Strip orphaned header comments left behind by older plugin versions
     * where the markers did not enclose the full injected block.
     *
     * @param string $content
     * @return string
     */
    private function remove_legacy_orphans(string $content): string
    {
        $cleaned = preg_replace('/^# RunCache Plugin: Apache rewrite rules.*$\n?/m', '', $content);
        $cleaned = preg_replace('/^# Bugs or questions\? Email support@runcloud\.io\.$\n?/m', '', $cleaned);

        return is_string($cleaned) ? $cleaned : $content;
    }
}

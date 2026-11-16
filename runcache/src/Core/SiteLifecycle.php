<?php

namespace RunCache\Core;

use RunCache\Integrations\Multisite;
use RunCache\Support\File;
use RunCache\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cleans up plugin state when a site is deleted from a network.
 *
 * Without this, a deleted site's config row and cache files survive, and
 * a future site at the same URL inherits the deleted site's settings.
 */
class SiteLifecycle
{
    private Logger $logger;
    private Setting $setting;

    /**
     * @param Logger|null $logger
     * @param object|null $validator Unused; core modules receive it uniformly.
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        $validator = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize site lifecycle hooks
     *
     * @return void
     */
    public function init(): void
    {
        if (!Multisite::is_multisite()) {
            return;
        }

        add_action('wp_delete_site', [$this, 'handle_site_deleted']);
    }

    /**
     * Remove the deleted site's config row and cache directory.
     *
     * A future site at the same URL then starts on the fail-open defaults
     * instead of the deleted site's cached configuration.
     *
     * @param \WP_Site $site The deleted site.
     * @return void
     */
    public function handle_site_deleted(\WP_Site $site): void
    {
        $site_id = (int) $site->id;
        $domain = (string) $site->domain;
        $path = (string) $site->path;

        $config_key = Multisite::config_key_for_site($domain, $path);
        if ($config_key === '') {
            $this->logger->warning('Site deletion cleanup skipped: config key could not be built', [
                'site_id' => $site_id,
            ]);
            return;
        }

        $removed = $this->setting->remove_config_rows([$config_key]);
        $this->logger->info($removed ? 'Site config row removed' : 'Failed to remove site config row', [
            'site_id' => $site_id,
            'config_key' => $config_key,
        ]);

        $cache_dir = $this->get_site_cache_directory($domain, $path);
        if ($cache_dir === null || !is_dir($cache_dir)) {
            return;
        }

        $file = new File();
        if ($file->delete($cache_dir, true)) {
            $this->logger->info('Site cache directory removed', [
                'site_id' => $site_id,
                'directory' => $cache_dir,
            ]);
        } else {
            $this->logger->error('Failed to remove site cache directory', [
                'site_id' => $site_id,
                'directory' => $cache_dir,
            ]);
        }
    }

    /**
     * Builds the deleted site's cache directory from its registered URL.
     *
     * The host and segments sanitize the way the file drop-in names them,
     * so the cleanup matches the on-disk layout.
     *
     * @param string $domain Site domain.
     * @param string $path   Site path, e.g. '/site1/deep/'.
     * @return string|null Directory path, or null when the domain is empty.
     */
    private function get_site_cache_directory(string $domain, string $path): ?string
    {
        $host = strtolower(trim($domain));
        $host = preg_replace('/[^a-zA-Z0-9.-]/', '-', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '') {
            return null;
        }

        $cache_dir = rtrim((string) RUNCACHE_CACHE_DIRECTORY, '/\\') . '/' . $host;

        if (Multisite::is_multisite() && !is_subdomain_install()) {
            $segments = Multisite::sanitize_cache_path_segments($path);
            if ($segments !== []) {
                $cache_dir .= '/' . implode('/', $segments);
            }
        }

        return $cache_dir;
    }
}

<?php

namespace RunCache\Integrations;

use RunCache\Core\Cdn;
use RunCache\Core\ObjectCache;
use RunCache\Core\Setting;
use RunCache\Support\Dropin;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redis object cache integration
 */
class RedisObjectCache extends Redis
{

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        parent::__construct($logger, $setting);
    }

    /**
     * Initialize object cache integration
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_object_cache_providers', [$this, 'register_object_cache_provider']);

        if ($this->is_object_cache_enabled()) {
            add_action('runcache_after_purge_all', [$this, 'purge_object_cache'], 15);
            add_action('runcache_after_purge_network', [$this, 'purge_network_object_cache'], 15);
        }
    }

    /**
     * Register Redis as an object cache provider
     *
     * @param array $providers Existing provider map
     * @return array<string, object> Provider map including Redis
     */
    public function register_object_cache_provider(array $providers): array
    {
        $providers['redis'] = $this;

        return $providers;
    }

    /**
     * Get Redis object cache settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $object_cache = new ObjectCache($this->logger);
        $cache_settings = $object_cache->get_settings();

        // Only accept a saved record with a usable host.
        // An empty record must not block the wp-config constants fallback.
        if (isset($cache_settings['provider']['redis']) && !empty($cache_settings['provider']['redis']['host'])) {
            $settings = $cache_settings['provider']['redis'];
            $this->logger->info('Settings found', [
                'provider' => 'Redis Object Cache',
                'has_host' => !empty($settings['host']),
                'has_port' => !empty($settings['port']),
                'has_key' => !empty($settings['key']),
                'has_username' => !empty($settings['username']),
                'has_password' => !empty($settings['password']),
            ]);
            return $settings;
        }

        $runcache_config = $this->get_constant_config();
        if (!empty($runcache_config)) {
            return $runcache_config;
        }

        $rcwp_config = $this->get_rcwp_settings();
        if (!empty($rcwp_config)) {
            return $rcwp_config;
        }

        // Verification must test what the drop-in resolves, so the
        // shared settings chain applies when no object record exists.
        return parent::get_settings();
    }

    /**
     * Enable Redis object cache
     *
     * The object-cache drop-in reads connection settings from the
     * generated config channel; wp-config.php is never written.
     *
     * @return bool
     */
    public function enable(): bool
    {
        $this->logger->info('Enable object cache', ['provider' => 'Redis Object Cache']);

        $dropin = new Dropin();
        if (!$dropin->install_object_cache_dropin()) {
            $this->logger->error('Failed to install object-cache.php drop-in');
            return false;
        }

        return true;
    }

    /**
     * Disable Redis object cache
     *
     * On a multisite network the drop-in stays installed while a sibling
     * site still has the object cache enabled, because one drop-in serves
     * every site. The caller stamps the disabled state into this site's
     * config row.
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->logger->info('Disable object cache', ['provider' => 'Redis Object Cache']);

        if (Multisite::is_multisite() && $this->network_still_uses_object_cache()) {
            return true;
        }

        $dropin = new Dropin();
        return $dropin->remove_object_cache_dropin();
    }

    /**
     * Checks the config channel for a sibling site that still uses the drop-in.
     *
     * A sibling row without the flag predates the per-site switch and
     * counts as enabled, so an upgrade never removes a drop-in that
     * others rely on. Must stay in sync with
     * runcache_object_cache_persistent_enabled() in includes/object-cache.php.
     *
     * @return bool True when any other site has the object cache on.
     */
    private function network_still_uses_object_cache(): bool
    {
        if (!defined('RUNCACHE_CONFIG_FILE') || !is_readable(RUNCACHE_CONFIG_FILE)) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_include -- Reads the plugin-generated channel the drop-ins include.
        $loaded = include RUNCACHE_CONFIG_FILE;
        if (!is_array($loaded) || !is_array($loaded['sites'] ?? null)) {
            return true;
        }

        $current_key = (new Multisite())->get_config_key();

        foreach ($loaded['sites'] as $row_key => $row) {
            if (!is_array($row) || $row_key === $current_key) {
                continue;
            }

            if (filter_var($row['object_cache_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify Redis object cache connection
     *
     * Four ordered gates prove the database status, the Redis
     * connection, the effective connection, and the drop-in presence,
     * so a proven connection auto-installs a missing drop-in.
     *
     * @return array|bool True when verified, or an error array.
     */
    public function verify(): array|bool
    {
        $this->logger->info('Verification', ['provider' => 'Redis Object Cache']);

        // Gate 1: skip everything when the object cache is disabled in the database.
        if (!$this->is_object_cache_enabled()) {
            return [
                'verified' => false,
                'message' => 'Object cache is disabled.',
            ];
        }

        $settings = $this->get_settings();

        if (empty($settings)) {
            return [
                'verified' => false,
                'message' => 'Redis object cache settings are not available.',
            ];
        }

        // Gate 2: prove connectivity before touching any files.
        try {
            if (!$this->test_connection($settings)) {
                $this->logger->error('Connection test failed', ['provider' => 'Redis Object Cache']);
                return [
                    'verified' => false,
                    'message' => 'Redis object cache connection failed. Check the host, port, username, and password.',
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Connection test failed', ['provider' => 'Redis Object Cache', 'error' => $e->getMessage()]);
            return [
                'verified' => false,
                'message' => 'Redis object cache connection test failed. Please check the settings and try again.',
            ];
        }

        // Verification must prove the drop-in's real resolution, so the
        // channel is rebuilt (idempotent) and read back.
        $configured_settings = $this->get_constant_config();
        if (!empty($configured_settings) && !$this->has_matching_settings($settings, $configured_settings)) {
            return [
                'verified' => false,
                'message' => 'Hand-written RUNCACHE_REDIS_* constants do not match the saved settings.',
            ];
        }

        $expected_connection = $this->get_connection_config();
        if (!empty($expected_connection) && !$this->channel_carries_connection($expected_connection)) {
            return [
                'verified' => false,
                'message' => 'The generated cache config does not carry the Redis connection. Check the cache directory permissions.',
            ];
        }

        // Gate 4: install the drop-in when missing; the connection is already proven.
        $dropin = new Dropin();
        if (!$dropin->is_object_cache_dropin_installed()) {
            if (!$dropin->install_object_cache_dropin()) {
                $this->logger->error('Failed to install object-cache.php drop-in', ['provider' => 'Redis Object Cache']);
                return [
                    'verified' => false,
                    'message' => 'Drop-in installation failed. Please check the wp-content directory permissions.',
                ];
            }

            $this->logger->info('Object cache drop-in installed during verification', ['provider' => 'Redis Object Cache']);
        }

        return true;
    }

    /**
     * Compare saved settings with the constants currently loaded from wp-config.php.
     *
     * @param array $settings Saved object-cache settings.
     * @param array $configured_settings Loaded RunCache Redis constants.
     * @return bool
     */
    private function has_matching_settings(array $settings, array $configured_settings): bool
    {
        foreach (['host', 'port', 'key', 'username', 'password'] as $setting) {
            if ((string) ($settings[$setting] ?? '') !== (string) ($configured_settings[$setting] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuild the generated config channel and confirm it carries the
     * effective Redis connection the drop-in will resolve.
     *
     * @param array $expected_connection Effective connection settings.
     * @return bool
     */
    private function channel_carries_connection(array $expected_connection): bool
    {
        $setting = new Setting();
        $setting->write_config_file();

        // Drop-ins include this channel file before WordPress loads, so
        // the same include reads it back here.
        $channel_redis = [];
        if (defined('RUNCACHE_CONFIG_FILE') && is_readable(RUNCACHE_CONFIG_FILE)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_include -- Reads the plugin-generated channel the drop-ins include.
            $loaded = include RUNCACHE_CONFIG_FILE;
            $config_key = (new Multisite())->get_config_key();
            $site = is_array($loaded) ? ($loaded['sites'][$config_key] ?? null) : null;
            if (is_array($site) && is_array($site['redis'] ?? null)) {
                $channel_redis = $site['redis'];
            }
        }

        return !empty($channel_redis) && $this->has_matching_settings($expected_connection, $channel_redis);
    }

    /**
     * Get scoped key prefix for object cache reads.
     *
     * Global groups are network-wide, so the scope is one network and
     * the identity is the writer-baked install identifier.
     *
     * @param string|null $base_key Optional base-prefix override.
     * @return string
     */
    protected function get_scoped_key_prefix(?string $base_key = null): string
    {
        $base = rtrim($this->get_key_prefix($base_key), ':');
        $identifier = Multisite::object_cache_identifier();

        if ('' !== $identifier) {
            return $base . ':' . $identifier . ':';
        }

        return $base . ':';
    }

    /**
     * Retrieve an object directly from Redis
     *
     * @param string $key Cache key
     * @return mixed The stored value or false when not found
     */
    public function get_object(string $key)
    {
        try {
            $redis = $this->get_connection();
            $prefix = $this->get_scoped_key_prefix() . 'object:';

            return $redis->get($prefix . $key);
        } catch (\Exception $exception) {
            $this->logger->error('Failed to get object', [
                'provider' => 'Redis Object Cache',
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Determine whether Redis object cache is enabled
     *
     * @return bool
     */
    private function is_object_cache_enabled(): bool
    {
        $object_cache = new ObjectCache($this->logger);
        $settings = $object_cache->get_settings();

        return !empty($settings['enable']) && $settings['object_cache_type'] === 'redis';
    }

    /**
     * Purge Redis object cache keys when the site purge action runs
     *
     * wp_cache_flush() clears the current blog only, so a site purge no
     * longer wipes the sibling sites' object cache.
     *
     * @return void
     */
    public function purge_object_cache(): void
    {
        if (!$this->is_object_cache_enabled()) {
            return;
        }

        if (!function_exists('wp_cache_flush')) {
            $this->logger->error('Object-cache flush function is unavailable', [
                'provider' => 'Redis Object Cache',
            ]);
            return;
        }

        try {
            if (wp_cache_flush()) {
                $this->logger->info('Object cache flushed successfully', [
                    'provider' => 'Redis Object Cache',
                    'scope' => 'site',
                ]);
            } else {
                $this->logger->error('Object-cache flush failed', [
                    'provider' => 'Redis Object Cache',
                    'scope' => 'site',
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to run wp_cache_flush()', [
                'provider' => 'Redis Object Cache',
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Purge every object-cache key of the whole network
     *
     * Hooked to the network purge only.
     *
     * @return void
     */
    public function purge_network_object_cache(): void
    {
        if (!$this->is_object_cache_enabled()) {
            return;
        }

        if (!function_exists('wp_cache_flush_network')) {
            $this->logger->error('Network object-cache flush function is unavailable', [
                'provider' => 'Redis Object Cache',
            ]);
            return;
        }

        try {
            if (wp_cache_flush_network()) {
                $this->logger->info('Network object cache flushed successfully', [
                    'provider' => 'Redis Object Cache',
                    'scope' => 'network',
                ]);
            } else {
                $this->logger->error('Network object-cache flush failed', [
                    'provider' => 'Redis Object Cache',
                    'scope' => 'network',
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to run wp_cache_flush_network()', [
                'provider' => 'Redis Object Cache',
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}

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

        return [];
    }

    /**
     * Enable Redis object cache
     *
     * @return bool
     */
    public function enable(): bool
    {
        $this->logger->info('Enable object cache', ['provider' => 'Redis Object Cache']);

        $settings = $this->get_settings();
        if (empty($settings)) {
            $this->logger->error('Cannot enable Redis object cache: no settings available');
            return false;
        }

        try {
            $this->update_wp_config($settings);
        } catch (\Exception $e) {
            $this->logger->error('Failed to write Redis object cache constants to wp-config.php', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

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
     * @return bool
     */
    public function disable(): bool
    {
        if (!$this->is_other_cache_using_redis()) {
            $this->update_wp_config([]);
        }

        $this->logger->info('Disable object cache', ['provider' => 'Redis Object Cache']);

        $dropin = new Dropin();
        return $dropin->remove_object_cache_dropin();
    }

    /**
     * Verify Redis object cache connection
     *
     * Runs four ordered gates: database status, Redis connection,
     * wp-config constants, and drop-in presence. A missing drop-in
     * is installed automatically once the connection is proven.
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

        // Gate 3: the drop-in reads these constants, so they must match the saved settings.
        $configured_settings = $this->get_constant_config();
        if (empty($configured_settings) || !$this->has_matching_settings($settings, $configured_settings)) {
            return [
                'verified' => false,
                'message' => 'Redis object cache constants are missing or do not match the saved settings.',
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
     * Purge Redis object cache keys when the global purge action runs
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
                ]);
            } else {
                $this->logger->error('Object-cache flush failed', [
                    'provider' => 'Redis Object Cache',
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to run wp_cache_flush()', [
                'provider' => 'Redis Object Cache',
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}

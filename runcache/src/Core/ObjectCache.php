<?php

namespace RunCache\Core;

use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Object cache management and utilities
 */
class ObjectCache
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings = [
        'enable' => false,
        'object_cache_type' => '',
    ];

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
     * Initialize object cache functionality
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_get_object_cache_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_object_cache_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_verify_object_cache', [$this, 'ajax_verify_object_cache']);
    }

    /**
     * Get object cache settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $settings = wp_parse_args($this->setting->get('object_cache'), $this->default_settings);
        return apply_filters('runcache_object_cache_settings', $settings);
    }

    /**
     * Get object cache provider by key
     *
     * @param string $key
     * @return object|false
     */
    public function get_object_cache_provider(string $key): object|false
    {
        if (empty($key)) {
            return false;
        }

        $object_cache_providers = apply_filters('runcache_object_cache_providers', []);

        if (isset($object_cache_providers[$key]) && is_object($object_cache_providers[$key])) {
            return $object_cache_providers[$key];
        }

        if (!empty($object_cache_providers)) {
            $this->logger->warning('Invalid provider requested', [
                'component' => 'ObjectCache',
                'key' => $key,
                'available' => array_keys($object_cache_providers),
            ]);
        }

        return false;
    }

    /**
     * Save object cache settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        $previous_settings = $this->get_settings();
        $previous_cache_type = $previous_settings['object_cache_type'] ?? 'redis';

        $settings = wp_parse_args($settings, $this->default_settings);
        $result = $this->setting->set('object_cache', $settings);

        if (!$result) {
            return false;
        }

        $cache_type = $settings['object_cache_type'] ?? 'redis';
        $provider_result = true;

        if ($settings['enable']) {
            if ($previous_cache_type !== $cache_type) {
                $previous_provider = $this->get_object_cache_provider($previous_cache_type);
                if ($previous_provider) {
                    $previous_provider->disable();
                }
            }

            $new_provider = $this->get_object_cache_provider($cache_type);
            if ($new_provider) {
                $provider_result = $new_provider->enable();
            }
        } else {
            $previous_provider = $this->get_object_cache_provider($previous_cache_type);
            if ($previous_provider) {
                $provider_result = $previous_provider->disable();
            }
        }

        do_action('runcache_object_cache_settings_saved', $settings);

        return $result && $provider_result;
    }

    /**
     * Verify object cache setup
     *
     * @return array
     */
    public function verify_object_cache(): array
    {
        $settings = $this->get_settings();

        if (!$settings['enable']) {
            return [
                'type' => $this->default_settings['object_cache_type'],
                'message' => 'Object cache is disabled',
                'verified' => false
            ];
        }

        $cache_type = $settings['object_cache_type'] ?? 'redis';
        $provider = $this->get_object_cache_provider($cache_type);

        if (!$provider) {
            return [
                'type' => $this->default_settings['object_cache_type'],
                'message' => 'Object cache provider not found',
                'verified' => false
            ];
        }

        $verified = $provider->verify();

        if (is_array($verified)) {
            return [
                'type' => $cache_type,
                'message' => $verified['message'],
                'verified' => $verified['verified']
            ];
        }

        return [
            'type' => $cache_type,
            'message' => '',
            'verified' => $verified
        ];
    }

    /**
     * Enable object cache
     *
     * @return bool True if the provider was enabled successfully.
     */
    public function enable(): bool
    {
        $settings = $this->get_settings();
        $settings['enable'] = true;
        $settings['object_cache_type'] = 'redis';
        return $this->save_settings($settings);
    }

    /**
     * Disable object cache
     *
     * Routes through save_settings() (mirroring enable()) so the
     * provider's disable() runs — which removes the object-cache
     * drop-in via Dropin::remove_object_cache_dropin().
     *
     * @return void
     */
    public function disable(): void
    {
        $settings = $this->get_settings();
        $settings['enable'] = false;
        $this->save_settings($settings);
    }

    /**
     * AJAX handler for getting object cache settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success($this->get_settings());
    }

    /**
     * AJAX handler for saving object cache settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();
        $settings = $this->validator->validate_ajax_settings();

        $result = $this->save_settings($settings);

        $result
            ? wp_send_json_success($this->get_settings())
            : wp_send_json_error(['message' => 'Failed to save settings']);
    }

    /**
     * AJAX handler for verifying object cache
     *
     * @return void
     */
    public function ajax_verify_object_cache(): void
    {
        $this->validator->validate_ajax_request();
        wp_send_json_success($this->verify_object_cache());
    }
}

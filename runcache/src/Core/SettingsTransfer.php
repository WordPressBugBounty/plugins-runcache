<?php

namespace RunCache\Core;

use Exception;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles importing and exporting RunCache settings as a single bundle.
 *
 * Provides AJAX endpoints that the SPA can use to generate a JSON snapshot
 * of all relevant configuration (cache, purger, preloader, rules, CDN, etc.)
 * and to restore those settings on another site.
 */
class SettingsTransfer
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings = [
        'supported_keys' => [
            'page_cache',
            'purger',
            'preloader',
            'rules',
            'object_cache',
            'cdn',
            'edge',
        ],
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
        $this->setting = $setting ?? new Setting($this->logger, $this->validator);
    }

    /**
     * Register AJAX endpoints.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_runcache_import_settings', [$this, 'ajax_import_settings']);
    }

    /**
     * AJAX handler for exporting settings.
     *
     * @return void
     */
    public function ajax_export_settings(): void
    {
        $this->validator->validate_ajax_request();

        $bundle = $this->build_export_bundle();

        wp_send_json_success([
            'message' => __('Settings bundle generated successfully.', 'runcache'),
            'filename' => $bundle['filename'],
            'settings' => $bundle['payload'],
        ]);
    }

    /**
     * AJAX handler for importing settings.
     *
     * @return void
     */
    public function ajax_import_settings(): void
    {
        $this->validator->validate_ajax_request();

        $decoded = $this->validator->validate_ajax_settings('settings_payload');

        $settings_data = $this->extract_settings_array($decoded);

        if (empty($settings_data)) {
            wp_send_json_error(['message' => __('No compatible settings were found in this file.', 'runcache')]);
        }

        $updated = [];

        foreach ($settings_data as $key => $config) {
            if (!in_array($key, $this->default_settings['supported_keys'], true) || !is_array($config)) {
                continue;
            }

            try {
                if ($this->save_module_settings($key, $config)) {
                    $updated[] = $key;
                }
            } catch (Exception $exception) {
                $this->logger->error(
                    sprintf('Failed to import settings for %s', $key),
                    ['error' => $exception->getMessage()]
                );
            }
        }

        if (empty($updated)) {
            wp_send_json_error(['message' => __('No settings were imported. Please verify the file contents.', 'runcache')]);
        }

        wp_send_json_success([
            'message' => __('Settings imported successfully.', 'runcache'),
            'updated' => $updated,
        ]);
    }

    /**
     * Build the payload + filename returned to the client.
     *
     * @return array{filename: string, payload: array<string, mixed>}
     */
    private function build_export_bundle(): array
    {
        $payload = [
            'schema' => 'runcache/settings',
            'version' => defined('RUNCACHE_VERSION') ? RUNCACHE_VERSION : '1.0.0',
            'generated_at' => current_time('mysql'),
            'site' => [
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'name' => get_bloginfo('name'),
            ],
            'settings' => $this->collect_settings(),
        ];

        return [
            'filename' => sprintf('runcache-settings-%s.json', date_i18n('Ymd-His')),
            'payload' => $payload,
        ];
    }

    /**
     * Gather the latest settings for all supported modules.
     *
     * @return array<string, mixed>
     */
    private function collect_settings(): array
    {
        $page_cache = (new PageCache($this->logger, $this->validator, $this->setting))->get_settings();
        $purger = (new Purger($this->logger, $this->validator))->get_settings();

        $preloader = wp_parse_args(
            $this->setting->get('preloader', []),
            (new Preloader($this->logger, $this->validator))->get_default_settings()
        );

        $rules = (new Rules($this->logger, $this->validator))->get_settings();
        $object_cache = (new ObjectCache($this->logger, $this->validator, $this->setting))->get_settings();
        $cdn = (new Cdn($this->logger, $this->validator))->get_settings();
        $edge = (new Edge($this->logger, $this->validator, $this->setting))->get_settings();

        return [
            'page_cache' => $page_cache,
            'purger' => $purger,
            'preloader' => $preloader,
            'rules' => $rules,
            'object_cache' => $object_cache,
            'cdn' => $cdn,
            'edge' => $edge,
        ];
    }

    /**
     * Extract the settings array regardless of how the JSON file is structured.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extract_settings_array(array $payload): array
    {
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            return $payload['settings'];
        }

        if (isset($payload['payload']['settings']) && is_array($payload['payload']['settings'])) {
            return $payload['payload']['settings'];
        }

        $intersect = array_intersect(array_keys($payload), $this->default_settings['supported_keys']);

        if (!empty($intersect)) {
            return $payload;
        }

        return [];
    }

    /**
     * Persist settings for a supported module.
     *
     * @param string $key
     * @param array<string, mixed> $settings
     * @return bool
     */
    private function save_module_settings(string $key, array $settings): bool
    {
        switch ($key) {
            case 'page_cache':
                return (new PageCache($this->logger, $this->validator, $this->setting))->save_settings($settings);
            case 'purger':
                return (new Purger($this->logger, $this->validator))->save_settings($settings);
            case 'preloader':
                return (new Preloader($this->logger, $this->validator))->save_settings($settings);
            case 'rules':
                return (new Rules($this->logger, $this->validator))->save_settings($settings);
            case 'object_cache':
                return (new ObjectCache($this->logger, $this->validator, $this->setting))->save_settings($settings);
            case 'cdn':
                return (new Cdn($this->logger, $this->validator))->save_settings($settings);
            case 'edge':
                return (new Edge($this->logger, $this->validator, $this->setting))->save_settings($settings);
            default:
                return false;
        }
    }
}

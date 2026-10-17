<?php

namespace RunCache\Core;

use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores CDN edge-cache selection and provider settings.
 */
class Edge
{
    public const OPTION_KEY = 'edge';
    public const CLOUDFLARE = 'cloudflare';
    public const CLOUDFLARE_APO = 'cloudflare-apo';

    private Setting $setting;

    public function __construct(
        ?Logger $logger = null,
        ?Validator $validator = null,
        ?Setting $setting = null
    ) {
        $this->setting = $setting ?? new Setting();
    }

    public function init(): void
    {
        add_action('wp_ajax_runcache_get_edge_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_edge_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_runcache_verify_edge', [$this, 'ajax_verify']);
    }

    private function get_provider(string $provider): ?object
    {
        return match ($provider) {
            self::CLOUDFLARE => new \RunCache\Integrations\Cloudflare(null, $this->setting),
            self::CLOUDFLARE_APO => new \RunCache\Integrations\CloudflareApo(null, $this->setting),
            default => null,
        };
    }

    public function get_settings(): array
    {
        $saved = $this->setting->get(self::OPTION_KEY, []);
        $saved = is_array($saved) ? $saved : [];
        $settings = wp_parse_args($saved, ['enable' => false, 'edge_type' => '']);
        $settings['provider'] = isset($saved['provider']) && is_array($saved['provider'])
            ? $saved['provider']
            : [];

        return $settings;
    }

    public function get_provider_settings(string $provider): array
    {
        $settings = $this->get_settings();
        return isset($settings['provider'][$provider]) && is_array($settings['provider'][$provider])
            ? $settings['provider'][$provider]
            : [];
    }

    public function is_active(string $provider): bool
    {
        $settings = $this->get_settings();
        return !empty($settings['enable']) && ($settings['edge_type'] ?? '') === $provider;
    }

    /** Save one provider and make it the sole active edge provider. */
    public function save_provider(string $provider, array $provider_settings, bool $enable = true): bool
    {
        if (!in_array($provider, [self::CLOUDFLARE, self::CLOUDFLARE_APO], true)) {
            return false;
        }

        $saved = $this->setting->get(self::OPTION_KEY, []);
        $saved = is_array($saved) ? $saved : [];
        $providers = isset($saved['provider']) && is_array($saved['provider']) ? $saved['provider'] : [];
        $previous_provider = !empty($saved['enable']) ? (string) ($saved['edge_type'] ?? '') : '';

        $providers[$provider] = $provider_settings;

        $result = $this->setting->set(self::OPTION_KEY, [
            'enable' => $enable,
            'edge_type' => $enable ? $provider : '',
            'provider' => $providers,
        ]);

        if ($result && $enable) {
            if ($previous_provider !== '' && $previous_provider !== $provider) {
                $this->get_provider($previous_provider)?->disable();
            }
            $this->get_provider($provider)?->enable();
        }

        if ($result) {
            do_action('runcache_edge_settings_saved', $this->get_settings(), $previous_provider);
        }

        return $result;
    }

    public function save_settings(array $settings): bool
    {
        $enable = !empty($settings['enable']);
        $provider = sanitize_key((string) ($settings['edge_type'] ?? ''));
        $providers = $settings['provider'] ?? [];

        // A settings import is a snapshot restore, so replace the whole option
        // instead of merging provider credentials with the destination site.
        if (!is_array($providers)) {
            return false;
        }

        foreach ($providers as $provider_key => $provider_settings) {
            if (!is_string($provider_key) || !is_array($provider_settings)) {
                return false;
            }
        }

        if ($enable && !in_array($provider, [self::CLOUDFLARE, self::CLOUDFLARE_APO], true)) {
            return false;
        }

        $saved = $this->get_settings();
        $previous_provider = !empty($saved['enable']) ? (string) ($saved['edge_type'] ?? '') : '';
        $restored = $settings;
        $restored['enable'] = $enable;
        $restored['edge_type'] = $enable ? $provider : '';
        $restored['provider'] = $providers;

        $result = $this->setting->set(self::OPTION_KEY, $restored);
        if (!$result) {
            return false;
        }

        if ($previous_provider !== '' && (!$enable || $previous_provider !== $provider)) {
            $this->get_provider($previous_provider)?->disable();
        }

        if ($enable && ($previous_provider !== $provider || !$this->get_provider($provider))) {
            $this->get_provider($provider)?->enable();
        }

        do_action('runcache_edge_settings_saved', $this->get_settings(), $previous_provider);

        return true;
    }

    public function ajax_get_settings(): void
    {
        (new \RunCache\Support\Validator())->validate_ajax_request();
        wp_send_json_success($this->get_settings());
    }

    public function ajax_save_settings(): void
    {
        $validator = new \RunCache\Support\Validator();
        $validator->validate_ajax_request();
        $settings = $validator->validate_ajax_settings();

        // Toggle semantics: empty or missing provider blocks keep the stored
        // blocks, so no client can wipe saved credentials through this handler.
        $stored = $this->get_settings();
        $stored_providers = is_array($stored['provider'] ?? null) ? $stored['provider'] : [];
        if (!isset($settings['provider']) || !is_array($settings['provider'])) {
            $settings['provider'] = $stored_providers;
        }
        foreach ($settings['provider'] as $key => $block) {
            if (is_string($key) && empty($block) && isset($stored_providers[$key])) {
                $settings['provider'][$key] = $stored_providers[$key];
            }
        }

        if (!$this->save_settings($settings)) {
            wp_send_json_error(['message' => 'Failed to save edge settings'], 400);
        }

        $message = !empty($settings['enable'])
            ? $this->get_enable_message($settings['edge_type'] ?? '')
            : __('CDN Edge Cache disabled successfully', 'runcache');

        wp_send_json_success([
            'message' => $message,
            'data' => $this->get_settings(),
        ]);
    }

    private function get_enable_message(string $provider): string
    {
        $integration = $this->get_provider($provider);
        $verification = $integration?->verify() ?? ['verified' => false];

        if (!empty($verification['verified'])) {
            return match ($provider) {
                self::CLOUDFLARE => __('Cloudflare enabled and working', 'runcache'),
                self::CLOUDFLARE_APO => __('Cloudflare APO enabled and working', 'runcache'),
                default => __('CDN Edge Cache enabled and working', 'runcache'),
            };
        }

        return !empty($verification['message'])
            ? (string) $verification['message']
            : match ($provider) {
                self::CLOUDFLARE => __('Cloudflare enabled. Please configure your API credentials.', 'runcache'),
                self::CLOUDFLARE_APO => __('Cloudflare APO enabled. Please configure your API credentials.', 'runcache'),
                default => __('CDN Edge Cache enabled. Please configure your provider.', 'runcache'),
            };
    }

    public function ajax_verify(): void
    {
        (new \RunCache\Support\Validator())->validate_ajax_request();
        $settings = $this->get_settings();
        $provider = $settings['edge_type'] ?? '';
        $integration = match ($provider) {
            self::CLOUDFLARE => new \RunCache\Integrations\Cloudflare(null, $this->setting),
            self::CLOUDFLARE_APO => new \RunCache\Integrations\CloudflareApo(null, $this->setting),
            default => null,
        };

        if ($integration === null) {
            wp_send_json_success([
                'message' => __('Edge cache provider is not configured.', 'runcache'),
                'edge' => [
                    'type' => '',
                    'verified' => false,
                ],
            ]);
        }

        $verification = $integration->verify();

        wp_send_json_success([
            'message' => $verification['message'] ?? '',
            'edge' => [
                'type' => $provider,
                'verified' => (bool) ($verification['verified'] ?? false),
            ],
        ]);
    }

}

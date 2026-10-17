<?php

namespace RunCache\Core;

use RunCache\Core\Setting;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rules class handles cache rules and exclusion settings
 */
class Rules
{
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings;

    /**
     * @return array<string, mixed>
     */
    private static function load_default_settings(): array
    {
        $defaults_file = defined('RUNCACHE_PLUGIN_DIR')
            ? RUNCACHE_PLUGIN_DIR . 'includes/runcache-rule-defaults.php'
            : dirname(__DIR__, 2) . '/includes/runcache-rule-defaults.php';
        $defaults = is_readable($defaults_file) ? require $defaults_file : [];

        return is_array($defaults) ? $defaults : [];
    }

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

        $defaults = self::load_default_settings();
        $this->default_settings = is_array($defaults['rules'] ?? null) ? $defaults['rules'] : [];
    }

    /**
     * Initialize rules functionality
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_get_rules_settings', [$this, 'ajax_get_settings']);
        add_action('wp_ajax_runcache_save_rules_settings', [$this, 'ajax_save_settings']);
    }

    /**
     * Get cache settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $saved = $this->setting->get('rules', null);
        $settings = is_array($saved) ? array_replace($this->default_settings, $saved) : $this->default_settings;

        return apply_filters('runcache_rules_settings', $settings);
    }

    /**
     * Normalize a Rules toggle value at the API boundary.
     *
     * @param mixed $value
     * @param bool  $default
     * @return bool
     */
    private function normalize_boolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Save cache settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        $defaults = $this->default_settings;
        $settings = wp_parse_args($settings, $defaults);
        $validated = [];

        $bool_settings = [
            'exclude_url_onn',
            'exclude_cookie_onn',
            'exclude_auto_onn',
            'exclude_browser_onn',
            'exclude_visitorip_onn',
            'ignore_query_onn',
            'allow_query_onn',
            'exclude_query_onn',
            'cache_key_extra_onn'
        ];

        foreach ($bool_settings as $key) {
            $validated[$key] = array_key_exists($key, $settings)
                ? $this->normalize_boolean($settings[$key], (bool) $defaults[$key])
                : (bool) $defaults[$key];
        }

        $text_settings = [
            'cache_key_extra_var'
        ];

        foreach ($text_settings as $key) {
            if (isset($settings[$key])) {
                $validated[$key] = sanitize_textarea_field($settings[$key]);
            } else {
                $validated[$key] = $defaults[$key];
            }
        }

        // Handle all _mch settings - can be either string or array (backward compatible)
        $mch_settings = [
            'exclude_url_mch',
            'exclude_cookie_mch',
            'exclude_browser_mch',
            'exclude_visitorip_mch',
            'ignore_query_mch',
            'allow_query_mch',
            'exclude_query_mch',
        ];

        foreach ($mch_settings as $key) {
            if (isset($settings[$key])) {
                if (is_array($settings[$key])) {
                    // Already an array, sanitize each element
                    $validated[$key] = array_map('sanitize_text_field', $settings[$key]);
                    $validated[$key] = array_values(array_filter(array_map('trim', $validated[$key]))); // Re-index array
                } else {
                    // String format (from textarea), convert to array
                    $string_value = sanitize_textarea_field($settings[$key]);
                    $validated[$key] = array_values(array_filter(array_map('trim', explode("\n", $string_value)))); // Re-index array
                }
            } else {
                $validated[$key] = $defaults[$key];
            }
        }

        $result = $this->setting->set('rules', $validated);

        if ($result) {
            do_action('runcache_rules_updated', $validated);
            $this->logger->info('Settings saved successfully', ['component' => 'Rules']);
        } else {
            $this->logger->error('Failed to save rules settings');
        }

        return $result;
    }

    /**
     * AJAX handler for getting cache settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        $this->validator->validate_ajax_request();
        $settings = $this->get_settings();

        // Convert arrays to newline-separated strings for frontend textareas
        foreach ($settings as $key => $value) {
            if (str_ends_with($key, '_mch') && is_array($value)) {
                $settings[$key] = implode("\n", $value);
            }
        }

        wp_send_json_success(['settings' => $settings]);
    }

    /**
     * AJAX handler for saving cache settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        $this->validator->validate_ajax_request();
        $settings = $this->validator->validate_ajax_settings();

        $result = $this->save_settings($settings);

        if ($result) {
            $settings = $this->get_settings();

            // Convert arrays to newline-separated strings for frontend textareas
            foreach ($settings as $key => $value) {
                if (str_ends_with($key, '_mch') && is_array($value)) {
                    $settings[$key] = implode("\n", $value);
                }
            }

            wp_send_json_success(['settings' => $settings]);
        } else {
            wp_send_json_error(['message' => 'Failed to save settings']);
        }
    }

}

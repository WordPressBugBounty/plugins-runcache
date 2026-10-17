<?php

namespace RunCache\Support;

use RunCache\Support\Logger;

/**
 * Validator handles security validation and input sanitization
 */
class Validator
{
    private Logger $logger;
    private array $default_dangerous_patterns = [
        '..',
        'php://',
        'data://',
        'file://',
        'ftp://',
        'javascript:',
        'vbscript:',
        'onload=',
        'onerror=',
        'onclick=',
        '<script',
        'eval(',
        'base64_decode'
    ];

    /**
     * @param Logger|null $logger
     */
    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Initialize validator hooks
     *
     * @return void
     */
    public function init(): void
    {
        // Validator primarily handles validation logic, not WordPress hooks
        // This method is available for future extensibility
        do_action('runcache_validator_initialized', $this);
    }

    /**
     * Verify nonce for AJAX requests
     *
     * @param string $nonce Nonce value to validate
     * @param string $action Nonce action key
     * @return int|false
     */
    public function verify_nonce(string $nonce, string $action = 'runcache_nonce'): int|false
    {
        return wp_verify_nonce($nonce, $action);
    }

    /**
     * Create nonce for AJAX requests
     *
     * @param string $action Nonce action key
     * @return string
     */
    public function create_nonce(string $action = 'runcache_nonce'): string
    {
        return wp_create_nonce($action);
    }

    /**
     * Check if user has required capabilities
     *
     * @param string $capability Capability to validate
     * @return bool
     */
    public function check_capability(string $capability = 'manage_options'): bool
    {
        return current_user_can($capability);
    }

    /**
     * Sanitize and validate URL
     *
     * @param string $url URL to sanitize
     * @return string|false
     */
    public function sanitize_url(string $url): string|false
    {
        $sanitized_url = filter_var(trim($url), FILTER_SANITIZE_URL);

        if (!filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return $sanitized_url ?: '';
    }

    /**
     * Normalize a list of URLs, sanitizing and ensuring uniqueness
     *
     * @param array<string|int, mixed>|string $urls URLs to normalize
     * @return array<int, string>
     */
    public function normalize_url_list(array|string $urls): array
    {
        if (is_string($urls)) {
            $urls = [$urls];
        }

        $normalized = [];

        foreach ($urls as $url) {
            if (is_string($url) && $url !== '') {
                $sanitized = $this->sanitize_url($url);
                if ($sanitized !== false && $sanitized !== '') {
                    $normalized[] = $sanitized;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Sanitize and validate URL pattern
     *
     * @param string $pattern URL pattern to sanitize
     * @return string|false
     */
    public function sanitize_url_pattern(string $pattern = ''): string|false
    {
        $pattern = trim($pattern);
        $pattern = preg_replace('/[\x00-\x1F\x7F]/', '', $pattern);

        $pattern = preg_replace('/[^a-zA-Z0-9\-\_\/\.\?\=\&\%\:\*\[\]]/', '', $pattern);

        if (empty($pattern)) {
            return false;
        }

        foreach ($this->default_dangerous_patterns as $dangerous) {
            if (stripos($pattern, $dangerous) !== false) {
                return false;
            }
        }

        return $pattern;
    }

    /**
     * Sanitize integer value
     *
     * @param mixed $value Value to sanitize
     * @param int|null $min Minimum allowed value
     * @param int|null $max Maximum allowed value
     * @return int
     */
    public function sanitize_int(mixed $value, ?int $min = null, ?int $max = null): int
    {
        $int_value = filter_var($value, FILTER_VALIDATE_INT);
        if ($int_value === false) {
            $int_value = 0;
        }

        $int_value = abs($int_value);

        if ($min !== null && $int_value < $min) {
            return $min;
        }

        if ($max !== null && $int_value > $max) {
            return $max;
        }

        return $int_value;
    }

    /**
     * Sanitize boolean value
     *
     * @param mixed $value Value to sanitize
     * @return bool
     */
    public function sanitize_bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower($value);
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Sanitize array of strings
     *
     * @param array<mixed> $array Array to sanitize
     * @return array<int, string>
     */
    public function sanitize_string_array(array $array): array
    {
        $sanitized = [];

        foreach ($array as $item) {
            if (is_string($item)) {
                $item = trim($item);
                $item = preg_replace('/[\x00-\x1F\x7F]/', '', $item);
                if ($item !== '') {
                    $sanitized[] = $item;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Validate cache rule structure
     *
     * @param array<string, mixed> $rule Rule data to validate
     * @return bool
     */
    public function validate_cache_rule(array $rule): bool
    {
        if (!isset($rule['pattern']) || !isset($rule['action'])) {
            return false;
        }

        $pattern = $this->sanitize_url_pattern($rule['pattern']);
        if ($pattern === false) {
            return false;
        }

        $valid_actions = ['cache', 'no_cache'];
        if (!in_array($rule['action'], $valid_actions, true)) {
            return false;
        }

        if (isset($rule['priority'])) {
            $valid_priorities = ['low', 'medium', 'high'];
            if (!in_array($rule['priority'], $valid_priorities, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate settings array
     *
     * @param array<string, mixed> $settings Settings payload to validate
     * @return array<string, mixed>|false
     */
    public function validate_settings(array $settings): array|false
    {
        $validated = [];

        if (isset($settings['settings']) && is_array($settings['settings'])) {
            $validated['settings'] = [];
            $core_settings = $settings['settings'];

            $bool_settings = ['enable_caching', 'gzip_compression'];
            foreach ($bool_settings as $setting) {
                if (isset($core_settings[$setting])) {
                    $validated['settings'][$setting] = $this->sanitize_bool($core_settings[$setting]);
                }
            }

            if (isset($core_settings['cache_expiration'])) {
                $validated['settings']['cache_expiration'] = $this->sanitize_int($core_settings['cache_expiration'], 60, 86400);
            }

            if (isset($core_settings['exclude_urls'])) {
                $validated['settings']['exclude_urls'] = $this->sanitize_string_array($core_settings['exclude_urls']);
            }

            if (isset($core_settings['include_urls'])) {
                $validated['settings']['include_urls'] = $this->sanitize_string_array($core_settings['include_urls']);
            }

            if (isset($core_settings['cache_rules']) && is_array($core_settings['cache_rules'])) {
                $validated['settings']['cache_rules'] = [];
                foreach ($core_settings['cache_rules'] as $rule) {
                    if ($this->validate_cache_rule($rule)) {
                        $validated['settings']['cache_rules'][] = $rule;
                    }
                }
            }

            $validated['settings'] = apply_filters('runcache_validated_settings', $validated['settings']);
        }

        return $validated;
    }

    /**
     * Validate AJAX request with standard RunCache requirements
     *
     * @return void
     */
    public function validate_ajax_request(): void
    {
        if (!wp_doing_ajax()) {
            wp_send_json_error('Not an AJAX request');
        }

        if (!check_ajax_referer('runcache_nonce', '_ajax_nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    }

    /**
     * Escape output for display
     *
     * @param mixed $output Value to escape
     * @return mixed
     */
    public function escape_output(mixed $output): mixed
    {
        if (is_string($output)) {
            return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
        }

        if (is_array($output)) {
            return array_map([$this, 'escape_output'], $output);
        }

        return $output;
    }

    /**
     * Validate AJAX settings from POST request
     *
     * @param string $key The POST key containing settings
     * @param bool $detailed_errors Whether to return detailed error messages
     * @return array<string, mixed>
     */
    public function validate_ajax_settings(string $key = 'settings', bool $detailed_errors = true): array
    {
        // Self-contained nonce verification so this method is safe on its own,
        // regardless of caller. All RunCache AJAX handlers send the same
        // 'runcache_nonce' action in '_ajax_nonce' (see validate_ajax_request()).
        if (!check_ajax_referer('runcache_nonce', '_ajax_nonce', false)) {
            $message = $detailed_errors
                ? ['message' => 'Invalid nonce']
                : 'Invalid nonce';
            wp_send_json_error($message);
        }

        if (!current_user_can('manage_options')) {
            $message = $detailed_errors
                ? ['message' => 'Insufficient permissions']
                : 'Insufficient permissions';
            wp_send_json_error($message);
        }

        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            $message = $detailed_errors
                ? ['message' => 'No settings data provided']
                : 'No settings data provided';
            wp_send_json_error($message);
        }

        // json_decode() gets the data. sanitize_settings_array() below sanitizes it.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization runs on the decoded array.
        $raw_settings = wp_unslash($_POST[$key]);

        if (trim($raw_settings) === '') {
            $message = $detailed_errors
                ? ['message' => 'No settings data provided']
                : 'No settings data provided';
            wp_send_json_error($message);
        }

        if (strlen($raw_settings) > 1048576) {
            $message = $detailed_errors
                ? ['message' => 'Settings payload too large']
                : 'Settings payload too large';
            wp_send_json_error($message);
        }

        $settings = json_decode($raw_settings, true, 12);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $message = $detailed_errors
                ? ['message' => 'Invalid JSON: ' . json_last_error_msg()]
                : 'Invalid JSON format';
            wp_send_json_error($message);
        }

        if (!is_array($settings)) {
            $message = $detailed_errors
                ? ['message' => 'Settings must be an object']
                : 'Invalid settings format';
            wp_send_json_error($message);
        }

        return $this->sanitize_settings_array($settings);
    }

    /**
     * Recursively sanitizes decoded JSON settings.
     *
     * @param array<int|string, mixed> $settings Decoded JSON data
     * @return array<int|string, mixed> Sanitized data
     */
    private function sanitize_settings_array(array $settings): array
    {
        // Caps stop deep nesting and large payloads.
        $max_nodes = 1000;
        $max_depth = 8;
        $max_key_length = 100;
        $remaining = $max_nodes;

        // Keys keep safe name characters. Case and dashes stay.
        $sanitize_key = static function (mixed $key) use ($max_key_length): string {
            $name = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
            return substr($name, 0, $max_key_length);
        };

        // Unique sentinel. Dropped entries and legit null values stay distinct.
        $dropped = new \stdClass();

        $walk = null;
        $walk = static function (mixed $value, int $depth) use (&$walk, &$remaining, $sanitize_key, $max_depth, $dropped): mixed {
            if ($remaining <= 0) {
                return $dropped;
            }
            $remaining--;

            if (is_array($value)) {
                if ($depth >= $max_depth) {
                    return $dropped;
                }
                $clean = [];
                foreach ($value as $key => $item) {
                    $key_name = $sanitize_key($key);
                    if ($key_name === '' || array_key_exists($key_name, $clean)) {
                        continue;
                    }
                    $clean_item = $walk($item, $depth + 1);
                    if ($clean_item === $dropped) {
                        continue;
                    }
                    $clean[$key_name] = $clean_item;
                }
                return $clean === [] ? $dropped : $clean;
            }

            if (is_string($value)) {
                // Keeps newlines for textarea round-trips. Strips tags.
                return sanitize_textarea_field($value);
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                return $value;
            }

            return $dropped;
        };

        $sanitized = $walk($settings, 0);
        return $sanitized === $dropped || !is_array($sanitized) ? [] : $sanitized;
    }

    /**
     * Log security event
     *
     * @param string $event Event identifier
     * @param array<string, mixed> $details Additional event data
     * @return void
     */
    public function log_security_event(string $event, array $details = []): void
    {
        if ($this->is_debug_mode_enabled()) {
            $details['category'] = 'security';
            $this->logger->info($event, $details);
        }
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    private function is_debug_mode_enabled(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Validate file path for security
     *
     * @param string $path File path to validate
     * @return bool
     */
    public function validate_file_path(string $path): bool
    {
        if (strpos($path, '..') !== false) {
            return false;
        }

        $wp_content_dir = WP_CONTENT_DIR;
        $wp_upload_dir = wp_upload_dir()['basedir'];

        if (strpos($path, $wp_content_dir) === 0 ||
            strpos($path, $wp_upload_dir) === 0 ||
            strpos($path, ABSPATH) === 0) {
            return true;
        }

        $dangerous_paths = [
            '/etc/',
            '/proc/',
            '/sys/',
            '/dev/',
            '/var/log/',
            '/tmp/',
            '/root/',
            '/usr/local/etc/',
            '/boot/',
            '/home/*/.ssh/',
            '/home/*/.config/',
            '/home/*/.cache/',
            '/home/*/.local/',
            '/home/*/mail/',
            '/home/*/.bash_history',
            '/home/*/.mysql_history',
            '/home/*/.wp-cli/'
        ];

        foreach ($dangerous_paths as $dangerous) {
            $pattern = '#^' . str_replace(['*', '/'], ['[^/]+', '\/'], $dangerous) . '#';
            if (preg_match($pattern, $path)) {
                return false;
            }
        }

        return true;
    }
}

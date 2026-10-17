<?php

namespace RunCache\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger handles structured logging for the plugin
 *
 * This class is responsible for:
 * - Writing log entries using the WordPress debug log
 * - Providing convenience helpers for common logging scenarios
 * - Controlling logging behaviour based on WordPress debug settings
 */
class Logger
{
    private string $log_file;
    private bool $enabled;

    /**
     * Initialize logger state
     */
    public function __construct()
    {
        $this->log_file = $this->resolve_log_file();
        $this->enabled = $this->is_logging_enabled();
    }

    /**
     * Resolve the log destination using WordPress's configured debug log path.
     *
     * Mirrors core wp_debug_mode() and resolves relative paths against the
     * WordPress root so the destination does not depend on the PHP process's
     * current working directory.
     *
     * @return string
     */
    private function resolve_log_file(): string
    {
        if (defined('WP_DEBUG_LOG')) {
            // Boolean true (or 'true'/'1') maps to the default debug.log.
            if (in_array(strtolower((string) WP_DEBUG_LOG), ['true', '1'], true)) {
                return WP_CONTENT_DIR . '/debug.log';
            }

            if (is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '') {
                $log_file = WP_DEBUG_LOG;

                // Resolve relative paths (e.g. 'wp-content/debug.log') against the WordPress root.
                if (!$this->is_absolute_path($log_file)) {
                    $log_file = ABSPATH . ltrim($log_file, '/\\');
                }

                return $log_file;
            }
        }

        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Check whether a path is absolute (Unix or Windows).
     *
     * @param string $path
     * @return bool
     */
    private function is_absolute_path(string $path): bool
    {
        // Unix absolute path.
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute path (drive letter or UNC).
        return (bool) preg_match('#^[a-zA-Z]:[\\\\/]#', $path)
            || str_starts_with($path, '\\\\');
    }

    /**
     * Determine whether logging should run
     *
     * @return bool Logging status
     */
    private function is_logging_enabled(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Log a message to the debug log
     *
     * @param string $message Message to log
     * @param string $level Log level name
     * @param array $context Additional context to json encode
     * @return bool Whether the write succeeded
     */
    public function log(string $message, string $level = 'INFO', array $context = []): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $timestamp = current_time('Y-m-d H:i:s', true);
        $level = strtoupper($level);

        // Add caller information automatically.
        $caller = $this->get_caller_info();
        if ($caller !== null) {
            $context = array_merge(['_caller' => $caller], $context);
        }

        $message = $this->redact_sensitive_text($message);
        $context = $this->redact_context($context);

        $context_str = '';
        if (!empty($context)) {
            $context_str = ' | Context: ' . json_encode($context);
        }

        $plugin_name = defined('RUNCACHE_PLUGIN_NAME') ? RUNCACHE_PLUGIN_NAME : 'RunCache';
        $log_entry = sprintf(
            '[%s] [%s] [%s] %s%s',
            $timestamp,
            $plugin_name,
            $level,
            $message,
            $context_str
        );

        // Debug logger: only writes when WP_DEBUG is enabled.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return error_log($log_entry . PHP_EOL, 3, $this->log_file);
        }

        return false;
    }

    /**
     * Redact sensitive values from structured log context.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redact_context(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $normalized_key = strtolower((string) $key);
            $is_sensitive = (bool) preg_match(
                '/(?:pass(?:word)?|secret|token|api[_-]?key|access[_-]?key|auth(?:orization)?|cookie|credential|private[_-]?key)/i',
                $normalized_key
            );

            if ($is_sensitive) {
                $redacted[$key] = '[REDACTED]';
            } elseif ((in_array($normalized_key, ['url', 'request_url', 'uri'], true) || str_ends_with($normalized_key, '_url')) && is_string($value)) {
                $redacted[$key] = $this->redact_url($value);
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact_context($value);
            } elseif (is_string($value)) {
                $redacted[$key] = $this->redact_sensitive_text($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Redact common credential formats from free-form log messages.
     *
     * @param string $text
     * @return string
     */
    private function redact_sensitive_text(string $text): string
    {
        $patterns = [
            '/((?:password|passwd|secret|token|api[_-]?key|access[_-]?key|authorization)\\s*[:=]\\s*)([^\\s,;]+)/i',
            '/(Bearer\\s+)[^\\s,;]+/i',
        ];

        return preg_replace($patterns, '$1[REDACTED]', $text) ?? '[REDACTED]';
    }

    /**
     * Remove query and fragment components from a URL before logging.
     *
     * @param string $url
     * @return string
     */
    private function redact_url(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '[INVALID_URL]';
        }

        unset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass']);

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        return $scheme . $host . $port . $path;
    }

    /**
     * Convenience wrapper for info logs
     *
     * @param string $message Message to log
     * @param array $context Additional context to json encode
     * @return bool Whether the write succeeded
     */
    public function info(string $message, array $context = []): bool
    {
        return $this->log($message, 'INFO', $context);
    }

    /**
     * Convenience wrapper for warning logs
     *
     * @param string $message Message to log
     * @param array $context Additional context to json encode
     * @return bool Whether the write succeeded
     */
    public function warning(string $message, array $context = []): bool
    {
        return $this->log($message, 'WARNING', $context);
    }

    /**
     * Convenience wrapper for error logs
     *
     * @param string $message Message to log
     * @param array $context Additional context to json encode
     * @return bool Whether the write succeeded
     */
    public function error(string $message, array $context = []): bool
    {
        return $this->log($message, 'ERROR', $context);
    }

    /**
     * Convenience wrapper for debug logs
     *
     * @param string $message Message to log
     * @param array $context Additional context to json encode
     * @return bool Whether the write succeeded
     */
    public function debug(string $message, array $context = []): bool
    {
        return $this->log($message, 'DEBUG', $context);
    }

    /**
     * Log cache related operation with context
     *
     * @param string $operation Cache operation identifier
     * @param string|null $key Cache key if available
     * @param mixed $result Operation result
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_cache_operation(string $operation, ?string $key = null, mixed $result = null, array $context = []): bool
    {
        $context['operation'] = $operation;
        if ($key) {
            $context['cache_key'] = $key;
        }
        if ($result !== null) {
            $context['result'] = $result;
        }

        return $this->info("Cache operation: {$operation}", $context);
    }

    /**
     * Log a setting lifecycle event
     *
     * @param string $operation Operation identifier
     * @param string|null $key Setting key involved
     * @param mixed $value Setting value snapshot
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_setting_operation(string $operation, ?string $key = null, mixed $value = null, array $context = []): bool
    {
        $context['operation'] = $operation;
        if ($key) {
            $context['setting_key'] = $key;
        }
        if ($value !== null) {
            $context['value'] = $value;
        }

        return $this->info("Setting operation: {$operation}", $context);
    }

    /**
     * Log a cache purge event
     *
     * @param string $operation Purge operation identifier
     * @param mixed $result Operation result snapshot
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_purge_operation(string $operation, mixed $result = null, array $context = []): bool
    {
        $context['operation'] = $operation;
        if ($result !== null) {
            $context['result'] = $result;
        }

        return $this->info("Purge operation: {$operation}", $context);
    }

    /**
     * Log an AJAX request life cycle
     *
     * @param string $action AJAX action name
     * @param array $data Request data payload
     * @param mixed $result Response payload
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_ajax_request(string $action, array $data = [], mixed $result = null, array $context = []): bool
    {
        $context['ajax_action'] = $action;
        if (!empty($data)) {
            $context['request_data'] = $data;
        }
        if ($result !== null) {
            $context['response'] = $result;
        }

        return $this->info("AJAX request: {$action}", $context);
    }

    /**
     * Log time based performance metrics
     *
     * @param string $operation Operation identifier
     * @param float $start_time Operation start time in microseconds
     * @param float|null $end_time Operation end time override
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_performance(string $operation, float $start_time, ?float $end_time = null, array $context = []): bool
    {
        if ($end_time === null) {
            $end_time = microtime(true);
        }

        $duration = round(($end_time - $start_time) * 1000, 2);

        $context['duration_ms'] = $duration;
        $context['start_time'] = $start_time;
        $context['end_time'] = $end_time;

        return $this->debug("Performance: {$operation} took {$duration}ms", $context);
    }

    /**
     * Log a file system related operation
     *
     * @param string $operation Operation identifier
     * @param string $file_path File system path
     * @param mixed $result Operation result snapshot
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_file_operation(string $operation, string $file_path, mixed $result = null, array $context = []): bool
    {
        $context['operation'] = $operation;
        $context['file_path'] = $file_path;
        if ($result !== null) {
            $context['result'] = $result;
        }

        return $this->debug("File operation: {$operation}", $context);
    }

    /**
     * Log a security related event
     *
     * @param string $event Security event identifier
     * @param array $details Supporting details for the event
     * @param array $context Additional context to merge
     * @return bool Whether the write succeeded
     */
    public function log_security_event(string $event, array $details = [], array $context = []): bool
    {
        $context['security_event'] = $event;
        if (!empty($details)) {
            $context['details'] = $details;
        }

        return $this->warning("Security event: {$event}", $context);
    }

    /**
     * Get log file path
     *
     * @return string Log file path
     */
    public function get_log_file(): string
    {
        return $this->log_file;
    }

    /**
     * Check if logging is enabled
     *
     * @return bool Whether logging is enabled
     */
    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the class/method that called the logger
     *
     * @return string|null Caller info in Class::method format
     */
    private function get_caller_info(): ?string
    {
        // Caller context is only used by the WP_DEBUG-gated logger.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        } else {
            return null;
        }
        
        // Skip backtrace indices to find the actual caller:
        // 0 = get_caller_info()
        // 1 = log()
        // 2 = debug/info/error/warning (wrapper method)
        // 3 = actual caller (could be 2 if calling log() directly)
        
        $caller_index = 2;
        
        // Check if the direct caller is one of our wrapper methods
        if (isset($backtrace[$caller_index]['function']) && 
            in_array($backtrace[$caller_index]['function'], ['debug', 'info', 'warning', 'error', 'log'])) {
            $caller_index++;
        }
        
        // If we have a valid caller, build the caller string
        if (isset($backtrace[$caller_index])) {
            $caller = $backtrace[$caller_index];
            
            if (isset($caller['class'])) {
                return $caller['class'] . '::' . ($caller['function'] ?? 'unknown');
            } else if (isset($caller['function'])) {
                return $caller['function'];
            } else if (isset($caller['file'])) {
                return basename($caller['file']) . ':' . ($caller['line'] ?? '?');
            }
        }
        
        return null;
    }

    /**
     * Clear log file
     *
     * @return bool Whether the clear succeeded
     */
    public function clear_log(): bool
    {
        if (file_exists($this->log_file)) {
            return file_put_contents($this->log_file, '') !== false;
        }
        return true;
    }

    /**
     * Get log file size
     *
     * @return int Log file size in bytes
     */
    public function get_log_size(): int
    {
        if (file_exists($this->log_file)) {
            return filesize($this->log_file);
        }
        return 0;
    }
}

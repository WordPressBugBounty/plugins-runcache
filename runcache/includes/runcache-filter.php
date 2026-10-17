<?php

/**
 * RunCache WordPress plugin
 *
 * Decides if a request can be cached. Runs before WordPress loads.
 * Standalone runs load the config file first.
 *
 * @package RunCache
 * @since 0.0.8
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions -- Drop-in runs before WordPress loads, wp_ functions not available
// phpcs:ignoreFile WordPress.Security.NonceVerification -- The advanced-cache drop-in runs before WordPress loads. It decides cache eligibility from $_GET and $_COOKIE. Query data passes through runcache_sanitize_query_params(). A nonce check cannot apply here.

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/* [RUNCACHE_DROP_WHEN_INLINED: runcache-config-require] */
// Dropin.php drops this block during generation. The generated file has
// the config section inlined already.
require_once __DIR__ . '/runcache-config.php';
/* [/RUNCACHE_DROP_WHEN_INLINED: runcache-config-require] */

if (!function_exists('runcache_should_cache')) {
    /**
     * Checks if the current request can be cached.
     *
     * @return bool
     */
    function runcache_should_cache(): bool
    {
        global $runcache_config, $runcache_hardcoded_exclusions;

        // Sanitize the request query once.
        // Only key names affect eligibility. Data never reaches output.
        $get_params = (!empty($_GET) && is_array($_GET))
            ? runcache_sanitize_query_params($_GET)
            : [];

        // Never cache the internal loopback request from Server::get_headers().
        if (runcache_get_server_var('HTTP_X_RUNCACHE_INTERNAL')) {
            return false;
        }

        // Only cache GET requests.
        if (runcache_get_server_var('REQUEST_METHOD') !== 'GET') {
            return false;
        }

        // Bypass requests that carry a hardcoded safety query key.
        $hardcoded_query_keys = is_array($runcache_hardcoded_exclusions ?? null)
            ? ($runcache_hardcoded_exclusions['exclude_query'] ?? [])
            : [];
        if ($get_params !== []) {
            foreach ($hardcoded_query_keys as $query_key) {
                if (array_key_exists((string) $query_key, $get_params)) {
                    return false;
                }
            }
        }

        if (empty($runcache_config) || !is_array($runcache_config)) {
            return false;
        }

        if (array_key_exists('cache_enabled', $runcache_config) && !$runcache_config['cache_enabled']) {
            return false;
        }

        // The exclude_auto_onn setting controls the DONOTCACHEPAGE bypass.
        // The bypass is on by default.
        if (!empty($runcache_config['exclude_auto_onn'])
            && defined('DONOTCACHEPAGE')
            && DONOTCACHEPAGE
        ) {
            return false;
        }

        $hardcoded_urls = is_array($runcache_hardcoded_exclusions ?? null)
            ? ($runcache_hardcoded_exclusions['exclude_url'] ?? [])
            : [];
        $hardcoded_cookies = is_array($runcache_hardcoded_exclusions ?? null)
            ? ($runcache_hardcoded_exclusions['exclude_cookie'] ?? [])
            : [];

        // Match a subject against a list of patterns.
        $matches_any = static function (string $subject, array $patterns): bool {
            $subject_lower = strtolower($subject);

            foreach ($patterns as $pattern) {
                $pattern = strtolower((string)$pattern);
                // Treat % as a wildcard. fnmatch needs *.
                $normalized = str_replace('%', '*', $pattern);

                // Try a case-insensitive wildcard match.
                $fnmatch_result = fnmatch($normalized, $subject, FNM_CASEFOLD);

                if ($fnmatch_result) {
                    return true;
                }

                // No wildcard: fall back to a substring match.
                if (!str_contains($normalized, '*') && str_contains($subject_lower, $pattern)) {
                    return true;
                }
            }
            return false;
        };

        // Mandatory paths cannot be disabled by dashboard settings.
        $request_path = (string) parse_url(runcache_get_server_var('REQUEST_URI'), PHP_URL_PATH);
        if ($matches_any($request_path, $hardcoded_urls)) {
            return false;
        }

        // Check the URI, browser, and visitor IP against the configured rules.
        $checks = [
            'url'       => ['val' => runcache_get_server_var('REQUEST_URI'), 'key' => 'exclude_url'],
            'browser'   => ['val' => runcache_get_server_var('HTTP_USER_AGENT'), 'key' => 'exclude_browser'],
            'visitorip' => ['val' => runcache_get_server_var('REMOTE_ADDR'), 'key' => 'exclude_visitorip'],
        ];

        foreach ($checks as $check) {
            $onn = $runcache_config["{$check['key']}_onn"] ?? false;
            $mch = $runcache_config["{$check['key']}_mch"] ?? [];

            // Split string lists on newlines.
            if (is_string($mch)) {
                $mch = array_filter(array_map('trim', preg_split('/\r?\n/', $mch) ?: []));
            }

            if ($onn && is_array($mch) && $matches_any($check['val'], $mch)) {
                return false;
            }
        }

        // Auth and session cookies are always excluded.
        foreach ($_COOKIE as $name => $_) {
            if ($matches_any((string) $name, $hardcoded_cookies)) {
                return false;
            }
        }

        // Check every cookie name against the configured patterns.
        if (($runcache_config['exclude_cookie_onn'] ?? false)) {
            $patterns = $runcache_config['exclude_cookie_mch'] ?? [];
            if (is_string($patterns)) {
                $patterns = array_filter(array_map('trim', preg_split('/\r?\n/', $patterns) ?: []));
            }

            if (is_array($patterns)) {
                foreach ($_COOKIE as $name => $_) {
                    if ($matches_any((string) $name, $patterns)) {
                        return false;
                    }
                }
            }
        }

        // Check query parameters after the ignore list removes its keys.
        if ($get_params !== []) {
            $query_params = $get_params;

            if (!empty($runcache_config['exclude_query_onn'])) {
                $excluded = runcache_normalize_list($runcache_config['exclude_query_mch'] ?? []);
                if ($excluded !== [] && array_intersect(array_keys($query_params), $excluded) !== []) {
                    return false;
                }
            }

            if (!empty($runcache_config['ignore_query_onn'])) {
                $ignored = runcache_normalize_list($runcache_config['ignore_query_mch'] ?? []);
                if ($ignored !== []) {
                    $query_params = array_diff_key($query_params, array_flip($ignored));
                }
            }

            if ($query_params !== []) {
                if (empty($runcache_config['allow_query_onn'])) {
                    // Query strings are not cacheable by default.
                    return false;
                }

                $allowed = runcache_normalize_list($runcache_config['allow_query_mch'] ?? []);
                if (array_diff(array_keys($query_params), $allowed) !== []) {
                    return false;
                }
            }
        }

        return true;
    }
}

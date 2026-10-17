<?php

/**
 * RunCache WordPress plugin
 *
 * Loads the generated cache config (wp-content/runcache-config.php) for the
 * current site. One 'sites' map holds the keys for all single-site and
 * multisite installs.
 *
 * @package RunCache
 * @since 0.0.8
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions -- Drop-in runs before WordPress loads, wp_ functions not available

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RUNCACHE_CACHE_DIRECTORY')) {
    define('RUNCACHE_CACHE_DIRECTORY', WP_CONTENT_DIR . '/cache/runcache/');
}

if (!defined('RUNCACHE_CONFIG_FILE')) {
    define('RUNCACHE_CONFIG_FILE', WP_CONTENT_DIR . '/runcache-config.php');
}

/* [RUNCACHE_INLINE_DATA: runcache-rule-defaults] */
// Dropin.php replaces this block with the rule defaults as a literal array.
// Standalone runs load the defaults by require.
$runcache_defaults_file = __DIR__ . '/runcache-rule-defaults.php';
$runcache_fallback_rules = is_readable($runcache_defaults_file)
    ? require $runcache_defaults_file
    : [];
if (!is_array($runcache_fallback_rules)) {
    $runcache_fallback_rules = [];
}
/* [/RUNCACHE_INLINE_DATA: runcache-rule-defaults] */

// Safety rules that the dashboard cannot disable. The filter checks them.
$GLOBALS['runcache_hardcoded_exclusions'] = is_array($runcache_fallback_rules['hardcoded'] ?? null)
    ? $runcache_fallback_rules['hardcoded']
    : [];

// The fallback below needs only the configurable rules.
$runcache_fallback_rules = is_array($runcache_fallback_rules['rules'] ?? null)
    ? $runcache_fallback_rules['rules']
    : [];

// ===== HELPER FUNCTIONS =====

if (!function_exists('runcache_get_server_var')) {
    /**
     * Reads a server variable and returns a trimmed string.
     * The drop-in runs before WordPress loads, so WordPress helpers are not available.
     *
     * @param string $key Server variable key
     * @return string Trimmed value
     */
    function runcache_get_server_var(string $key): string
    {
        $value = $_SERVER[$key] ?? '';

        // Strip slashes (legacy PHP support).
        if (is_string($value)) {
            $value = stripslashes($value);
        }

        // Trim whitespace
        return trim($value);
    }
}

if (!function_exists('runcache_normalize_list')) {
    /**
     * Normalizes a list value to an array of trimmed strings.
     * List values can be arrays or newline-separated strings.
     *
     * @param mixed $value String or array value from config
     * @return array<int, string>
     */
    function runcache_normalize_list(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value)) {
            $parts = preg_split('/\r?\n/', $value);
        } else {
            return [];
        }

        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $parts), static function ($item) {
            return $item !== '';
        }));
    }
}

if (!function_exists('runcache_sanitize_query_params')) {
    /**
     * Sanitizes raw query parameters before the cache key uses them.
     * Plain PHP only. The drop-in runs before WordPress loads.
     *
     * @param array<int|string, mixed> $params Raw query parameters, typically $_GET
     * @return array<int|string, mixed> Sanitized parameters
     */
    function runcache_sanitize_query_params(array $params): array
    {
        // Caps stop nested-array floods.
        $max_nodes = 100;
        $max_depth = 3;
        $max_key_length = 100;
        $remaining = $max_nodes;

        // Keys keep only URL-safe name characters. A length cap applies.
        $sanitize_key = static function (mixed $key) use ($max_key_length): string {
            $name = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
            return substr($name, 0, $max_key_length);
        };

        // Values drop control characters. No truncation keeps md5 keys distinct.
        $sanitize_value = static function (mixed $value): string {
            if (!is_scalar($value)) {
                return '';
            }
            return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value);
        };

        $walk = null;
        $walk = static function (mixed $value, int $depth) use (&$walk, &$remaining, $sanitize_key, $sanitize_value, $max_depth): mixed {
            if ($remaining <= 0) {
                return null;
            }
            $remaining--;

            if (is_array($value)) {
                if ($depth >= $max_depth) {
                    return null;
                }
                $clean = [];
                foreach ($value as $key => $item) {
                    $key_name = $sanitize_key($key);
                    if ($key_name === '' || array_key_exists($key_name, $clean)) {
                        continue;
                    }
                    $clean_item = $walk($item, $depth + 1);
                    if ($clean_item === null || (is_array($clean_item) && $clean_item === [])) {
                        continue;
                    }
                    $clean[$key_name] = $clean_item;
                }
                return $clean;
            }

            $text = $sanitize_value($value);
            return $text === '' ? null : $text;
        };

        $sanitized = $walk($params, 0);
        return is_array($sanitized) ? $sanitized : [];
    }
}

if (!function_exists('runcache_filter_query_params')) {
    /**
     * Filters query parameters for the cache key.
     * runcache_should_cache() rejects excluded keys before this runs.
     *
     * @param array<int|string, mixed> $params Raw query parameters
     * @param array<string, mixed>     $config RunCache config
     * @return array<int|string, mixed> Filtered query parameters
     */
    function runcache_filter_query_params(array $params, array $config): array
    {
        $ignored = !empty($config['ignore_query_onn'])
            ? runcache_normalize_list($config['ignore_query_mch'] ?? [])
            : [];
        $allowed = !empty($config['allow_query_onn'])
            ? runcache_normalize_list($config['allow_query_mch'] ?? [])
            : [];

        if ($ignored !== []) {
            $params = array_diff_key($params, array_flip($ignored));
        }

        // With no allow list, drop all remaining params. Random query strings
        // then cannot flood the cache with variants.
        if (empty($config['allow_query_onn'])) {
            return [];
        }

        if ($allowed !== []) {
            $params = array_intersect_key($params, array_flip($allowed));
        }

        return $params;
    }
}

if (!function_exists('runcache_normalize_config_component')) {
    /**
     * Normalizes one config key component (host or path segment).
     *
     * Must stay in sync with Multisite::normalize_config_key_component() in
     * src/Integrations/Multisite.php.
     *
     * @param string $value Raw component value.
     * @return string Normalized component, possibly empty.
     */
    function runcache_normalize_config_component(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}

if (!function_exists('runcache_normalize_config_host')) {
    /**
     * Normalizes a host for the config key: port and trailing dots removed.
     * Some clients send FQDN form ('example.com.') or a port (':8080').
     *
     * Must stay in sync with Multisite::normalize_config_key_host() in
     * src/Integrations/Multisite.php.
     *
     * @param string $host Raw host value.
     * @return string Normalized host, possibly empty.
     */
    function runcache_normalize_config_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = rtrim($host, '.');

        return runcache_normalize_config_component($host);
    }
}

if (!function_exists('runcache_get_config_key')) {
    /**
     * Returns the config map key for the current site.
     *
     * Must stay in sync with Multisite::get_config_key() in
     * src/Integrations/Multisite.php. Both sides must produce identical keys.
     *
     * An empty or fully sanitized result (e.g. empty HTTP_HOST under CLI)
     * misses the lookup. The per-site fallback then applies.
     *
     * @return string Config key, e.g. 'example.com' or 'example.com/shop'.
     */
    function runcache_get_config_key(): string
    {
        $host = runcache_normalize_config_host(runcache_get_server_var('HTTP_HOST'));

        // Subdirectory multisite: the first URI path segment is the site.
        $segment = '';
        if (defined('MULTISITE') && MULTISITE && (!defined('SUBDOMAIN_INSTALL') || !SUBDOMAIN_INSTALL)) {
            $uri = runcache_get_server_var('REQUEST_URI');
            $path = parse_url($uri, PHP_URL_PATH);
            $path = trim(is_string($path) ? $path : '', '/');

            if ($path !== '') {
                $parts = explode('/', $path);
                $segment = runcache_normalize_config_component((string) array_shift($parts));
            }
        }

        if ($segment === '') {
            return $host;
        }

        return $host . '/' . $segment;
    }
}

// ===== LOAD CONFIGURATION =====

$runcache_config_key = runcache_get_config_key();
$runcache_loaded = null;

if (is_readable(RUNCACHE_CONFIG_FILE)) {
    $runcache_ob_level = ob_get_level();
    ob_start();
    try {
        // The config file must contain exactly one `return array(...)`.
        // A broken file must not abort the request.
        $runcache_loaded = @include RUNCACHE_CONFIG_FILE;
    } catch (\Throwable $runcache_exception) {
        $runcache_loaded = null; // ParseError is catchable (PHP 7+).
    } finally {
        // Discard stray output a corrupted file may emit on include.
        // Close only our own buffer, never an outer one.
        if (ob_get_level() > $runcache_ob_level) {
            ob_end_clean();
        }
    }

    if (!is_array($runcache_loaded)) {
        $runcache_loaded = null;
    }
}

$runcache_site_config = is_array($runcache_loaded)
    ? ($runcache_loaded['sites'][$runcache_config_key] ?? null)
    : null;

if (is_array($runcache_site_config)) {
    // Valid file, this site's key present.
    $GLOBALS['runcache_config'] = $runcache_site_config;
} elseif (is_array($runcache_loaded)) {
    // Valid file, key absent (fresh child site): fail-open defaults,
    // same semantics as a missing per-site config file.
    $GLOBALS['runcache_config'] = array_merge(
        ['cache_enabled' => true, 'page_cache_type' => 'php'],
        $runcache_fallback_rules
    );
} elseif (is_readable(RUNCACHE_CONFIG_FILE)) {
    // File exists but is broken (parse error, wrong type): FAIL CLOSED.
    // A broken config must not cache anything with guessed rules.
    $GLOBALS['runcache_config'] = array_merge(
        ['cache_enabled' => false, 'page_cache_type' => 'php'],
        $runcache_fallback_rules
    );
} else {
    // File missing: fail-open defaults (first request before first save).
    $GLOBALS['runcache_config'] = array_merge(
        ['cache_enabled' => true, 'page_cache_type' => 'php'],
        $runcache_fallback_rules
    );
}

// Set HTTP_X_DEVICE from the User-Agent when the cache key uses it
// and no proxy set it.
$extra_var = $GLOBALS['runcache_config']['cache_key_extra_var'] ?? '';
if (!empty($extra_var)
    && str_contains($extra_var, 'HTTP_X_DEVICE')
    && !isset($_SERVER['HTTP_X_DEVICE'])
) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SERVER['HTTP_X_DEVICE'] = preg_match(
        '/Mobile|Android|iPhone|iPod|BlackBerry|Opera Mini|Windows Phone/i',
        $ua
    ) ? 'mobile' : 'desktop';
}

<?php

/**
 * RunCache WordPress plugin
 *
 * Redis page cache handler. Serves cached pages.
 * Stores new pages through output buffering.
 *
 * @package RunCache
 * @since 0.0.8
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions -- Drop-in runs before WordPress loads, wp_ functions not available
// phpcs:ignoreFile WordPress.Security.NonceVerification -- The advanced-cache drop-in runs before WordPress loads. It builds a cache key from $_GET through runcache_sanitize_query_params(). It writes no data. A nonce check cannot apply here.

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/* [RUNCACHE_DROP_WHEN_INLINED: handler-filter-guard] */
// Standalone runs need runcache_should_cache(). The generator drops this
// guard because the drop-in defines the filter in an earlier section.
if (!function_exists('runcache_should_cache')) {
    return;
}
/* [/RUNCACHE_DROP_WHEN_INLINED: handler-filter-guard] */

if (!runcache_should_cache()) {
    // Do not cache this request.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-RunCache-Status: BYPASS');
    header('X-RunCache-Type: Redis');
    return;
}

// Read the config global from the config section.
$runcache_config = $GLOBALS['runcache_config'] ?? [];

header('X-RunCache-Type: Redis');

// ===== REDIS CONNECTION =====

$runcache_redis = null;
$runcache_key = null;

// Use the same defaults as the object-cache drop-in when the
// RUNCACHE_REDIS_* constants are missing from wp-config.php.
$runcache_redis_host = defined('RUNCACHE_REDIS_HOST') && RUNCACHE_REDIS_HOST !== ''
    ? (string) RUNCACHE_REDIS_HOST
    : '127.0.0.1';
$runcache_redis_port = defined('RUNCACHE_REDIS_PORT') ? (int) RUNCACHE_REDIS_PORT : 6379;

if (class_exists('\Redis')) {
    try {
        $runcache_redis = new \Redis();
        if ($runcache_redis->connect($runcache_redis_host, $runcache_redis_port, 1.0)) {
            $password = defined('RUNCACHE_REDIS_PASSWORD') ? (string) RUNCACHE_REDIS_PASSWORD : '';
            $username = defined('RUNCACHE_REDIS_USERNAME') ? (string) RUNCACHE_REDIS_USERNAME : '';

            if ($password !== '' || $username !== '') {
                $auth = $username !== '' ? [$username, $password] : [$password];
                $runcache_redis->auth($auth);
            }

            if (defined('RUNCACHE_REDIS_DATABASE')) {
                $runcache_redis->select((int) RUNCACHE_REDIS_DATABASE);
            }

            $runcache_redis->ping();
        } else {
            $runcache_redis = null;
        }
    } catch (Throwable $e) {
        $runcache_redis = null;
    }
}

// ===== RESOLVE CACHE KEY =====

// Build the key only when Redis is connected.
if ($runcache_redis !== null) {
    // Sanitize the request data before the code builds the key.
    $host = (string) (runcache_get_server_var('HTTP_HOST') ?: 'localhost');
    $host = preg_replace('/[^a-zA-Z0-9.-]/', '-', $host) ?: 'localhost';
    $host = trim($host, '.-');
    $host = $host !== '' ? $host : 'localhost';

    $uri = (string) (runcache_get_server_var('REQUEST_URI') ?: '/');
    $parsed_path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($parsed_path) ? str_replace('\\', '/', trim($parsed_path, '/')) : '';

    $safe_segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }

        $segment = preg_replace('/[^a-zA-Z0-9_-]/', '-', $segment);
        if ($segment !== '') {
            $safe_segments[] = $segment;
        }
    }

    $parts = [$host];
    if ($safe_segments === []) {
        $parts[] = 'index';
    } else {
        $parts = array_merge($parts, $safe_segments);
        if (str_ends_with($uri, '/')) {
            $parts[] = 'index';
        }
    }

    // Sanitize the raw data first, then apply the query filter.
    if (!empty($_GET) && is_array($_GET) && function_exists('runcache_filter_query_params')) {
        $params = runcache_filter_query_params(runcache_sanitize_query_params($_GET), $runcache_config);

        if ($params !== []) {
            ksort($params);
            $parts[] = 'q_' . substr(md5(http_build_query($params)), 0, 8);
        }
    }

    // Handle the extra cache key variables.
    if (!empty($runcache_config['cache_key_extra_var'])) {
        $extras = [];
        foreach (array_map('trim', explode(',', $runcache_config['cache_key_extra_var'])) as $var) {
            if ($var !== '' && isset($_SERVER[$var])) {
                $extras[$var] = runcache_get_server_var($var);
            }
        }
        if ($extras !== []) {
            ksort($extras);
            $parts[] = 'e_' . substr(md5(json_encode($extras)), 0, 8);
        }
    }

    // Build the Redis key.
    $prefix = defined('RUNCACHE_REDIS_KEY') ? rtrim((string) RUNCACHE_REDIS_KEY, ':') : 'runcache';
    $site = strtolower(preg_replace('/:\d+$/', '', $host));
    $site = preg_replace('/[^a-z0-9]/', '-', $site);
    $runcache_key = $prefix . ':' . $site . ':' . implode('/', $parts);
}

// ===== SERVE CACHE (HIT) =====

if ($runcache_redis !== null && $runcache_key !== null) {
    try {
        $raw = $runcache_redis->get($runcache_key);
        if ($raw !== false && $raw !== null) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && isset($cached['expires'], $cached['content'], $cached['headers']) && time() <= (int) $cached['expires']) {
                // Restore headers.
                foreach ($cached['headers'] as $name => $value) {
                    $n = (string) $name;
                    if (stripos($n, 'X-RunCache-') !== 0) {
                        header("$n: " . (string) $value);
                    }
                }
                header('X-RunCache-Status: HIT');
                echo $cached['content'];
                exit;
            }
            // Expired entry: delete it.
            $runcache_redis->del($runcache_key);
        }
    } catch (Throwable $e) {
        // Redis error: continue without caching.
    }
}

// ===== CACHE MISS: BUFFER AND STORE =====

$runcache_expiry = (int) ($runcache_config['cache_expiry'] ?? 28800);

header('X-RunCache-Status: MISS');

// Record the buffer level before our buffer opens. The shutdown
// function closes only the buffers this handler owns.
$runcache_ob_level = ob_get_level();

ob_start(static function (string $content) use ($runcache_redis, $runcache_key, $runcache_expiry): string {
    if ($content === '' || http_response_code() !== 200) {
        return $content;
    }
    // Capture the response headers before the cache decision.
    $headers = [];
    foreach (headers_list() as $header) {
        if (str_contains($header, ':')) {
            [$h, $v] = explode(':', $header, 2);
            $h = trim($h);
            if (stripos($h, 'X-RunCache-') !== 0) {
                $headers[$h] = trim($v);
            }
        }
    }

    $cache_control = '';
    foreach ($headers as $header_name => $header_value) {
        if (strcasecmp((string) $header_name, 'Cache-Control') === 0) {
            $cache_control = strtolower((string) $header_value);
            break;
        }
    }

    if (preg_match('/(?:^|,)\s*(?:no-store|no-cache|private)(?:\s*=|\s*(?:,|$))/i', $cache_control)) {
        return $content;
    }

    $headers['Cache-Control'] = 's-maxage=' . $runcache_expiry . ', max-age=60';

    // Send STORE only when Redis accepts the entry.
    $stored = false;
    if ($runcache_redis !== null && $runcache_key !== null) {
        try {
            $stored = (bool) $runcache_redis->setex($runcache_key, $runcache_expiry, json_encode([
                'time' => time(),
                'expires' => time() + $runcache_expiry,
                'headers' => $headers,
                'content' => $content,
            ]));
        } catch (Throwable $e) {
            // Store failed: continue.
        }
    }

    if ($stored && !headers_sent()) {
        header('X-RunCache-Status: STORE');
    }

    return $content;
});

// Close the buffers this handler opened. The shutdown function runs
// before PHP flushes. The cache callback always completes.
register_shutdown_function(static function () use ($runcache_ob_level) {
    while (ob_get_level() > $runcache_ob_level) {
        if (!@ob_end_flush()) {
            break;
        }
    }
});

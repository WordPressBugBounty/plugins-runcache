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

/* [RUNCACHE_DROP_WHEN_INLINED: runcache-redis-keys-require] */
// Standalone runs load the shared key mapping from the plugin. The
// generator inlines the file body instead, so this require disappears.
if (is_readable(__DIR__ . '/runcache-redis-keys.php')) {
    require_once __DIR__ . '/runcache-redis-keys.php';
}
if (!function_exists('runcache_redis_page_key_head')) {
    return;
}
/* [/RUNCACHE_DROP_WHEN_INLINED: runcache-redis-keys-require] */

if (!runcache_should_cache()) {
    // Do not cache this request.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-RunCache-Status: BYPASS');
    header('X-RunCache-Type: Redis');
    return;
}

// Read the config global from the config section.
$runcache_config = $GLOBALS['runcache_config'] ?? [];

// ===== REDIS CONNECTION =====

$runcache_redis = null;
$runcache_key = null;

// Hand-written constants win, then the generated config channel, then
// the same defaults as the object-cache drop-in.
$runcache_redis_cfg = is_array($runcache_config['redis'] ?? null) ? $runcache_config['redis'] : [];
$runcache_redis_host = defined('RUNCACHE_REDIS_HOST') && '' !== RUNCACHE_REDIS_HOST
    ? (string) RUNCACHE_REDIS_HOST
    : (string) ($runcache_redis_cfg['host'] ?? '127.0.0.1');
$runcache_redis_port = defined('RUNCACHE_REDIS_PORT') ? (int) RUNCACHE_REDIS_PORT : (int) ($runcache_redis_cfg['port'] ?? 6379);

if (class_exists('\Redis')) {
    try {
        $runcache_redis = new \Redis();
        if ($runcache_redis->connect($runcache_redis_host, $runcache_redis_port, 1.0)) {
            $password = defined('RUNCACHE_REDIS_PASSWORD')
                ? (string) RUNCACHE_REDIS_PASSWORD
                : (string) ($runcache_redis_cfg['password'] ?? '');
            $username = defined('RUNCACHE_REDIS_USERNAME')
                ? (string) RUNCACHE_REDIS_USERNAME
                : (string) ($runcache_redis_cfg['username'] ?? '');

            if ($password !== '' || $username !== '') {
                $auth = $username !== '' ? [$username, $password] : [$password];
                $runcache_redis->auth($auth);
            }

            if (defined('RUNCACHE_REDIS_DATABASE')) {
                $runcache_redis->select((int) RUNCACHE_REDIS_DATABASE);
            } elseif (isset($runcache_redis_cfg['database'])) {
                $runcache_redis->select((int) $runcache_redis_cfg['database']);
            }

            $runcache_redis->ping();
        } else {
            $runcache_redis = null;
            error_log('[RunCache] Redis page cache connect failed: ' . $runcache_redis_host . ':' . $runcache_redis_port);
        }
    } catch (Throwable $e) {
        $runcache_redis = null;
        error_log('[RunCache] Redis page cache connection error: ' . $e->getMessage());
    }
}

// Advertise Redis only after Redis answered a command. A failed
// connection must not look like a healthy cacheable miss.
if (!$runcache_redis instanceof \Redis) {
    header('X-RunCache-Status: BYPASS');
    return;
}

header('X-RunCache-Type: Redis');

// ===== RESOLVE CACHE KEY =====

// Build the key only when Redis is connected.
if ($runcache_redis !== null) {
    // Sanitize the request data before the code builds the key.
    // The shared mapping owns the host and segment normalization.
    $host = runcache_redis_page_sanitize_host((string) (runcache_get_server_var('HTTP_HOST') ?: 'localhost'));

    $uri = (string) (runcache_get_server_var('REQUEST_URI') ?: '/');
    $parsed_path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($parsed_path) ? $parsed_path : '';

    $safe_segments = runcache_redis_page_sanitize_segments($path);

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

    // Build the Redis key. The key head comes from the shared mapping,
    // so the drop-in and the admin-side purger share one key shape.
    $prefix = defined('RUNCACHE_REDIS_KEY')
        ? rtrim((string) RUNCACHE_REDIS_KEY, ':')
        : rtrim((string) ($runcache_redis_cfg['key'] ?? 'runcache'), ':');
    $runcache_key = runcache_redis_page_key_head($prefix, $host) . ':' . implode('/', $parts);
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

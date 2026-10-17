<?php

/**
 * RunCache WordPress plugin
 *
 * PHP file cache handler. Serves cached pages.
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
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('X-RunCache-Status: BYPASS');
        header('X-RunCache-Type: PHP');
    }
    return;
}

/* [RUNCACHE_DROP_WHEN_INLINED: handler-cache-dir-define] */
// Standalone runs get the default cache directory. The generator drops this
// define because the config section defines the constant first.
if (!defined('RUNCACHE_CACHE_DIRECTORY')) {
    define('RUNCACHE_CACHE_DIRECTORY', WP_CONTENT_DIR . '/cache/runcache/');
}
/* [/RUNCACHE_DROP_WHEN_INLINED: handler-cache-dir-define] */

// Read the config global from the config section.
$runcache_config = $GLOBALS['runcache_config'] ?? [];

// Set the cache type header.
header('X-RunCache-Type: PHP');

// ===== RESOLVE CACHE PATH =====

$runcache_req_path = (function() use (&$runcache_config) {
    // Treat the request path as unsafe data. Sanitize each part.
    $host = (string) (runcache_get_server_var('HTTP_HOST') ?: 'localhost');
    $host = preg_replace('/[^a-zA-Z0-9.-]/', '-', $host) ?: 'localhost';
    $host = trim($host, '.-');
    $host = $host !== '' ? $host : 'localhost';

    $request_uri = (string) (runcache_get_server_var('REQUEST_URI') ?: '/');
    $parsed_path = parse_url($request_uri, PHP_URL_PATH);
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

    $pathParts = [$host];
    if ($safe_segments === []) {
        $pathParts[] = 'index';
    } else {
        $pathParts = array_merge($pathParts, $safe_segments);
        if (str_ends_with($request_uri, '/')) {
            $pathParts[] = 'index';
        }
    }

    // Sanitize the raw data first, then apply the query filter.
    if (!empty($_GET) && is_array($_GET) && function_exists('runcache_filter_query_params')) {
        $params = runcache_filter_query_params(runcache_sanitize_query_params($_GET), $runcache_config);

        if ($params !== []) {
            ksort($params);
            $pathParts[] = 'q_' . substr(md5(http_build_query($params)), 0, 8);
        }
    }

    // Handle the extra cache key variables.
    if (!empty($runcache_config['cache_key_extra_var'])) {
        $extras = [];
        $vars = array_map('trim', explode(',', $runcache_config['cache_key_extra_var']));
        foreach ($vars as $var) {
            if ($var !== '' && isset($_SERVER[$var])) {
                $extras[$var] = runcache_get_server_var($var);
            }
        }
        if (!empty($extras)) {
            ksort($extras);
            $pathParts[] = 'e_' . substr(md5(json_encode($extras)), 0, 8);
        }
    }

    return implode('/', $pathParts);
})();

$runcache_cache_root = realpath(RUNCACHE_CACHE_DIRECTORY);
if ($runcache_cache_root === false) {
    $runcache_cache_root = rtrim(RUNCACHE_CACHE_DIRECTORY, '/\\');
}

$runcache_file_html = $runcache_cache_root . '/' . $runcache_req_path . '.html';
$runcache_file_meta = $runcache_cache_root . '/' . $runcache_req_path . '.meta';

// Keep the cache path inside the cache root.
$runcache_parent = dirname($runcache_file_html);
if ($runcache_parent !== $runcache_cache_root && !str_starts_with($runcache_parent, $runcache_cache_root . DIRECTORY_SEPARATOR)) {
    return;
}

// ===== SERVE CACHE (HIT) =====

if (is_readable($runcache_file_meta) && is_readable($runcache_file_html)) {
    // Cleanup can delete a file between the check and the read. Every read
    // is checked. A failed read falls through to the MISS path.
    $runcache_meta_raw = @file_get_contents($runcache_file_meta);
    $runcache_meta = is_string($runcache_meta_raw) ? json_decode($runcache_meta_raw, true) : null;

    if (is_array($runcache_meta) && isset($runcache_meta['expires']) && time() <= $runcache_meta['expires']) {
        // Pick the body variant. Read it before the first header goes out.
        $runcache_body_gzip = !empty($runcache_config['gzip_enabled'])
            && str_contains(runcache_get_server_var('HTTP_ACCEPT_ENCODING'), 'gzip')
            && is_readable($runcache_file_html . '.gz');

        $runcache_body = $runcache_body_gzip
            ? @file_get_contents($runcache_file_html . '.gz')
            : @file_get_contents($runcache_file_html);

        if (is_string($runcache_body)) {
            // Restore headers.
            if (!empty($runcache_meta['headers']) && is_array($runcache_meta['headers'])) {
                foreach ($runcache_meta['headers'] as $runcache_name => $runcache_value) {
                    // Skip internal headers. Cast to string to prevent type errors.
                    $runcache_nameStr = (string)$runcache_name;
                    $runcache_valueStr = (string)$runcache_value;

                    if (stripos($runcache_nameStr, 'X-RunCache-') !== 0) {
                        header("$runcache_nameStr: $runcache_valueStr");
                    }
                }
            }

            header('X-RunCache-Status: HIT');

            if ($runcache_body_gzip) {
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding', false);
            }

            echo $runcache_body;
            exit;
        }
        // Read failure: fall through to the MISS path.
    }

    // The scheduled cleanup task removes expired entries.
}

// ===== CACHE MISS: BUFFER AND STORE =====

$runcache_expiry = $runcache_config['cache_expiry'] ?? 28800;
$runcache_gzip = $runcache_config['gzip_enabled'] ?? false;

header('X-RunCache-Status: MISS');

// Record the buffer level before our buffer opens. The shutdown
// function closes only the buffers this handler owns.
$runcache_ob_level = ob_get_level();

ob_start(static function (string $content) use ($runcache_config, $runcache_file_html, $runcache_file_meta, $runcache_expiry, $runcache_gzip): string {
    // Skip empty bodies and non-200 responses.
    if ($content === '' || http_response_code() !== 200) {
        return $content;
    }

    $cache_dir = dirname($runcache_file_html);
    if (!is_dir($cache_dir)) {
        // This drop-in runs before WP_Filesystem is available.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Early cache writes cannot initialize WP_Filesystem during request handling.
        if (!@mkdir($cache_dir, 0755, true)) {
            return $content;
        }
    }

    // Capture the response headers before the cache decision.
    $headers = [];
    foreach (headers_list() as $header) {
        if (str_contains($header, ':')) {
            $parts = explode(':', $header, 2);
            $key = trim($parts[0]);
            // Skip the X-RunCache headers to avoid duplicates.
            if (stripos($key, 'X-RunCache-') !== 0) {
                $headers[$key] = trim($parts[1] ?? '');
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

    $headers['Cache-Control'] = 's-maxage=' . (int) $runcache_expiry . ', max-age=60';

    $meta = [
        'time' => time(),
        'expires' => time() + (int) $runcache_expiry,
        'headers' => $headers,
    ];

    // Write the gzip file first. Store its size in the meta.
    if (!empty($runcache_config['gzip_enabled']) && function_exists('gzencode')) {
        $gzipped = gzencode($content, 6);
        if ($gzipped && file_put_contents($runcache_file_html . '.gz', $gzipped) !== false) {
            $meta['gzip_size'] = strlen($gzipped);
        }
    }

    // Write the meta file and the page.
    $meta_written = file_put_contents($runcache_file_meta, json_encode($meta)) !== false;
    $body_written = file_put_contents($runcache_file_html, $content) !== false;

    // Send STORE only when both writes succeed.
    if ($meta_written && $body_written && !headers_sent()) {
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

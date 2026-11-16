<?php

/**
 * RunCache WordPress plugin
 *
 * Single source of truth for the Redis page-cache key shapes. The drop-in
 * generator inlines this file into advanced-cache.php, and the admin-side
 * purger requires it, so both sides build identical keys.
 *
 * @package RunCache
 * @since 1.3.1
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions -- Shared by the drop-in, which runs before WordPress loads.

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One glob class per hex digit of a variant suffix (md5 substring, 8 chars).
 * Variants in keys are 'q_' or 'e_' followed by these digits.
 */
const RUNCACHE_REDIS_KEYS_VARIANT_CLASS = '[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]';

if (!function_exists('runcache_redis_page_sanitize_host')) {
    /**
     * Sanitizes a request host to the drop-in's host form.
     *
     * Mirrors includes/runcache-page-redis.php and
     * includes/runcache-page-php.php: the host keeps dots, every other
     * outside character becomes '-', and an empty result falls back.
     *
     * @param string $host Raw host value, e.g. from HTTP_HOST.
     * @return string Sanitized host, never empty.
     */
    function runcache_redis_page_sanitize_host(string $host): string
    {
        $host = preg_replace('/[^a-zA-Z0-9.-]/', '-', $host) ?: '';
        $host = trim($host, '.-');

        return $host !== '' ? $host : 'localhost';
    }
}

if (!function_exists('runcache_redis_page_site_part')) {
    /**
     * Builds the site namespace segment from a sanitized host.
     *
     * Mirrors the drop-in: lowercase, every character outside [a-z0-9]
     * becomes '-', so 'example.com' becomes 'example-com'.
     *
     * @param string $sanitized_host Host already passed through runcache_redis_page_sanitize_host().
     * @return string Site namespace segment, never empty.
     */
    function runcache_redis_page_site_part(string $sanitized_host): string
    {
        $site = strtolower($sanitized_host);
        $site = preg_replace('/[^a-z0-9]/', '-', $site) ?? '';
        $site = trim($site, '-');

        return $site !== '' ? $site : 'localhost';
    }
}

if (!function_exists('runcache_redis_page_sanitize_segments')) {
    /**
     * Sanitizes a request path into the drop-in's safe segments.
     *
     * Mirrors the drop-in: each segment keeps [A-Za-z0-9_-], and empty,
     * dot, and dot-dot segments are dropped.
     *
     * @param string $path Raw request path without query string.
     * @return array<int, string> Safe path segments, possibly empty.
     */
    function runcache_redis_page_sanitize_segments(string $path): array
    {
        $path = str_replace('\\', '/', trim($path, '/'));

        $safe_segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }

            $segment = preg_replace('/[^a-zA-Z0-9_-]/', '-', $segment) ?? '';
            if ($segment !== '') {
                $safe_segments[] = $segment;
            }
        }

        return $safe_segments;
    }
}

if (!function_exists('runcache_redis_page_key_head')) {
    /**
     * Builds the key head shared by every key of one request host:
     * '{base}:{site}:{dot-host}'.
     *
     * @param string $base_prefix    Base key prefix, e.g. 'runcache:'.
     * @param string $sanitized_host Host already passed through runcache_redis_page_sanitize_host().
     * @return string Key head with no trailing separator.
     */
    function runcache_redis_page_key_head(string $base_prefix, string $sanitized_host): string
    {
        return rtrim($base_prefix, ':') . ':' . runcache_redis_page_site_part($sanitized_host) . ':' . $sanitized_host;
    }
}

if (!function_exists('runcache_redis_page_url_patterns')) {
    /**
     * Builds the glob patterns that match one URL's cache entries.
     *
     * The drop-in stores one URL's entries as '{head}:{host}' plus the
     * safe path segments, an 'index' segment for trailing-slash URLs,
     * and q_/e_ variant segments after 'index'. The pattern set covers
     * the plain entry, the index form, and both variant forms, but no
     * deeper URLs. Mirror of the drop-in key builder in
     * includes/runcache-page-redis.php.
     *
     * @param string $base_prefix Base key prefix, e.g. 'runcache:'.
     * @param string $url_host    Raw host of the URL.
     * @param string $url_path    Raw path of the URL without query string.
     * @return array<int, string> Glob patterns for SCAN.
     */
    function runcache_redis_page_url_patterns(string $base_prefix, string $url_host, string $url_path): array
    {
        $sanitized_host = runcache_redis_page_sanitize_host($url_host);
        $head = runcache_redis_page_key_head($base_prefix, $sanitized_host);
        $safe_segments = runcache_redis_page_sanitize_segments($url_path);
        $variant = '[qe]_' . RUNCACHE_REDIS_KEYS_VARIANT_CLASS . '*';

        // The drop-in repeats the host as the first key path segment.
        $base = $head . ':' . $sanitized_host;
        if ($safe_segments !== []) {
            $base .= '/' . implode('/', $safe_segments);
        }

        return [
            $base,
            $base . '/index',
            $base . '/index/' . $variant,
            $base . '/' . $variant,
        ];
    }
}

if (!function_exists('runcache_redis_page_site_patterns')) {
    /**
     * Builds the glob patterns that match every cache entry of one site.
     *
     * An empty site path (single site, subdomain site, or the main site of
     * a subdirectory network) yields the whole host namespace; the caller
     * then filters main-site keys against the child site paths.
     *
     * @param string $base_prefix Base key prefix, e.g. 'runcache:'.
     * @param string $url_host    Raw host of the site.
     * @param string $site_path   Registered site path without slashes at the ends, e.g. 'site1'.
     * @return array<int, string> Glob patterns for SCAN.
     */
    function runcache_redis_page_site_patterns(string $base_prefix, string $url_host, string $site_path): array
    {
        $sanitized_host = runcache_redis_page_sanitize_host($url_host);
        $head = runcache_redis_page_key_head($base_prefix, $sanitized_host);
        $safe_segments = runcache_redis_page_sanitize_segments($site_path);

        // The drop-in repeats the host as the first key path segment.
        $base = $head . ':' . $sanitized_host;

        if ($safe_segments === []) {
            return [$base . '/*'];
        }

        return [
            $base . '/' . implode('/', $safe_segments) . '/*',
            $base . '/' . implode('/', $safe_segments),
        ];
    }
}

if (!function_exists('runcache_redis_page_key_in_site_scope')) {
    /**
     * Reports whether one scanned key belongs to the site being purged.
     *
     * Used by the main site of a subdirectory network, whose host
     * namespace also holds the child sites' entries. A key stays in scope
     * when the part after '{head}:{host}/' does not fall inside a child
     * path.
     *
     * @param string             $key              Scanned Redis key.
     * @param string             $base_prefix      Base key prefix, e.g. 'runcache:'.
     * @param string             $url_host         Raw host of the site.
     * @param string             $site_path        Registered site path of the purged site, '' for the host scope.
     * @param array<int, string> $child_site_paths Registered child site paths, e.g. ['site1', 'site1/deep'].
     * @return bool True when the key is in the purge scope.
     */
    function runcache_redis_page_key_in_site_scope(
        string $key,
        string $base_prefix,
        string $url_host,
        string $site_path,
        array $child_site_paths
    ): bool {
        $sanitized_host = runcache_redis_page_sanitize_host($url_host);
        $head = runcache_redis_page_key_head($base_prefix, $sanitized_host);

        // The drop-in repeats the host as the first key path segment.
        $key_root = $head . ':' . $sanitized_host . '/';
        if (!str_starts_with($key, $key_root)) {
            return false;
        }

        $safe_site_segments = runcache_redis_page_sanitize_segments($site_path);
        if ($safe_site_segments !== []) {
            // A child site's patterns already bound the scope, so every
            // key under its head belongs to the purged site.
            return true;
        }

        // Main site of a subdirectory network: drop keys that live inside
        // a child site's path prefix.
        $remainder = substr($key, strlen($key_root));
        foreach ($child_site_paths as $child_path) {
            $safe_child_segments = runcache_redis_page_sanitize_segments((string) $child_path);
            if ($safe_child_segments === []) {
                continue;
            }

            $child_base = implode('/', $safe_child_segments);
            if ($remainder === $child_base || str_starts_with($remainder, $child_base . '/')) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('runcache_redis_page_network_pattern')) {
    /**
     * Builds the glob pattern that matches every page-cache entry of the
     * whole install. Network purge only; the pattern also covers the
     * object-cache namespace, which the network purge clears anyway.
     *
     * @param string $base_prefix Base key prefix, e.g. 'runcache:'.
     * @return string Glob pattern for SCAN.
     */
    function runcache_redis_page_network_pattern(string $base_prefix): string
    {
        return rtrim($base_prefix, ':') . ':*';
    }
}

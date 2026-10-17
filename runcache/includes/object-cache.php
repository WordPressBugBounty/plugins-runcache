<?php

/**
 * RunCache Object Cache Drop-in
 *
 * Provides a Redis-backed implementation of WordPress' object cache API.
 * Mirrors the connection and authentication logic used by the
 * Redis page-cache drop-in so both layers share identical connectivity
 * behaviour.
 *
 * @package RunCache
 * @since 1.0.0
 */

declare(strict_types=1);

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions -- Drop-in runs before WordPress loads, wp_ functions not available
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals -- These are WordPress standard object cache drop-in functions

if (!defined('ABSPATH')) {
    exit;
}

global $runcache_redis_connection, $runcache_redis_connection_attempted;
$runcache_redis_connection = $runcache_redis_connection ?? null;
$runcache_redis_connection_attempted = false;

// In-memory cache for current request
global $runcache_memory_cache;
$runcache_memory_cache = [];

// Blog prefix for multisite support
global $runcache_global_prefix;
$runcache_global_prefix = '';

global $runcache_blog_prefix;
$runcache_blog_prefix = 0;

/**
 * Remove common credential values from drop-in log messages.
 *
 * @param string $message
 * @return string
 */
function runcache_redact_log_message(string $message): string
{
    $patterns = [
        '/((?:password|passwd|secret|token|api[_-]?key|access[_-]?key|authorization)\\s*[:=]\\s*)([^\\s,;]+)/i',
        '/(Bearer\\s+)[^\\s,;]+/i',
    ];

    return preg_replace($patterns, '$1[REDACTED]', $message) ?? '[REDACTED]';
}

// Group configuration
global $runcache_global_groups, $runcache_ignored_groups;

if (!isset($runcache_global_groups)) {
    $runcache_global_groups = [
        'blog-details',
        'blog-id-cache', 
        'blog-lookup',
        'global-posts',
        'networks',
        'rss',
        'sites',
        'site-details',
        'site-lookup',
        'site-options',
        'site-transient',
        'users',
        'useremail',
        'userlogins',
        'usermeta',
        'userslugs',
        'redis-cache',
    ];
}

if (!isset($runcache_ignored_groups)) {
    $runcache_ignored_groups = [];
}

/**
 * Gets the Redis key prefix for object cache entries.
 *
 * @return string The key prefix.
 */
function runcache_object_cache_get_prefix(): string
{
    $base_prefix = defined('RUNCACHE_REDIS_KEY') ? (string) RUNCACHE_REDIS_KEY : 'runcache:';
    $base_prefix = rtrim($base_prefix, ':') . ":";
    return $base_prefix . 'object:';
}

/**
 * Normalizes Redis SCAN results across PhpRedis and Predis.
 *
 * PhpRedis updates the cursor via the by-reference $iterator argument and
 * returns a flat array of keys. Predis (and the raw Redis protocol) return
 * [cursor, [keys]]. This helper extracts the keys and, for Predis-style
 * results, updates the iterator value so the calling loop can terminate.
 *
 * @param int|null $iterator Cursor variable passed by reference.
 * @param array|false $result The value returned by $redis->scan().
 * @return array<int,string> Array of keys (empty if result is invalid).
 */
function runcache_normalize_scan_result(&$iterator, $result): array
{
    if ($result === false) {
        $iterator = 0;
        return [];
    }

    if (!is_array($result)) {
        return [];
    }

    // Predis / protocol-style shape: [cursor (int), keys (array)].
    if (isset($result[0]) && is_int($result[0]) && isset($result[1]) && is_array($result[1])) {
        $iterator = $result[0];
        return array_values(array_filter($result[1], 'is_string'));
    }

    // PhpRedis shape: flat array of keys (cursor already updated by reference).
    return array_values(array_filter($result, 'is_string'));
}

/**
 * Sanitizes a key part to prevent Redis key collisions.
 *
 * @param mixed $part The key part to sanitize.
 * @return string The sanitized key part.
 */
function runcache_sanitize_key_part($part): string
{
    return is_string($part) ? str_replace(':', '-', $part) : (string) $part;
}

/**
 * Checks if a cache group is a global group.
 *
 * Global groups are shared across all blogs in multisite installations.
 *
 * @param mixed $group The cache group to check.
 * @return bool True if the group is global, false otherwise.
 */
function runcache_is_global_group($group): bool
{
    global $runcache_global_groups;
    return in_array($group, $runcache_global_groups, true);
}

/**
 * Initializes blog prefixes for multisite support.
 *
 * Sets up the global and blog-specific prefixes used in cache keys.
 *
 * @return void
 */
function runcache_initialize_prefixes(): void
{
    global $runcache_global_prefix, $runcache_blog_prefix;

    // Only initialize once
    if (isset($GLOBALS['runcache_prefixes_initialized'])) {
        return;
    }
    $GLOBALS['runcache_prefixes_initialized'] = true;

    if (function_exists('is_multisite')) {
        global $blog_id, $table_prefix;
        $runcache_global_prefix = is_multisite() ? '' : $table_prefix;
        $runcache_blog_prefix = is_multisite() ? (int) $blog_id : $table_prefix;
    }
}

/**
 * Establishes a connection to Redis server.
 *
 * @return \Redis|null Redis connection object or null on failure.
 */
function runcache_object_cache_connect(): ?\Redis
{
    if (!class_exists('Redis')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis extension not available');
        }
        return null;
    }

    $host = defined('RUNCACHE_REDIS_HOST') && RUNCACHE_REDIS_HOST !== ''
        ? (string) RUNCACHE_REDIS_HOST
        : '127.0.0.1';
    $port = defined('RUNCACHE_REDIS_PORT') ? (int) RUNCACHE_REDIS_PORT : 6379;

    try {
        $redis = new \Redis();

        if (!$redis->connect($host, $port, 1.0)) {
            throw new \RuntimeException('Failed to connect to Redis');
        }

        if (function_exists('runcache_authenticate_redis')) {
            runcache_authenticate_redis($redis);
        } else {
            $password = defined('RUNCACHE_REDIS_PASSWORD') ? trim((string) RUNCACHE_REDIS_PASSWORD) : '';
            $username = defined('RUNCACHE_REDIS_USERNAME') ? trim((string) RUNCACHE_REDIS_USERNAME) : '';

            if ($password !== '') {
                if ($username !== '') {
                    if (!$redis->auth([$username, $password])) {
                        throw new \RuntimeException('Redis authentication failed');
                    }
                } elseif (!$redis->auth($password)) {
                    throw new \RuntimeException('Redis authentication failed');
                }
            }
        }

        if (defined('RUNCACHE_REDIS_DATABASE')) {
            if (!$redis->select((int) RUNCACHE_REDIS_DATABASE)) {
                throw new \RuntimeException('Failed to select Redis database');
            }
        }

        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 1);
        return $redis;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis connection error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return null;
    }
}

/**
 * Gets or creates the Redis connection.
 *
 * @return \Redis|null Redis connection object or null if unavailable.
 */
function runcache_object_cache_get_connection(): ?\Redis
{
    global $runcache_redis_connection, $runcache_redis_connection_attempted;

    if ($runcache_redis_connection_attempted) {
        return $runcache_redis_connection;
    }

    $runcache_redis_connection_attempted = true;
    $runcache_redis_connection = runcache_object_cache_connect();

    return $runcache_redis_connection;
}

/**
 * Builds a Redis key from group and key parts.
 *
 * @param mixed $group The cache group.
 * @param mixed $key   The cache key.
 * @return string The full Redis key.
 */
function runcache_object_cache_build_key($group, $key): string
{
    global $runcache_global_prefix, $runcache_blog_prefix;

    // Match WordPress core: an empty group is treated as 'default'.
    if ('' === (string) $group) {
        $group = 'default';
    }

    // Initialize prefixes if not done
    runcache_initialize_prefixes();

    // Sanitize key parts to prevent Redis key collisions
    $sanitized_group = runcache_sanitize_key_part($group);
    $sanitized_key = runcache_sanitize_key_part($key);

    // Use blog-specific prefix for non-global groups
    $prefix = runcache_is_global_group($sanitized_group)
        ? (string) $runcache_global_prefix
        : (string) $runcache_blog_prefix;

    // Clean prefix - remove trailing separators
    $prefix = trim($prefix, '_-:$');
        
        // Only add separator if prefix is not empty
        $prefix_part = $prefix !== '' ? $prefix . ':' : '';

        return runcache_object_cache_get_prefix() . $prefix_part . $sanitized_group . ':' . $sanitized_key;
}

/**
 * Checks if a cache group should be persisted to Redis.
 *
 * @param mixed $group The cache group to check.
 * @return bool True if the group should be cached, false otherwise.
 */
function runcache_should_cache_group($group): bool
{
    global $runcache_ignored_groups;
    return !in_array((string) $group, $runcache_ignored_groups, true);
}

/**
 * Applies the optional Redis object-cache maximum TTL.
 *
 * A missing, invalid, zero, or negative maximum preserves WordPress behavior.
 * When enabled, zero expiration receives the maximum and larger expirations
 * are capped at it.
 *
 * @param mixed $expiration Requested expiration in seconds.
 * @return int Effective expiration in seconds, or 0 for no expiration.
 */
function runcache_object_cache_normalize_expiration($expiration): int
{
    $expiration = (int) $expiration;
    $max_ttl = defined('RUNCACHE_REDIS_MAXTTL')
        ? (int) RUNCACHE_REDIS_MAXTTL
        : 0;

    if ($max_ttl <= 0) {
        return max(0, $expiration);
    }

    if ($expiration <= 0) {
        return $max_ttl;
    }

    return min($expiration, $max_ttl);
}

/**
 * Helper to store value in cache with optional existence check.
 *
 * @param string $key           The cache key.
 * @param mixed  $value         The value to store.
 * @param string $group         The cache group.
 * @param int    $expiration    The expiration time.
 * @param bool   $check_exists  If true, only set if key exists (replace). If false, only set if key doesn't exist (add).
 * @return bool True on success, false on failure.
 */
function runcache_cache_set_helper($key, $value, $group, $expiration, $check_exists = null): bool
{
    global $runcache_memory_cache;

    // Check WordPress cache suspension for add operations
    if ($check_exists === false && function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
        return false;
    }

    $redis_key = runcache_object_cache_build_key($group, $key);

    // Handle existence check in memory
    $exists_in_memory = isset($runcache_memory_cache[$redis_key]);

    if ($check_exists === true && !$exists_in_memory) {
        // Replace: key must exist in memory
        // Note: We don't check Redis here to avoid extra round-trip
        // Redis XX flag will handle this when we persist
        return false;
    }

    if ($check_exists === false && $exists_in_memory) {
        // Add: key must not exist in memory
        return false;
    }

    // Store in memory immediately (we'll revert if Redis rejects)
    $old_value = $runcache_memory_cache[$redis_key] ?? null;
    $runcache_memory_cache[$redis_key] = $value;

    // Don't persist ignored groups to Redis
    if (!runcache_should_cache_group($group)) {
        return true;
    }

    $redis = runcache_object_cache_get_connection();
    if (!$redis) {
        return true;
    }

    try {
        $payload = serialize($value);
        $exp = runcache_object_cache_normalize_expiration($expiration);
        $result = false;

        if ($check_exists === false) {
            // Add operation: use NX flag - only set if key doesn't exist
            $options = $exp > 0 ? ['NX', 'EX' => $exp] : ['NX'];
            $result = (bool) $redis->set($redis_key, $payload, $options);
        } elseif ($check_exists === true) {
            // Replace operation: use XX flag - only set if key exists
            $options = $exp > 0 ? ['XX', 'EX' => $exp] : ['XX'];
            $result = (bool) $redis->set($redis_key, $payload, $options);
        } else {
            // Standard set operation
            if ($exp > 0) {
                $result = (bool) $redis->setex($redis_key, $exp, $payload);
            } else {
                $result = (bool) $redis->set($redis_key, $payload);
            }
        }

        // If add/replace failed (key exists/doesn't exist), revert memory cache
        if (!$result && $check_exists !== null) {
            if ($check_exists === false) {
                // Add failed: key exists in Redis - remove from memory
                unset($runcache_memory_cache[$redis_key]);
            } elseif ($check_exists === true) {
                // Replace failed: key doesn't exist in Redis - revert to old value
                if ($old_value !== null) {
                    $runcache_memory_cache[$redis_key] = $old_value;
                } else {
                    unset($runcache_memory_cache[$redis_key]);
                }
            }
        }

        return $result;
    } catch (\Throwable $exception) {
        $operation = $check_exists === false ? 'add' : ($check_exists === true ? 'replace' : 'set');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis ' . $operation . ' error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        // Graceful degradation: memory cache is already set, return true
        return true;
    }
}

function wp_cache_init(): void
{
    runcache_object_cache_get_connection();
}

/**
 * Determines whether the object cache implementation supports a particular feature.
 *
 * @param string $feature Name of the feature to check for.
 * @return bool True if the feature is supported, false otherwise.
 */
function wp_cache_supports(string $feature): bool
{
    switch ($feature) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
        case 'flush_group':
            return true;

        default:
            return false;
    }
}

function wp_cache_add_global_groups($groups)
{
    global $runcache_global_groups;
    
    if (!is_array($groups)) {
        $groups = [$groups];
    }
    
    foreach ($groups as $group) {
        $group = (string) $group;
        if (!in_array($group, $runcache_global_groups, true)) {
            $runcache_global_groups[] = $group;
        }
    }
    return true;
}

function wp_cache_add_non_persistent_groups($groups)
{
    global $runcache_ignored_groups;
    
    if (!is_array($groups)) {
        $groups = [$groups];
    }
    
    foreach ($groups as $group) {
        $group = (string) $group;
        if (!in_array($group, $runcache_ignored_groups, true)) {
            $runcache_ignored_groups[] = $group;
        }
    }
    return true;
}

function wp_cache_switch_to_blog($blog_id)
{
    global $runcache_blog_prefix;
    global $runcache_global_prefix;
    
    if (!function_exists('is_multisite') || !is_multisite()) {
        return false;
    }
    
    // Only sanitize and use the blog ID if valid
    $runcache_blog_prefix = (int) $blog_id;
    
    return true;
}

function wp_cache_get($key, $group = 'default', $force = false, &$found = null)
{
    global $runcache_memory_cache;

    $redis_key = runcache_object_cache_build_key($group, $key);

    // Check in-memory cache first (fast!)
    if (!$force && isset($runcache_memory_cache[$redis_key])) {
        $found = true;
        return $runcache_memory_cache[$redis_key];
    }

    $is_ignored = !runcache_should_cache_group($group);

    if ($is_ignored) {
        // For ignored groups, only check in-memory cache
        $found = isset($runcache_memory_cache[$redis_key]);
        return $runcache_memory_cache[$redis_key] ?? false;
    }

    $redis = runcache_object_cache_get_connection();
    
    // Graceful degradation: if Redis fails, let WordPress use DB
    if (!$redis) {
        $found = false;
        return false;
    }

    try {
        // Check if key exists first to properly handle false values
        if (!$redis->exists($redis_key)) {
            $found = false;
            return false;
        }
        
        $value = $redis->get($redis_key);
        $found = true;
        
        // Handle stored false value properly
        if ($value === false) {
            $runcache_memory_cache[$redis_key] = false;
            return false;
        }
        
        $unserialized = unserialize($value);
        
        // Store in memory for subsequent calls
        $runcache_memory_cache[$redis_key] = $unserialized;
        
        return $unserialized;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis get error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        $found = false;
        return false;
    }
}

function wp_cache_set($key, $value, $group = 'default', $expiration = 0)
{
    return runcache_cache_set_helper($key, $value, $group, $expiration, null);
}

function wp_cache_add($key, $value, $group = 'default', $expiration = 0)
{
    return runcache_cache_set_helper($key, $value, $group, $expiration, false);
}

function wp_cache_replace($key, $value, $group = 'default', $expiration = 0)
{
    return runcache_cache_set_helper($key, $value, $group, $expiration, true);
}

function runcache_delete_redis_batch(\Redis $redis, array $keys): void
{
    if (empty($keys)) {
        return;
    }

    if (method_exists($redis, 'unlink')) {
        $redis->unlink($keys);
        return;
    }

    $redis->del($keys);
}

function wp_cache_delete($key, $group = 'default')
{
    global $runcache_memory_cache;

    $redis_key = runcache_object_cache_build_key($group, $key);
    
    // Delete from memory - always, even for ignored groups
    $redis_exists = isset($runcache_memory_cache[$redis_key]);
    unset($runcache_memory_cache[$redis_key]);

    // For ignored groups, just check in-memory status
    if (!runcache_should_cache_group($group)) {
        return $redis_exists;
    }

    $redis = runcache_object_cache_get_connection();
    
    if (!$redis) {
        return $redis_exists;
    }

    try {
        return $redis->del($redis_key) > 0 || $redis_exists;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis delete error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return $redis_exists;
    }
}

function wp_cache_flush()
{
    global $runcache_memory_cache;

    $redis = runcache_object_cache_get_connection();
    
    if (!$redis) {
        return false;
    }

    try {
        // Process bounded batches to avoid retaining the full key set in PHP.
        $iterator = null;
        $pattern = runcache_object_cache_get_prefix() . '*';
        $scan_count = 500;
        
        do {
            $result = $redis->scan($iterator, $pattern, $scan_count);
            $keys = runcache_normalize_scan_result($iterator, $result);

            if (!empty($keys)) {
                foreach ($keys as $key) {
                    unset($runcache_memory_cache[$key]);
                }
                runcache_delete_redis_batch($redis, $keys);
            }
        } while ($iterator !== 0);

        $runcache_memory_cache = [];

        return true;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis flush error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return false;
    }
}

/**
 * Clears the in-memory (runtime) cache without affecting Redis.
 *
 * This is used for clearing the PHP process cache only.
 *
 * @return bool True on success
 */
function wp_cache_flush_runtime(): bool
{
    global $runcache_memory_cache;
    $runcache_memory_cache = [];
    return true;
}

/**
 * Clears all cache entries in a specific group.
 *
 * Note: Redis doesn't support wildcards for bulk delete, so this uses incremental
 * SCAN batches and UNLINK, with DEL as a compatibility fallback.
 *
 * @param string $group The cache group to flush
 * @return bool True on success
 */
function wp_cache_flush_group($group): bool
{
    global $runcache_memory_cache;

    $redis = runcache_object_cache_get_connection();

    if (!$redis) {
        return false;
    }

    try {
        // Build the pattern for this group
        $sanitized_group = runcache_sanitize_key_part($group);
        $base_prefix = runcache_object_cache_get_prefix();
        $pattern = $base_prefix . '*:' . $sanitized_group . ':*';

        // Process bounded batches to avoid retaining the full key set in PHP.
        $iterator = null;
        $scan_count = 500;
        do {
            $result = $redis->scan($iterator, $pattern, $scan_count);
            $keys = runcache_normalize_scan_result($iterator, $result);

            if (!empty($keys)) {
                // Remove from memory cache and Redis immediately.
                foreach ($keys as $key) {
                    unset($runcache_memory_cache[$key]);
                }
                runcache_delete_redis_batch($redis, $keys);
            }
        } while ($iterator !== 0);

        return true;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis flush_group error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return false;
    }
}

function wp_cache_close()
{
    global $runcache_redis_connection;

    if ($runcache_redis_connection instanceof \Redis) {
        try {
            $runcache_redis_connection->close();
        } catch (\Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[RunCache] Redis close error: ' . runcache_redact_log_message($exception->getMessage()));
            }
        }
    }

    return true;
}

function wp_cache_get_multiple($keys, $group = 'default')
{
    global $runcache_memory_cache;

    if (!$keys) {
        return [];
    }

    $is_ignored = !runcache_should_cache_group($group);

    // First, build results from in-memory cache
    $results = [];
    $keys_to_fetch = [];

    foreach ($keys as $key) {
        $redis_key = runcache_object_cache_build_key($group, $key);
        
        if (isset($runcache_memory_cache[$redis_key])) {
            $results[$key] = $runcache_memory_cache[$redis_key];
        } else {
            $results[$key] = false;
            $keys_to_fetch[$key] = $redis_key;
        }
    }

    // For ignored groups, only return in-memory results
    if ($is_ignored || empty($keys_to_fetch)) {
        return $results;
    }

    $redis = runcache_object_cache_get_connection();
    
    if (!$redis) {
        return $results;
    }

    try {
        $redis_keys = array_values($keys_to_fetch);
        $values = $redis->mGet($redis_keys);

        foreach ($keys_to_fetch as $key => $redis_key) {
            $index = array_search($redis_key, $redis_keys, true);
            $value = ($index !== false) ? ($values[$index] ?? null) : null;

            if ($value === false || $value === null) {
                $results[$key] = false;
            } else {
                $unserialized = unserialize($value);
                $results[$key] = $unserialized;
                
                // Store in memory
                $runcache_memory_cache[$redis_key] = $unserialized;
            }
        }

        return $results;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis mget error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return $results;
    }
}

function wp_cache_incr($key, $offset = 1, $group = 'default')
{
    // Don't use Redis for ignored groups
    if (!runcache_should_cache_group($group)) {
        return false;
    }

    $redis = runcache_object_cache_get_connection();
    if (!$redis) {
        return false;
    }

    try {
        $redis_key = runcache_object_cache_build_key($group, $key);
        
        $result = $redis->incrBy($redis_key, (int) $offset);
        
        // Update memory cache
        if ($result !== false) {
            $runcache_memory_cache[$redis_key] = $result;
        }
        
        return $result;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis incr error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return false;
    }
}

function wp_cache_decr($key, $offset = 1, $group = 'default')
{
    // Don't use Redis for ignored groups
    if (!runcache_should_cache_group($group)) {
        return false;
    }

    $redis = runcache_object_cache_get_connection();
    if (!$redis) {
        return false;
    }

    try {
        $redis_key = runcache_object_cache_build_key($group, $key);
        
        $result = $redis->decrBy($redis_key, (int) $offset);
        
        // Update memory cache
        if ($result !== false) {
            $runcache_memory_cache[$redis_key] = $result;
        }
        
        return $result;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis decr error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return false;
    }
}

/**
 * Sets multiple values to the cache in one call using Redis pipeline.
 *
 * @param array  $data       Array of key => value pairs to set
 * @param string $group      The cache group
 * @param int    $expiration Expiration time in seconds
 * @return bool[] Array of results keyed by original keys
 */
function wp_cache_set_multiple(array $data, $group = 'default', $expiration = 0): array
{
    global $runcache_memory_cache;

    if (empty($data)) {
        return [];
    }

    $results = [];
    $redis_data = [];

    // Store in memory immediately and prepare Redis data
    foreach ($data as $key => $value) {
        $redis_key = runcache_object_cache_build_key($group, $key);
        $runcache_memory_cache[$redis_key] = $value;
        $redis_data[$key] = $redis_key;
        $results[$key] = true;
    }

    // Skip Redis for ignored groups
    if (!runcache_should_cache_group($group)) {
        return $results;
    }

    $redis = runcache_object_cache_get_connection();
    if (!$redis) {
        return $results;
    }

    try {
        $pipe = $redis->pipeline();
        $exp = runcache_object_cache_normalize_expiration($expiration);

        foreach ($data as $key => $value) {
            $redis_key = $redis_data[$key];
            $payload = serialize($value);

            if ($exp > 0) {
                $pipe->setex($redis_key, $exp, $payload);
            } else {
                $pipe->set($redis_key, $payload);
            }
        }

        $pipe->exec();
        return $results;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis set_multiple error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return $results;
    }
}

/**
 * Adds multiple values to the cache in one call using Redis pipeline.
 *
 * Only adds if key doesn't already exist (NX semantics).
 *
 * @param array  $data       Array of key => value pairs to add
 * @param string $group      The cache group
 * @param int    $expiration Expiration time in seconds
 * @return bool[] Array of results keyed by original keys
 */
function wp_cache_add_multiple(array $data, $group = 'default', $expiration = 0): array
{
    global $runcache_memory_cache;

    if (empty($data)) {
        return [];
    }

    // Check cache addition suspension (WordPress API)
    if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) {
        return array_combine(array_keys($data), array_fill(0, count($data), false));
    }

    $results = [];
    $redis_data = [];

    // Check memory cache first
    foreach ($data as $key => $value) {
        $redis_key = runcache_object_cache_build_key($group, $key);

        if (isset($runcache_memory_cache[$redis_key])) {
            // Already exists in memory, can't add
            $results[$key] = false;
            continue;
        }

        $runcache_memory_cache[$redis_key] = $value;
        $redis_data[$key] = $redis_key;
        $results[$key] = true;
    }

    // Skip Redis for ignored groups
    if (!runcache_should_cache_group($group)) {
        return $results;
    }

    // Only process keys that passed the memory check
    if (empty($redis_data)) {
        return $results;
    }

    $redis = runcache_object_cache_get_connection();
    if (!$redis) {
        return $results;
    }

    try {
        $pipe = $redis->pipeline();
        $exp = runcache_object_cache_normalize_expiration($expiration);

        foreach ($redis_data as $key => $redis_key) {
            $payload = serialize($data[$key]);

            if ($exp > 0) {
                $pipe->set($redis_key, $payload, ['NX', 'EX' => $exp]);
            } else {
                $pipe->set($redis_key, $payload, ['NX']);
            }
        }

        $pipe_results = $pipe->exec();

        // Map results back to keys - Redis returns true/false for each NX set
        $i = 0;
        foreach ($redis_data as $key => $redis_key) {
            if (isset($pipe_results[$i])) {
                // If Redis returned false, key already existed - revert memory cache
                if (!$pipe_results[$i]) {
                    unset($runcache_memory_cache[$redis_key]);
                    $results[$key] = false;
                }
            }
            $i++;
        }

        return $results;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis add_multiple error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return $results;
    }
}

/**
 * Deletes multiple values from the cache in one call using Redis pipeline.
 *
 * @param array  $keys  Array of keys to delete
 * @param string $group The cache group
 * @return bool[] Array of results keyed by original keys
 */
function wp_cache_delete_multiple(array $keys, $group = 'default'): array
{
    global $runcache_memory_cache;

    if (empty($keys)) {
        return [];
    }

    $results = [];
    $redis_data = [];

    // Check memory cache
    foreach ($keys as $key) {
        $redis_key = runcache_object_cache_build_key($group, $key);

        if (isset($runcache_memory_cache[$redis_key])) {
            $results[$key] = true;
        } else {
            $results[$key] = false;
        }

        unset($runcache_memory_cache[$redis_key]);
        $redis_data[$key] = $redis_key;
    }

    // Skip Redis for ignored groups
    if (!runcache_should_cache_group($group)) {
        return $results;
    }

    $redis = runcache_object_cache_get_connection();
    if (!$redis) {
        return $results;
    }

    try {
        $pipe = $redis->pipeline();

        foreach ($redis_data as $redis_key) {
            $pipe->del($redis_key);
        }

        $pipe_results = $pipe->exec();

        // Update results based on actual Redis response
        $i = 0;
        foreach ($redis_data as $key => $redis_key) {
            if (isset($pipe_results[$i])) {
                $results[$key] = $pipe_results[$i] > 0;
            }
            $i++;
        }

        return $results;
    } catch (\Throwable $exception) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[RunCache] Redis delete_multiple error: ' . runcache_redact_log_message($exception->getMessage()));
        }
        return $results;
    }
}

<?php

/**
 * RunCache WordPress plugin
 *
 * Advanced cache drop-in router.
 * Checks if a request can be cached. Routes the request to the handler.
 *
 * @package RunCache
 * @since 0.0.8
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions
// The drop-in runs before WordPress loads. Do not use WordPress functions here.

declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[RunCache] ABSPATH not defined, exiting');
    }
    exit;
}

/* [RUNCACHE_DROP_WHEN_INLINED: template-usage-note] */
// TEMPLATE FILE. src/Support/Dropin.php generates wp-content/advanced-cache.php
// from this template. The generator drops this note.
/* [/RUNCACHE_DROP_WHEN_INLINED: template-usage-note] */

// [RUNCACHE_INLINE: generation-note]

// [RUNCACHE_INLINE: runcache-config]

// [RUNCACHE_INLINE: runcache-filter]

// Stop here when the generated cache_enabled value is false.
if (array_key_exists('cache_enabled', $runcache_config) && !$runcache_config['cache_enabled']) {
    return;
}

// Get the provider that this drop-in was generated for.
$runcache_page_cache_type = $runcache_config['page_cache_type'] ?? 'php';

// [RUNCACHE_INLINE: page-cache-handler]

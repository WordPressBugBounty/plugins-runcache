import { PAGE_CACHE_CONFIG } from './IntegrationConfig.js'

/**
 * Determine the toast notification to show after a full-page cache type change.
 *
 * @param {object} params
 * @param {boolean} params.enable - Whether Full Page Cache is enabled.
 * @param {string} params.pageCacheType - The newly active cache type key.
 * @param {string} params.previousCache - The previous cache type key (used when switching to PHP).
 * @param {boolean} params.cacheVerified - Whether the backend verified the active cache configuration.
 * @returns {{ type: 'success' | 'warning', message: string }} Toast notification details.
 */
export function getCacheEnableToastMessage({
    enable,
    pageCacheType,
    previousCache,
    cacheVerified,
}) {
    if (!enable) {
        return { type: 'success', message: 'Full Page Cache has been disabled successfully!' }
    }

    if (pageCacheType === 'php') {
        const previousCacheLabel = PAGE_CACHE_CONFIG[previousCache]?.label || previousCache
        return { type: 'success', message: `${previousCacheLabel} is disabled successfully!` }
    }

    const cacheTypeName = PAGE_CACHE_CONFIG[pageCacheType]?.label || pageCacheType
    if (cacheVerified) {
        return { type: 'success', message: `${cacheTypeName} is enabled successfully!` }
    }

    return { type: 'warning', message: `${cacheTypeName} is enabled. Configuration required.` }
}

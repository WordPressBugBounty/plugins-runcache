import { apiGet, apiPost, apiCustom } from './client'

/**
 * Cache Management API
 * Handles all cache-related operations including settings, statistics, and cache control
 *
 * This module provides direct API functions for cache settings, statistics, purge, object cache,
 * and preload operations. Integration verification methods live in integrations.js.
 */

/**
 * Direct API functions for use with TanStack Query
 * These functions return promises and are designed to be used as queryFn
 */
export const cacheApi = {
    /**
     * Get current cache settings
     * @returns {Promise<Object>} Cache settings data
     * @example
     * // In TanStack Query composable:
     * useQuery({
     *   queryKey: queryKeys.cache.settings(),
     *   queryFn: () => cacheApi.getSettings()
     * })
     */
    async getSettings() {
        return apiGet('runcache_get_cache_settings')
    },

    /**
     * Save cache settings
     * @param {Object} settings - Cache settings to save
     * @param {boolean} settings.enable - Enable/disable cache
     * @param {string} settings.page_cache_type - Page cache type (php, redis, or server)
     * @returns {Promise<Object>} Saved settings data
     * @example
     * // In TanStack Query mutation:
     * useMutation({
     *   mutationFn: (settings) => cacheApi.saveSettings(settings),
     *   onSuccess: () => queryClient.invalidateQueries(queryKeys.cache.settings())
     * })
     */
    async saveSettings(settings) {
        const response = await apiPost('runcache_save_cache_settings', settings)
        // Handle nested response format: response.data.data
        return response.data || response
    },

    /**
     * Get cache statistics
     * @returns {Promise<Object>} Statistics including cached pages, cache size, compression ratio
     * @example
     * // In TanStack Query composable:
     * useQuery({
     *   queryKey: queryKeys.cache.stats(),
     *   queryFn: () => cacheApi.getStats(),
     *   refetchInterval: 60000 // Auto-refresh every minute
     * })
     */
    async getStats() {
        return apiGet('runcache_get_stats')
    },

    /**
     * Purge all cached content
     * @returns {Promise<Object>} Purge operation result
     * @example
     * // In TanStack Query mutation:
     * useMutation({
     *   mutationFn: () => cacheApi.purgeAll(),
     *   onSuccess: () => queryClient.invalidateQueries(queryKeys.cache.stats())
     * })
     */
    async purgeAll() {
        return apiGet('runcache_purge_all')
    },

    /**
     * Purge cache for a specific URL
     * @param {string} url - The exact URL to purge
     * @param {Object} options - Additional options
     * @param {boolean} options.preload - Whether to preload URL after purging
     * @returns {Promise<Object>} Purge operation result
     */
    async purgeByUrl(url, options = {}) {
        const { preload = false } = options

        if (!url || typeof url !== 'string') {
            throw new Error('A valid URL is required to purge cache')
        }

        return apiCustom('runcache_purge_by_url', {
            url,
            preload: preload ? '1' : '0',
        })
    },

    /**
     * Object Cache Settings
     * Manages object cache configuration (Redis)
     */
    objectCache: {
        /**
         * Get object cache settings
         * @returns {Promise<Object>} Object cache settings data
         */
        async get() {
            return apiGet('runcache_get_object_cache_settings')
        },

        /**
         * Save object cache settings
         * @param {Object} settings - Object cache settings to save
         * @param {boolean} settings.enable - Enable/disable object cache
         * @param {string} settings.object_cache_type - Object cache type (redis)
         * @returns {Promise<Object>} Saved settings data
         */
        async save(settings) {
            return apiPost('runcache_save_object_cache_settings', settings)
        },

        /**
         * Verify object cache setup
         * @param {Object} options - Request options (e.g., { showToast: true })
         * @returns {Promise<Object>} Verification results
         */
        async verify(options = {}) {
            return apiGet('runcache_verify_object_cache', options)
        },
    },

    /**
     * Preload Operations
     * Manages cache preloading functionality
     */
    preload: {
        /**
         * Start cache preload process
         * @param {string} url - Optional specific URL to preload
         * @returns {Promise<Object>} Preload start result
         */
        async start(url = '') {
            return apiGet('runcache_start_preload' + (url ? '?url=' + encodeURIComponent(url) : ''))
        },

        /**
         * Get current preload status
         * @returns {Promise<Object>} Preload status information
         */
        async getStatus() {
            return apiGet('runcache_get_preload_status')
        },

        /**
         * Clear the current preload queue
         * @returns {Promise<Object>} Clear operation result
         */
        async clear() {
            return apiGet('runcache_clear_preload_queue')
        },
    },
}

/**
 * Default export for convenience
 */
export default cacheApi

import { apiClient } from './client'

/**
 * Integrations API
 * Handles cache and external service integrations (Redis, asset CDN, edge CDN)
 */
export const integrationsApi = {
    /**
     * Redis Operations
     * Manages Redis connection and configuration
     */
    redis: {
        /**
         * Get Redis settings
         * @returns {Promise<Object>} Redis connection settings
         */
        async getSettings() {
            return apiClient.get('runcache_get_redis_settings')
        },

        /**
         * Save Redis settings
         * @param {Object} settings - Redis connection settings
         * @param {string} settings.host - Redis host address
         * @param {string} settings.port - Redis port number
         * @param {string} settings.username - Redis username (optional)
         * @param {string} settings.password - Redis password (optional)
         * @returns {Promise<Object>} Saved settings result
         */
        async saveSettings(settings) {
            return apiClient.post('runcache_save_redis_settings', settings)
        },

        /**
         * Test Redis connection
         * @returns {Promise<Object>} Connection test result
         */
        async testConnection() {
            return apiClient.get('runcache_test_redis_connection')
        },
    },

    /**
     * BunnyCDN Operations
     * Manages BunnyCDN integration
     */
    bunnycdn: {
        /**
         * Get BunnyCDN pull zones using API key
         * @param {string} apiKey - BunnyCDN API key
         * @returns {Promise<Object>} Available pull zones data
         */
        async getPullZones(apiKey) {
            return apiClient.custom('runcache_get_bunnycdn_pull_zones', { api_key: apiKey })
        },

        /**
         * Save CDN settings
         * @param {Object} settings - CDN settings
         * @param {boolean} settings.enable - Enable/disable CDN
         * @param {string} settings.cdn_type - CDN type (bunnycdn)
         * @param {string} settings.hostname - CDN hostname
         * @param {string} settings.api_key - CDN API key
         * @param {string} settings.pull_zone_id - CDN pull zone ID
         * @param {Array} settings.allowed_extensions - Allowed file extensions
         * @returns {Promise<Object>} Saved settings result
         */
        async saveSettings(settings) {
            const response = await apiClient.post('runcache_save_bunnycdn_settings', settings)
            // Handle nested response format: response.data.data
            return response.data || response
        },
    },

    /**
     * Custom CDN Operations
     * Manages user-provided CDN configuration
     */
    customcdn: {
        /**
         * Get Custom CDN settings
         * @returns {Promise<Object>} Custom CDN settings data
         */
        async getSettings() {
            return apiClient.get('runcache_get_custom_cdn_settings')
        },

        /**
         * Save Custom CDN settings
         * @param {Object} settings - Custom CDN settings
         * @param {string} settings.hostname - CDN hostname
         * @param {Array<string>} [settings.allowed_extensions] - Allowed extensions
         * @param {boolean} [settings.enable] - Enable/disable CDN after saving
         * @param {string} [settings.cdn_type] - Optional CDN type override
         * @returns {Promise<{settings:Object,state:{enable:boolean,cdn_type:string}}>} Saved settings result
         */
        async saveSettings(settings) {
            const response = await apiClient.post('runcache_save_custom_cdn_settings', settings)
            return response.data || response
        },
    },

    /**
     * CDN Edge Cache Operations
     * Manages full-page caching providers at the CDN edge
     */
    edgeCdn: {
        async getSettings() {
            return apiClient.get('runcache_get_edge_settings')
        },

        async saveSettings(settings, options = {}) {
            const response = await apiClient.post('runcache_save_edge_settings', settings, options)
            return response.data || response
        },

        async saveProviderSettings(provider, settings, options = {}) {
            const actions = {
                cloudflare: 'runcache_save_cloudflare_settings',
                'cloudflare-apo': 'runcache_save_cloudflare_apo_settings',
            }
            const action = actions[provider]

            if (!action) {
                throw new Error(`Unsupported edge cache provider: ${provider}`)
            }

            const response = await apiClient.post(action, settings, options)
            return response.data || response
        },

        /**
         * Verify edge CDN setup
         * @param {Object} options - Request options (e.g., { showToast: true })
         * @returns {Promise<Object>} Edge CDN verification result
         */
        async verify(options = {}) {
            return apiClient.get('runcache_verify_edge', options)
        },
    },

    /**
     * Assets CDN Operations
     * Manages static asset delivery and rewriting
     */
    assetCdn: {
        /**
         * Get CDN settings
         * @returns {Promise<Object>} CDN settings data
         */
        async getSettings() {
            return apiClient.get('runcache_get_cdn_settings')
        },

        /**
         * Save CDN settings
         * @param {Object} settings - CDN settings
         * @param {boolean} settings.enable - Enable/disable CDN
         * @param {string} settings.cdn_type - CDN type (bunnycdn)
         * @returns {Promise<Object>} Saved settings result
         */
        async saveSettings(settings) {
            const response = await apiClient.post('runcache_save_cdn_settings', settings)
            // Handle nested response format: response.data.data
            return response.data || response
        },

        /**
         * Verify asset CDN setup
         * @param {Object} options - Request options (e.g., { showToast: true })
         * @returns {Promise<Object>} CDN verification result
         */
        async verify(options = {}) {
            return apiClient.get('runcache_verify_cdn', options)
        },
    },

    /**
     * Full Page Cache Operations
     * Handles page-cache verification
     */
    pageCache: {
        async verify(options = {}) {
            return apiClient.get('runcache_verify_cache', options)
        },
    },

    /**
     * System Information
     * Retrieves server and system information
     */
    system: {
        /**
         * Get server type and configuration
         * @returns {Promise<Object>} Server information
         */
        async getServerType() {
            return apiClient.get('runcache_get_server_type')
        },

        /**
         * Check PHP Redis extension availability
         * @returns {Promise<Object>} PHP Redis availability status
         */
        async checkPhpRedis() {
            return apiClient.get('runcache_check_phpredis')
        },
    },
}

import { apiGet, apiPost } from './client'

/**
 * Settings Management API
 * Handles CRUD operations for all plugin settings
 */
export const settingsApi = {
    /**
     * Purger Settings
     * Manages automatic cache purging configuration
     */
    purger: {
        /**
         * Get purger settings
         * @returns {Promise<Object>} Purger settings data
         */
        async get() {
            return apiGet('runcache_get_purger_settings')
        },

        /**
         * Save purger settings
         * @param {Object} settings - Purger settings to save
         * @param {boolean} settings.purge_permalink_onn - Purge on permalink change
         * @param {boolean} settings.purge_theme_switch_onn - Purge on theme switch
         * @param {boolean} settings.purge_general_onn - Purge on general changes
         * @param {boolean} settings.purge_customize_onn - Purge on customizer save
         * @param {boolean} settings.purge_upgrader_onn - Purge on plugin/theme update
         * @param {boolean} settings.purge_plugin_onn - Purge on plugin activation/deactivation
         * @param {boolean} settings.purge_navmenu_onn - Purge on nav menu update
         * @param {boolean} settings.purge_sidebar_onn - Purge on sidebar widget update
         * @param {boolean} settings.purge_widget_onn - Purge on widget parameter update
         * @param {boolean} settings.homepage_post_onn - Purge homepage on post publish/modify
         * @param {boolean} settings.homepage_removed_onn - Purge homepage on post trash
         * @param {boolean} settings.content_publish_onn - Purge content on publish/modify/trash
         * @param {boolean} settings.content_comment_approved_onn - Purge content on comment changes
         * @param {boolean} settings.archives_content_onn - Purge archives on content changes
         * @param {boolean} settings.archives_cpt_onn - Purge post type archive pages
         * @param {boolean} settings.archives_category_onn - Purge category/tag/taxonomy pages
         * @param {boolean} settings.archives_author_onn - Purge author pages

         * @param {boolean} settings.schedule_purge_onn - Enable scheduled purge
         * @param {number} settings.schedule_purge_int - Scheduled purge interval
         * @param {string} settings.schedule_purge_unt - Scheduled purge unit (60, 3600, 86400)
         * @param {boolean} settings.html_footprint_onn - Enable HTML footprint (debug)
         * @returns {Promise<Object>} Saved settings data
         */
        async save(settings) {
            return apiPost('runcache_save_purger_settings', settings)
        },
    },

    /**
     * Preloader Settings
     * Manages cache preloading configuration
     */
    preloader: {
        /**
         * Get preloader settings
         * @returns {Promise<Object>} Preloader settings data
         */
        async get() {
            return apiGet('runcache_get_preloader_settings')
        },

        /**
         * Save preloader settings
         * @param {Object} settings - Preloader settings to save
         * @param {boolean} settings.preload_post_onn - Preload on post publish
         * @param {number} settings.preload_speed - Background preload speed: URLs per minute (1-120)
         * @param {boolean} settings.preload_onn - Enable general preloading
         * @param {boolean} settings.preload_path_onn - Enable custom preload paths
         * @param {string} settings.preload_path_mch - Custom preload paths (one per line)
         * @param {boolean} settings.preload_schedule_onn - Enable scheduled preloading
         * @param {number} settings.preload_schedule_int - Scheduled preload interval
         * @param {string} settings.preload_schedule_unt - Scheduled preload unit (60, 3600, 86400)
         * @returns {Promise<Object>} Saved settings data
         */
        async save(settings) {
            return apiPost('runcache_save_preloader_settings', settings)
        },
    },

    /**
     * Rules Settings
     * Manages cache exclusion and inclusion rules
     */
    rules: {
        /**
         * Get rules settings
         * @returns {Promise<Object>} Rules settings data
         */
        async get() {
            return apiGet('runcache_get_rules_settings')
        },

        /**
         * Save rules settings
         * @param {Object} settings - Rules settings to save
         * @param {boolean} settings.exclude_url_onn - Enable URL path exclusion
         * @param {string} settings.exclude_url_mch - URL paths to exclude (one per line)
         * @param {boolean} settings.exclude_cookie_onn - Enable cookie exclusion
         * @param {string} settings.exclude_cookie_mch - Cookie names to exclude (one per line)
         * @param {boolean} settings.exclude_auto_onn - Exclude DONOTCACHEPAGE constant
         * @param {boolean} settings.exclude_browser_onn - Enable browser user-agent exclusion
         * @param {string} settings.exclude_browser_mch - User-agents to exclude (one per line)
         * @param {boolean} settings.exclude_visitorip_onn - Enable visitor IP exclusion
         * @param {string} settings.exclude_visitorip_mch - IP addresses to exclude (one per line)
         * @param {boolean} settings.ignore_query_onn - Enable query string ignoring
         * @param {string} settings.ignore_query_mch - Query strings to ignore (one per line)
         * @param {boolean} settings.allow_query_onn - Enable query string caching
         * @param {string} settings.allow_query_mch - Query strings to allow (one per line)
         * @param {boolean} settings.exclude_query_onn - Enable query string exclusion
         * @param {string} settings.exclude_query_mch - Query strings to exclude (one per line)
         * @param {boolean} settings.cache_key_extra_onn - Enable additional cache key
         * @param {string} settings.cache_key_extra_var - Additional cache key variable
         * @returns {Promise<Object>} Saved settings data
         */
        async save(settings) {
            return apiPost('runcache_save_rules_settings', settings)
        },
    },
}

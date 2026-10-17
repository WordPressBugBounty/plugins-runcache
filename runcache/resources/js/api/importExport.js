import { apiGet, apiCustom } from './client'

/**
 * Import / Export Settings API
 */
export const settingsTransferApi = {
    /**
     * Fetches a full settings bundle that can be saved as JSON.
     * @returns {Promise<Object>} Bundle containing metadata + settings arrays.
     */
    async export() {
        return apiGet('runcache_export_settings')
    },

    /**
     * Sends a previously exported bundle back to the server for processing.
     * @param {Object} settingsBundle - Parsed JSON contents from the export file.
     * @returns {Promise<Object>} Import response.
     */
    async import(settingsBundle) {
        return apiCustom('runcache_import_settings', {
            settings_payload: JSON.stringify(settingsBundle),
        })
    },
}

export default settingsTransferApi

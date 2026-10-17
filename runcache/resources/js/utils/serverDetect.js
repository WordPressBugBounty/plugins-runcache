import { integrationsApi } from '@/api/integrations'

/**
 * Shared single-flight server type detection.
 * All callers share one promise, so the SPA sends at most one request.
 */

let detectPromise = null

/**
 * Detect the server type when it is unknown.
 *
 * Fire-and-forget helper. Resolves with the server info payload or null on
 * failure. Never throws and never shows toasts.
 *
 * @returns {Promise<Object|null>} Server info payload
 */
export const detectServerType = () => {
    if (!detectPromise) {
        detectPromise = integrationsApi.system
            .getServerType()
            .then((data) => (data?.server_type ? data : null))
            .catch((error) => {
                console.error('Server type detection failed:', error)
                return null
            })
    }

    return detectPromise
}

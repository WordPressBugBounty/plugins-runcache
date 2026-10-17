import { cacheApi } from '@/api/cache'
import { ref } from 'vue'

// Create a reactive state store
const store = {
    isPreloading: ref(false),
    preloadMessage: ref(''),
    preloadStatus: ref(null), // Full status object
    statusInterval: null,
}

// Export a getter function to ensure all components access the same ref
export const usePreloader = () => ({
    isPreloading: store.isPreloading,
    preloadMessage: store.preloadMessage,
    preloadStatus: store.preloadStatus,
})

/**
 * Check preload status and update the store
 * @returns {Promise<Object|null>}
 */
export const checkPreloadStatus = async () => {
    try {
        console.log('[Preloader Poll] Checking preload status...')
        const data = await cacheApi.preload.getStatus()
        console.log('[Preloader Poll] Status response:', data)

        // Status can be: false (no queue), 'queued', 'processing', 'completed', 'failed'
        const statusValue = data.status

        // Active statuses that should trigger polling
        const isActive = statusValue === 'queued' || statusValue === 'processing'
        // Explicitly check for inactive statuses
        const isCompleted = statusValue === 'completed' || statusValue === 'failed'

        store.isPreloading.value = statusValue === 'queued' || statusValue === 'processing'
        store.preloadStatus.value = data

        if (isActive) {
            store.preloadMessage.value = data.message || 'Preloading...'
            // Start checking if not already checking
            if (!store.statusInterval) {
                startStatusCheck()
            }
        } else if (isCompleted) {
            // Preload finished. Stop checking and fully reset so the Preload
            // button returns to its normal idle state (no spinner, no queue /
            // dispatch text, no completion message).
            stopStatusCheck()
            store.isPreloading.value = false
            store.preloadMessage.value = ''
            store.preloadStatus.value = data
        } else {
            // No queue
            stopStatusCheck()
            store.preloadMessage.value = ''
        }

        return data
    } catch (error) {
        console.error('Failed to check preload status:', error)
        stopStatusCheck() // Stop checking on error
        return null
    }
}

/**
 * Start polling for status updates
 * @param {number} interval Polling interval in ms (default 2000)
 */
export const startStatusCheck = (interval = 2000) => {
    checkPreloadStatus() // Check immediately
    store.statusInterval = setInterval(checkPreloadStatus, interval)
}

/**
 * Stop polling for status updates
 */
export const stopStatusCheck = () => {
    if (store.statusInterval) {
        clearInterval(store.statusInterval)
        store.statusInterval = null
    }
}

/**
 * Start a preload process
 * @returns {Promise<Object|null>}
 */
export const startPreload = async () => {
    if (store.isPreloading.value) {
        console.log('Preload already in progress')
        return store.preloadStatus.value
    }

    store.isPreloading.value = true
    try {
        const data = await cacheApi.preload.start()

        // Start checking status immediately after successful preload start
        checkPreloadStatus()

        return data
    } catch (error) {
        console.error('Preload error:', error)
        store.isPreloading.value = false
        throw error
    }
}

/**
 * Clear the preload queue
 * @returns {Promise<boolean>}
 */
export const clearPreloadQueue = async () => {
    try {
        await cacheApi.preload.clear()
        stopStatusCheck()
        store.isPreloading.value = false
        store.preloadMessage.value = ''
        store.preloadStatus.value = null
        return true
    } catch (error) {
        console.error('Failed to clear preload queue:', error)
        return false
    }
}

/**
 * Get progress percentage
 * @returns {number}
 */
export const getProgress = () => {
    const status = store.preloadStatus.value
    if (!status || !status.total_urls || status.total_urls === 0) return 0
    return Math.round((status.processed_count / status.total_urls) * 100)
}

/**
 * Format status message with progress
 * @returns {string}
 */
export const getFormattedStatus = () => {
    const status = store.preloadStatus.value
    if (!status) return ''

    const { processed_count = 0, failed_count = 0, total_urls = 0 } = status
    const progress = total_urls > 0 ? Math.round((processed_count / total_urls) * 100) : 0

    if (failed_count > 0) {
        return `${processed_count}/${total_urls} URLs (${progress}%) - ${failed_count} failed`
    }
    return `${processed_count}/${total_urls} URLs (${progress}%)`
}

// Cleanup function to be called when component unmounts
export const cleanup = () => {
    stopStatusCheck()
}

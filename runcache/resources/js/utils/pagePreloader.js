import { ref, provide, inject } from 'vue'

const pagePreloaderKey = Symbol('pagePreloader')

const createPagePreloader = () => {
    const isPreloading = ref(true)
    const pendingPreloadTasks = ref(0)
    const preloadCompletionRequested = ref(false)

    const startPreloadTask = () => {
        pendingPreloadTasks.value += 1
    }

    const finishPreloadTask = () => {
        if (pendingPreloadTasks.value > 0) {
            pendingPreloadTasks.value -= 1
        }

        if (pendingPreloadTasks.value === 0 && preloadCompletionRequested.value) {
            isPreloading.value = false
        }
    }

    const trackPreload = async (operation) => {
        startPreloadTask()
        try {
            const result = typeof operation === 'function' ? await operation() : await operation
            return result
        } finally {
            finishPreloadTask()
        }
    }

    const requestPreloadComplete = () => {
        preloadCompletionRequested.value = true

        if (pendingPreloadTasks.value === 0) {
            isPreloading.value = false
        }
    }

    const resetPreload = () => {
        preloadCompletionRequested.value = false
        pendingPreloadTasks.value = 0
        isPreloading.value = true
    }

    return {
        isPreloading,
        pendingPreloadTasks,
        startPreloadTask,
        finishPreloadTask,
        trackPreload,
        requestPreloadComplete,
        resetPreload,
    }
}

export const providePagePreloader = () => {
    const preloader = createPagePreloader()
    provide(pagePreloaderKey, preloader)
    return preloader
}

export const usePagePreloader = () => {
    return inject(pagePreloaderKey, null)
}

/**
 * Runs an operation with page preloader tracking if available, otherwise executes it directly
 * This provides a fallback mechanism for components that work both with and without page preloader
 * @param {Object} pagePreloader - The page preloader instance from usePagePreloader()
 * @param {Function|any} operation - The operation to execute (function or value)
 * @returns {Promise<any>} The result of the operation
 */
export const runPreload = (pagePreloader, operation) => {
    if (pagePreloader?.trackPreload) {
        return pagePreloader.trackPreload(operation)
    }

    const task = typeof operation === 'function' ? operation() : operation
    return task
}

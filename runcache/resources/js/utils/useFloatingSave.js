import { ref, watch } from 'vue'

/**
 * Floating save composable for tracking settings changes
 * @param {Object} settings - Reactive settings object
 */
export function useFloatingSave(settings) {
    const hasChanges = ref(false)
    const originalSettings = ref(null)
    const isInitialized = ref(false)

    const storeOriginalSettings = () => {
        originalSettings.value = JSON.parse(JSON.stringify(settings.value))
        hasChanges.value = false
        isInitialized.value = true
    }

    const undoChanges = () => {
        if (originalSettings.value) {
            settings.value = JSON.parse(JSON.stringify(originalSettings.value))
            hasChanges.value = false
        }
    }

    watch(
        settings,
        () => {
            if (originalSettings.value && isInitialized.value) {
                const current = JSON.stringify(settings.value)
                const original = JSON.stringify(originalSettings.value)
                hasChanges.value = current !== original
            }
        },
        { deep: true }
    )

    const markAsSaved = () => {
        storeOriginalSettings()
    }

    return {
        hasChanges,
        storeOriginalSettings,
        undoChanges,
        markAsSaved,
        isInitialized,
    }
}

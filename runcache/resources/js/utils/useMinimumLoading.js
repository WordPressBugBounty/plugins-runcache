import { ref, watch, onBeforeUnmount } from 'vue'

export const useMinimumLoading = (sourceLoadingRef, minimumDuration = 350) => {
    const delayedLoading = ref(Boolean(sourceLoadingRef?.value))
    let loadingStartedAt = delayedLoading.value ? Date.now() : 0
    let hideTimeout = null

    const clearHideTimeout = () => {
        if (!hideTimeout) return
        clearTimeout(hideTimeout)
        hideTimeout = null
    }

    watch(
        sourceLoadingRef,
        (isLoading) => {
            clearHideTimeout()

            if (isLoading) {
                loadingStartedAt = Date.now()
                delayedLoading.value = true
                return
            }

            const elapsed = Date.now() - loadingStartedAt
            const remaining = Math.max(minimumDuration - elapsed, 0)

            if (remaining === 0) {
                delayedLoading.value = false
                return
            }

            hideTimeout = setTimeout(() => {
                delayedLoading.value = false
                hideTimeout = null
            }, remaining)
        },
        { immediate: true }
    )

    onBeforeUnmount(clearHideTimeout)

    return delayedLoading
}

import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import {
    SquareKanban,
    SquareBottomDashedScissors,
    LoaderPinwheel,
    Columns3Cog,
    ArrowUpDown,
} from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { cacheApi } from '@/api/cache'
import {
    startPreload,
    clearPreloadQueue,
    usePreloader,
    checkPreloadStatus,
    cleanup,
} from '@/utils/preloader'

// Icon mapping
const iconMap = {
    dashboard: SquareKanban,
    purger: SquareBottomDashedScissors,
    preloader: LoaderPinwheel,
    rules: Columns3Cog,
    importExport: ArrowUpDown,
}

export function useNavbar() {
    const router = useRouter()
    const cachedPages = ref(0)
    const isPurging = ref(false)
    const isStoppingPreload = ref(false)
    const { isPreloading, preloadMessage } = usePreloader()

    // Get navigation from router - single source of truth
    const navigation = computed(() => {
        return router
            .getRoutes()
            .filter((route) => route.name && route.meta?.icon)
            .map((route) => ({
                name: route.name,
                to: route.path,
                icon: iconMap[route.meta.icon],
            }))
    })

    const loadStats = async () => {
        try {
            const stats = await cacheApi.getStats()
            cachedPages.value = stats.cached_pages
        } catch (error) {
            console.error('Failed to load stats:', error)
        }
    }

    const preloadCache = async () => {
        try {
            await startPreload()
            await loadStats()
        } catch (error) {
            console.error('Error preloading cache:', error)
        }
    }

    const stopPreload = async () => {
        if (isStoppingPreload.value || !isPreloading.value) {
            return
        }

        const confirmed = confirm('Stop the preload process? Remaining URLs will be skipped.')

        if (!confirmed) {
            return
        }

        isStoppingPreload.value = true

        try {
            const stopped = await clearPreloadQueue()

            if (stopped) {
                toast.success('Preload stopped')
            } else {
                toast.error('Failed to stop preload')
            }
        } catch (error) {
            console.error('Failed to stop preload:', error)
            toast.error('Failed to stop preload')
        } finally {
            isStoppingPreload.value = false
        }
    }

    const purgeCache = async () => {
        const confirmed = confirm(
            'Are you sure you want to purge all cached pages? This action cannot be undone.'
        )

        if (!confirmed) {
            return
        }

        isPurging.value = true

        try {
            await cacheApi.purgeAll()
            //await loadStats()
        } catch (error) {
            console.error('Failed to purge cache:', error)
        } finally {
            isPurging.value = false
        }
    }

    return {
        navigation,
        cachedPages,
        isPreloading,
        isPurging,
        isStoppingPreload,
        preloadMessage,
        loadStats,
        preloadCache,
        stopPreload,
        purgeCache,
        checkPreloadStatus,
        cleanup,
    }
}

<template>
    <!-- Loading State -->
    <DashboardSkeleton v-if="isLoading" />

    <!-- Error State -->
    <Alert variant="destructive" v-else-if="error">
        <TriangleAlert class="rce-size-4" :stroke-width="2" />
        <AlertTitle>Failed to load Dashboard</AlertTitle>
        <AlertDescription
            class="rce-flex md:rce-flex-row rce-flex-col md:rce-items-center rce-gap-2">
            <div>{{ error }}</div>
            <button
                @click="getCacheSettings"
                class="rce-font-medium rce-underline-offset-4 rce-py-2 rce-shrink-0 rce-text-sky-500 hover:rce-text-sky-600 rce-underline">
                Try Again
            </button>
        </AlertDescription>
    </Alert>

    <!-- Content -->
    <template v-else>
        <!-- Get Started Section (only shown when cache is disabled) -->
        <GetStarted v-if="!cacheEnabled" />

        <template v-else>
            <!-- Multisite Notice for subdirectory setup on main site -->
            <MultisiteNotice v-if="showMultisiteNotice" />

            <DashboardStats :cache-enabled="cacheEnabled" />

            <DashboardCacheConfiguration :cache-enabled="cacheEnabled" />
        </template>
    </template>
</template>

<script setup>
import { ref, computed, provide, onMounted } from 'vue'
import { cacheApi } from '@/api/cache'
import { usePagePreloader } from '@/utils/pagePreloader'
import { useMinimumLoading } from '@/utils/useMinimumLoading'
import { detectServerType } from '@/utils/serverDetect'
import { TriangleAlert } from 'lucide-vue-next'
import DashboardStats from '@/components/DashboardStats.vue'
import GetStarted from '@/components/GetStarted.vue'
import DashboardCacheConfiguration from '@/components/DashboardCacheConfiguration.vue'
import DashboardSkeleton from '@/components/DashboardSkeleton.vue'
import MultisiteNotice from '@/components/MultisiteNotice.vue'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'

const pagePreloader = usePagePreloader()

const cacheEnabled = ref(false)
const pageCacheType = ref('php')
const isTogglingCache = ref(false)
const rawIsLoading = ref(true)
const isLoading = useMinimumLoading(rawIsLoading)
const error = ref(null)
const serverType = ref('')
const multisite = ref({
    is_multisite: false,
    type: 'single',
    is_main_site: true,
})

// Store full cache settings for child components
const cacheSettings = ref({})

// Get initial cache settings
const getCacheSettings = async () => {
    try {
        rawIsLoading.value = true
        error.value = null

        const settings = await cacheApi.getSettings()
        cacheSettings.value = settings
        const newCacheEnabled = Boolean(settings.enable)
        cacheEnabled.value = newCacheEnabled
        pageCacheType.value = settings.page_cache_type || 'php'

        // Store multisite information
        if (settings.multisite) {
            multisite.value = settings.multisite
        }

        // Store server type
        if (settings.server_type) {
            serverType.value = settings.server_type
        } else {
            // Silent background detect. Fills the value when it lands.
            detectServerType().then((info) => {
                if (info?.server_type) {
                    serverType.value = info.server_type
                }
            })
        }

        return settings
    } catch (err) {
        console.error('Settings fetch error:', err)
        error.value = err.message || 'Failed to load cache settings'
    } finally {
        rawIsLoading.value = false
    }
}

// Save cache settings
const saveSettings = async ({ enable = cacheEnabled.value, loadingState = null }) => {
    if (loadingState?.value) return null
    if (loadingState) loadingState.value = true

    try {
        const settings = await cacheApi.saveSettings({ enable })
        const newCacheEnabled = Boolean(settings.enable)
        cacheEnabled.value = newCacheEnabled
        return settings
    } catch (error) {
        console.error('Save settings error:', error)
        throw error
    } finally {
        if (loadingState) loadingState.value = false
    }
}

// Toggle cache state
const toggleCache = async () => {
    try {
        const newSettings = {
            enable: !cacheEnabled.value,
            loadingState: isTogglingCache,
        }

        await saveSettings(newSettings)
    } catch (error) {
        console.error('Failed to toggle cache:', error)
    }
}

// Check if multisite notice should be shown
const showMultisiteNotice = computed(() => {
    const isNginx = serverType.value === 'nginx' || serverType.value === 'nginx-rc'
    return (
        multisite.value.is_multisite &&
        multisite.value.type === 'subdirectory' &&
        multisite.value.is_main_site &&
        isNginx
    )
})

onMounted(async () => {
    if (pagePreloader?.trackPreload) {
        await pagePreloader.trackPreload(() => getCacheSettings())
        pagePreloader.requestPreloadComplete()
    } else {
        await getCacheSettings()
    }
})

provide('cacheState', {
    isTogglingCache,
    toggleCache,
    pageCacheType,
    settings: cacheSettings,
})
</script>

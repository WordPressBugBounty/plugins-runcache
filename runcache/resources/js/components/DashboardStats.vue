<template>
    <div class="rce-grid rce-grids-cols-1 md:rce-grid-cols-12 rce-gap-4 rce-mb-7">
        <!-- Master Switch Card -->
        <Card class="rce-col-span-1 md:rce-col-span-3 rce-p-0">
            <CardContent class="rce-p-0 rce-pb-0 rce-px-0">
                <div class="rce-flex rce-items-center rce-space-x-4 rce-py-5 rce-px-7">
                    <div class="rce-flex rce-flex-col rce-gap-2 rce-w-full">
                        <p class="rce-text-sm rce-text-gray-500 rce-gap-2 rce-flex">
                            <span>Cache Status:</span>
                            <span class="rce-text-gray-900">
                                {{ cacheEnabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </p>
                        <h4 class="rce-text-lg rce-font-semibold rce-text-gray-900">
                            {{ cacheTypeLabel }}
                        </h4>
                    </div>
                    <Switch
                        class="rce-shrink-0"
                        :model-value="cacheEnabled"
                        :disabled="cacheState.isTogglingCache.value"
                        @update:model-value="cacheState.toggleCache" />
                </div>
            </CardContent>
        </Card>

        <!-- Stats Card -->
        <Card class="rce-col-span-1 md:rce-col-span-9">
            <CardContent class="rce-p-0 rce-pb-0 rce-px-0">
                <div
                    class="rce-grid rce-grid-cols-1 md:rce-grid-cols-3 rce-divide-y md:rce-divide-y-0 md:rce-divide-x">
                    <!-- Pages Cached -->
                    <div class="rce-flex rce-items-center rce-justify-between rce-py-5 rce-px-7">
                        <div class="rce-flex rce-flex-col rce-gap-2">
                            <p class="rce-text-sm rce-text-gray-500">Pages Cached</p>
                            <h4 class="rce-text-xl rce-font-semibold rce-text-gray-900">
                                {{ stats.cached_pages }}
                            </h4>
                        </div>
                        <div
                            class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-primary-300 rce-ring-1 rce-ring-offset-3 rce-ring-primary-200 rce-bg-gradient-to-b rce-from-white rce-to-primary-50/50">
                            <GalleryHorizontalEnd
                                :stroke-width="1.2"
                                :size="20"
                                class="rce-text-primary-700" />
                        </div>
                    </div>

                    <!-- Cache Size -->
                    <div class="rce-flex rce-items-center rce-justify-between rce-py-5 rce-px-7">
                        <div class="rce-flex rce-flex-col rce-gap-2">
                            <p class="rce-text-sm rce-text-gray-500">Cache Size</p>
                            <h4 class="rce-text-xl rce-font-semibold rce-text-gray-900">
                                {{ stats.cache_size }}
                            </h4>
                        </div>
                        <div
                            class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-primary-300 rce-ring-1 rce-ring-offset-3 rce-ring-primary-200 rce-bg-gradient-to-b rce-from-white rce-to-primary-50/50">
                            <Weight :stroke-width="1.2" :size="20" class="rce-text-primary-700" />
                        </div>
                    </div>

                    <!-- Compression Ratio -->
                    <div class="rce-flex rce-items-center rce-justify-between rce-py-5 rce-px-7">
                        <div class="rce-flex rce-flex-col rce-gap-2">
                            <p class="rce-text-sm rce-text-gray-500">Compression Ratio</p>
                            <h4 class="rce-text-xl rce-font-semibold rce-text-gray-900">
                                {{ stats.compression_ratio }}
                            </h4>
                        </div>
                        <div
                            class="rce-flex rce-items-center rce-justify-center rce-rounded-lg rce-size-12 rce-border rce-border-primary-300 rce-ring-1 rce-ring-offset-3 rce-ring-primary-200 rce-bg-gradient-to-b rce-from-white rce-to-primary-50/50">
                            <Percent :stroke-width="1.2" :size="20" class="rce-text-primary-700" />
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

<script setup>
import { ref, onMounted, inject, computed } from 'vue'
import { GalleryHorizontalEnd, Weight, Percent } from 'lucide-vue-next'
import { Card, CardContent } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { cacheApi } from '@/api/cache'
import { usePagePreloader } from '@/utils/pagePreloader'
import { PAGE_CACHE_CONFIG } from '@/utils/IntegrationConfig'

const props = defineProps({
    cacheEnabled: {
        type: Boolean,
        required: true,
    },
})

// Inject cache state from Dashboard
const cacheState = inject('cacheState')
const pagePreloader = usePagePreloader()

const stats = ref({
    cached_pages: 0,
    cache_size: '0 B',
    compression_ratio: '0%',
})

// Use shared pageCacheType from parent
const pageCacheType = cacheState?.pageCacheType || ref('')

const cacheTypeLabel = computed(() => {
    return PAGE_CACHE_CONFIG[pageCacheType.value]?.label || pageCacheType.value
})

const loadStats = async () => {
    try {
        stats.value = await cacheApi.getStats()
    } catch (error) {
        console.error('Failed to load stats:', error)
        // Keep default values on error
    }
}

// Load stats when component mounts
onMounted(() => {
    if (pagePreloader?.trackPreload) {
        pagePreloader.trackPreload(() => loadStats())
    } else {
        loadStats()
    }
})
</script>

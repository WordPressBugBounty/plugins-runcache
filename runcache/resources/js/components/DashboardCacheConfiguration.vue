<template>
    <div class="rce-w-full">
        <!-- Tab Navigation -->
        <div class="rce-border rce-rounded-t-xl rce-overflow-hidden">
            <nav class="rce-flex rce-w-full rce-divide-x rce-divide-gray-200" aria-label="Tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="setActiveTab(tab.id)"
                    :class="[
                        'tab-button rce-group rce-flex-1 rce-h-28 md:rce-h-20 rce-px-5 md:rce-px-4 rce-flex rce-flex-col md:rce-flex-row rce-items-center rce-justify-center rce-gap-3 rce-font-medium rce-text-sm rce-transition-all rce-relative',
                        activeTab === tab.id
                            ? 'active rce-text-primary-700 rce-bg-white rce-shadow-xl'
                            : 'rce-text-gray-700 hover:rce-text-primary-700 hover:rce-bg-white',
                    ]"
                    :aria-current="activeTab === tab.id ? 'page' : undefined">
                    <component
                        :is="tab.icon"
                        :stroke-width="1.25"
                        :class="[
                            'rce-size-5 md:rce-size-6',
                            activeTab === tab.id
                                ? 'rce-text-primary-600'
                                : 'rce-text-gray-500 group-hover:rce-text-primary-600',
                        ]" />
                    <span>{{ tab.label }}</span>
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        <div>
            <FullPageCacheSection v-if="activeTab === 'full-page'" class="rce-pt-2" />

            <CdnEdgeCacheSection v-if="activeTab === 'edge-cdn'" class="rce-pt-2" />

            <AssetCdnSection v-if="activeTab === 'asset-cdn'" class="rce-pt-2" />

            <ObjectCacheSection v-if="activeTab === 'object-cache'" class="rce-pt-2" />
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Globe, Cloud, Image, Database } from 'lucide-vue-next'
import FullPageCacheSection from '@/components/FullPageCacheSection.vue'
import CdnEdgeCacheSection from '@/components/CdnEdgeCacheSection.vue'
import AssetCdnSection from '@/components/AssetCdnSection.vue'
import ObjectCacheSection from '@/components/ObjectCacheSection.vue'

// Tab configuration
const tabs = [
    { id: 'full-page', label: 'Full Page Cache', icon: Globe },
    { id: 'edge-cdn', label: 'CDN Edge Cache', icon: Cloud },
    { id: 'asset-cdn', label: 'Assets CDN', icon: Image },
    { id: 'object-cache', label: 'Object Cache', icon: Database },
]

// Active tab state with localStorage persistence
const STORAGE_KEY = 'runcache_dashboard_active_tab'
const activeTab = ref('full-page') // Default to first tab

// Set active tab and persist to localStorage
const setActiveTab = (tabId) => {
    activeTab.value = tabId
    localStorage.setItem(STORAGE_KEY, tabId)
}

// Load active tab from localStorage on mount
onMounted(() => {
    const savedTab = localStorage.getItem(STORAGE_KEY)
    if (savedTab && tabs.some((tab) => tab.id === savedTab)) {
        activeTab.value = savedTab
    }
})
</script>

<style scoped>
/* Active tab bottom border with margins */
.tab-button.active::before {
    content: '';
    @apply rce-absolute rce-top-0 rce-inset-x-0 rce-h-0.5 rce-bg-primary-700 rce-transition-all;
}
</style>

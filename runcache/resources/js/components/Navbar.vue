<template>
    <header
        class="rce-bg-white rce-border-b rce-border-b-gray-200 rce-border-t-4 rce-border-t-primary-700 rce-pt-4 md:rce-pt-8">
        <component
            :is="activeNavbarComponent"
            :navigation="navigation"
            :cached-pages="cachedPages"
            :is-preloading="isPreloading"
            :is-purging="isPurging"
            :preload-message="preloadMessage"
            @preload="preloadCache"
            @purge="purgeCache" />
    </header>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import NavbarDesktop from './NavbarDesktop.vue'
import NavbarMobile from './NavbarMobile.vue'
import { useNavbar } from '@/composables/useNavbar'

const {
    navigation,
    cachedPages,
    isPreloading,
    isPurging,
    preloadMessage,
    loadStats,
    preloadCache,
    purgeCache,
    checkPreloadStatus,
    cleanup,
} = useNavbar()

const isDesktop = ref(
    typeof window !== 'undefined' ? window.matchMedia('(min-width: 768px)').matches : false
)
let mediaQuery = null

const activeNavbarComponent = computed(() => {
    return isDesktop.value ? NavbarDesktop : NavbarMobile
})

const updateViewportState = () => {
    if (!mediaQuery) {
        return
    }

    isDesktop.value = mediaQuery.matches
}

// Load stats and check preload status when component mounts
onMounted(() => {
    loadStats()
    checkPreloadStatus()
    mediaQuery = window.matchMedia('(min-width: 768px)')
    updateViewportState()
    mediaQuery.addEventListener('change', updateViewportState)
})

// Clean up on unmount
onUnmounted(() => {
    cleanup()
    mediaQuery?.removeEventListener('change', updateViewportState)
})
</script>

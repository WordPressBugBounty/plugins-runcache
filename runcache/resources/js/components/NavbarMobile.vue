<template>
    <div class="md:rce-hidden">
        <Drawer v-model:open="isMenuOpen" :should-scale-background="false">
            <div class="rce-px-4 rce-pb-4">
                <div class="rce-flex rce-justify-between rce-items-center">
                    <AppLogo width="100" height="28" class="rce-flex-shrink-0" />

                    <div class="rce-flex rce-items-center rce-space-x-4">
                        <DrawerTrigger as-child>
                            <button
                                class="rce-p-0 rce-shrink-0 [&_svg]:rce-size-6"
                                :aria-label="isMenuOpen ? 'Close menu' : 'Open menu'"
                                :aria-expanded="isMenuOpen"
                                aria-controls="mobile-navigation-drawer"
                                ref="menuButtonRef">
                                <Menu :stroke-width="1.2" class="rce-text-gray-600" />
                            </button>
                        </DrawerTrigger>
                    </div>
                </div>
            </div>

            <DrawerContent id="mobile-navigation-drawer" class="rce-z-[1000] rce-mx-4 rce-mb-4">
                <div class="rce-flex-1 rce-overflow-y-auto rce-pb-4">
                    <section class="rce-px-4 rce-pt-1">
                        <h3 class="rce-text-sm rce-font-medium rce-text-gray-500 rce-mb-3 rce-pl-1">
                            Menu
                        </h3>
                        <nav role="navigation" aria-label="Mobile navigation">
                            <RouterLink
                                v-for="item in navigation"
                                :key="item.name"
                                :to="item.to"
                                @click="closeMenu"
                                class="rce-flex rce-items-center rce-px-3 rce-py-3 rce-text-sm rce-font-medium rce-text-gray-700 hover:rce-text-primary-700 hover:rce-bg-gray-50 rce-transition-colors rce-duration-150 rce-rounded-lg"
                                :class="{
                                    'rce-text-primary-700 rce-bg-primary-50':
                                        $route.path === item.to,
                                }"
                                :aria-current="$route.path === item.to ? 'page' : undefined">
                                <component
                                    :is="item.icon"
                                    :size="20"
                                    :stroke-width="1.5"
                                    class="rce-mr-3 rce-flex-shrink-0" />
                                <span>{{ item.name }}</span>
                            </RouterLink>
                        </nav>
                    </section>

                    <section class="rce-pt-4 rce-px-4">
                        <h3 class="rce-text-sm rce-font-medium rce-text-gray-500 rce-mb-3 rce-pl-1">
                            Actions
                        </h3>
                        <div>
                            <button
                                type="button"
                                @click.prevent="handlePreload"
                                :disabled="isPreloading"
                                class="rce-flex rce-items-center rce-w-full rce-px-3 rce-py-3 rce-text-sm rce-font-medium rce-text-gray-700 hover:rce-text-primary-700 hover:rce-bg-gray-50 rce-transition-colors rce-duration-150 rce-rounded-lg disabled:rce-opacity-60 disabled:rce-cursor-not-allowed">
                                <LoaderCircle
                                    v-if="isPreloading"
                                    :size="20"
                                    :stroke-width="1.5"
                                    class="rce-animate-spin rce-mr-3 rce-flex-shrink-0" />
                                <LoaderPinwheel
                                    v-else
                                    :size="20"
                                    :stroke-width="1.5"
                                    class="rce-mr-3 rce-flex-shrink-0" />
                                {{
                                    isPreloading
                                        ? preloadMessage || 'Preloading...'
                                        : 'Preload Cache'
                                }}
                            </button>

                            <button
                                type="button"
                                @click.prevent="handlePurge"
                                :disabled="isPurging"
                                class="rce-flex rce-items-center rce-w-full rce-px-3 rce-py-3 rce-text-sm rce-font-medium rce-text-gray-700 hover:rce-text-primary-700 hover:rce-bg-gray-50 rce-transition-colors rce-duration-150 rce-rounded-lg disabled:rce-opacity-60 disabled:rce-cursor-not-allowed">
                                <LoaderCircle
                                    v-if="isPurging"
                                    :size="20"
                                    :stroke-width="1.5"
                                    class="rce-animate-spin rce-mr-3 rce-flex-shrink-0" />
                                <SquareBottomDashedScissors
                                    v-else
                                    :size="20"
                                    :stroke-width="1.5"
                                    class="rce-mr-3 rce-flex-shrink-0" />
                                {{ isPurging ? 'Purging...' : 'Purge Cache' }}
                            </button>
                        </div>
                    </section>
                </div>
            </DrawerContent>
        </Drawer>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { Menu, X, LoaderCircle, LoaderPinwheel, SquareBottomDashedScissors } from 'lucide-vue-next'
import { Drawer, DrawerContent, DrawerTrigger } from '@/components/ui/drawer'
import AppLogo from './AppLogo.vue'

defineProps({
    navigation: {
        type: Array,
        required: true,
    },
    cachedPages: {
        type: Number,
        default: 0,
    },
    isPreloading: {
        type: Boolean,
        default: false,
    },
    isPurging: {
        type: Boolean,
        default: false,
    },
    preloadMessage: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['preload', 'purge', 'menu-closed'])

const isMenuOpen = ref(false)
const menuButtonRef = ref(null)

const closeMenu = () => {
    isMenuOpen.value = false
}

watch(isMenuOpen, (isOpen, wasOpen) => {
    if (wasOpen && !isOpen) {
        emit('menu-closed')
        if (menuButtonRef.value) {
            menuButtonRef.value.focus()
        }
    }
})

const handlePreload = () => {
    emit('preload')
}

const handlePurge = () => {
    emit('purge')
}
</script>

<style>
[data-vaul-drawer]:not([data-vaul-custom-container='true'])::after {
    content: none !important;
}
</style>

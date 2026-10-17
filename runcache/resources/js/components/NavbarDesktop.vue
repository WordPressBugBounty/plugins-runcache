<template>
    <div class="rce-hidden md:rce-block">
        <!-- Header with version -->
        <div class="rce-max-w-7xl rce-mx-auto rce-px-8 rce-pb-4">
            <div class="rce-flex rce-justify-between rce-items-center">
                <AppLogo />

                <div class="rce-flex rce-items-center rce-space-x-2">
                    <Button
                        variant="primary"
                        size="sm"
                        @click="$emit('preload')"
                        :disabled="isPreloading">
                        <LoaderCircle
                            v-if="isPreloading"
                            :size="20"
                            :stroke-width="1.5"
                            class="rce-animate-spin" />
                        {{ isPreloading ? preloadMessage || 'Preloading...' : 'Preload cache' }}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        class="!rce-rounded-full"
                        @click="$emit('purge')"
                        :disabled="isPurging">
                        <LoaderCircle
                            v-if="isPurging"
                            :size="20"
                            :stroke-width="1.5"
                            class="rce-animate-spin" />
                        {{ isPurging ? 'Purging...' : 'Purge cache' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Main navigation -->
        <nav>
            <div class="rce-max-w-7xl rce-mx-auto rce-px-8">
                <div class="rce-flex rce-space-x-8">
                    <RouterLink
                        v-for="item in navigation"
                        :key="item.name"
                        :to="item.to"
                        class="rce-py-7 rce-text-sm rce-font-normal rce-transition-colors rce-text-gray-900 hover:rce-text-primary-700 rce-relative rce-group rce-select-none rce-outline-none focus:rce-outline-none focus-visible:rce-outline-none focus:rce-ring-0 [&:not(:focus-visible)]:focus:ring-0 [-webkit-tap-highlight-color:rce-transparent]"
                        active-class="rce-text-primary-700 !hover:rce-text-primary-600"
                        exact-active-class="rce-text-primary-700 !hover:rce-text-primary-600">
                        <div class="rce-flex rce-items-center rce-space-x-2">
                            <component
                                :is="item.icon"
                                :size="20"
                                :stroke-width="1.5"
                                :class="[
                                    'rce-transition-colors group-hover:rce-text-primary-700',
                                    $route.path === item.to
                                        ? 'rce-text-primary-700'
                                        : 'rce-text-gray-500',
                                ]" />
                            <span>{{ item.name }}</span>
                        </div>
                    </RouterLink>
                </div>
            </div>
        </nav>
    </div>
</template>

<script setup>
import { Button } from '@/components/ui/button'
import { LoaderCircle } from 'lucide-vue-next'
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

defineEmits(['preload', 'purge'])
</script>

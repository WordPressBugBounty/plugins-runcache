<template>
    <Teleport to="body">
        <Transition
            enter-active-class="floating-save-enter-active"
            enter-from-class="floating-save-enter-from"
            enter-to-class="floating-save-enter-to"
            leave-active-class="floating-save-leave-active"
            leave-from-class="floating-save-leave-from"
            leave-to-class="floating-save-leave-to">
            <div
                v-if="show"
                class="rce-fixed rce-bottom-4 md:rce-bottom-6 rce-z-[900] rce-pointer-events-auto floating-save-button">
                <div
                    class="rce-bg-gray-900 rce-text-white rce-rounded-xl rce-shadow-lg rce-p-4 md:rce-h-16 md:rce-pl-4 md:rce-pr-3 rce-flex rce-flex-col md:rce-flex-row rce-gap-3 md:rce-gap-0 md:rce-items-center md:rce-justify-between rce-w-full md:rce-w-[480px]">
                    <div class="rce-flex rce-items-center rce-min-w-0">
                        <div
                            class="rce-flex rce-items-center rce-justify-center rce-bg-primary-900 rce-rounded-full rce-p-1 rce-mr-3">
                            <Info :size="20" :stroke-width="1.5" class="rce-text-primary-300" />
                        </div>
                        <span class="rce-text-sm rce-leading-5 md:rce-whitespace-nowrap">
                            Unsaved Changes
                        </span>
                    </div>
                    <div class="rce-flex rce-items-center rce-gap-2 rce-w-full md:rce-w-auto">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="$emit('undo')"
                            :disabled="props.isSaving"
                            class="rce-bg-white/10 rce-px-3 rce-border-white/20 rce-text-gray-200 hover:rce-text-white hover:rce-bg-white/20 hover:rce-border-gray-500 rce-flex-1 md:rce-flex-none">
                            Undo
                        </Button>
                        <Button
                            type="button"
                            variant="white"
                            size="sm"
                            @click="$emit('save')"
                            :disabled="props.isSaving"
                            class="rce-px-3 rce-flex-1 md:rce-flex-none">
                            {{ props.isSaving ? 'Saving...' : 'Save Changes' }}
                        </Button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { Button } from '@/components/ui/button'
import { Info } from 'lucide-vue-next'
import { useFloatingSave } from '@/utils/useFloatingSave'

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    isSaving: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['undo', 'save'])
</script>

<style>
/* Positioning for different screen sizes and WordPress admin states */

/* Mobile first: full width with margins */
@media (max-width: 782px) {
    .floating-save-button {
        left: 1rem !important;
        right: 1rem !important;
        transform: none !important;
    }

    /* Mobile animations */
    .floating-save-enter-from,
    .floating-save-leave-to {
        opacity: 0 !important;
        transform: translateY(calc(100% + 2rem)) !important;
    }

    .floating-save-enter-to,
    .floating-save-leave-from {
        opacity: 1 !important;
        transform: translateY(0) !important;
    }
}

/* Tablet: account for collapsed sidebar */
@media (min-width: 783px) and (max-width: 960px) {
    .floating-save-button {
        left: calc(50% + 18px) !important;
        transform: translateX(-50%) !important;
    }

    /* Tablet animations */
    .floating-save-enter-from,
    .floating-save-leave-to {
        opacity: 0 !important;
        transform: translateX(-50%) translateY(calc(100% + 2rem)) !important;
    }

    .floating-save-enter-to,
    .floating-save-leave-from {
        opacity: 1 !important;
        transform: translateX(-50%) translateY(0) !important;
    }
}

/* Desktop: account for full sidebar */
@media (min-width: 961px) {
    .floating-save-button {
        left: calc(50% + 80px) !important;
        transform: translateX(-50%) !important;
    }

    /* Desktop animations */
    .floating-save-enter-from,
    .floating-save-leave-to {
        opacity: 0 !important;
        transform: translateX(-50%) translateY(calc(100% + 2rem)) !important;
    }

    .floating-save-enter-to,
    .floating-save-leave-from {
        opacity: 1 !important;
        transform: translateX(-50%) translateY(0) !important;
    }
}

/* WordPress admin menu folded state */
@media (min-width: 961px) {
    body.folded .floating-save-button {
        left: calc(50% + 18px) !important;
    }

    body.folded .floating-save-enter-from,
    body.folded .floating-save-leave-to {
        transform: translateX(-50%) translateY(calc(100% + 2rem)) !important;
    }

    body.folded .floating-save-enter-to,
    body.folded .floating-save-leave-from {
        transform: translateX(-50%) translateY(0) !important;
    }
}

/* Transition timing */
.floating-save-enter-active {
    transition:
        opacity 150ms ease-out,
        transform 150ms ease-out !important;
}

.floating-save-leave-active {
    transition:
        opacity 150ms ease-in,
        transform 150ms ease-in !important;
}
</style>

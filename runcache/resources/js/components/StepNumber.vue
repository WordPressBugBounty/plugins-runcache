<template>
    <div
        class="rce-h-7 rce-px-3 rce-flex rce-items-center rce-justify-center rce-rounded-full rce-border rce-text-gray-900 rce-text-xs rce-font-medium rce-shrink-0">
        Step {{ number }}
    </div>
</template>

<script setup>
defineProps({
    number: {
        type: [Number, String],
        required: true,
    },
})
</script>

<template>
    <Alert variant="warning" class="rce-mb-6">
        <TriangleAlert class="rce-size-4" :stroke-width="2" />
        <AlertTitle>RunCache could not add WP_CACHE</AlertTitle>
        <AlertDescription class="rce-space-y-2">
            <p>
                The page cache needs the WP_CACHE constant to work. RunCache could not add it to
                <code
                    class="rce-bg-gray-200/50 rce-px-1 rce-py-0.5 rce-rounded rce-font-mono rce-text-xs">
                    {{ configPath }}
                </code>
                .
            </p>
            <p>Add this line manually:</p>
            <code
                class="rce-block rce-bg-gray-200/50 rce-px-2 rce-py-1.5 rce-rounded-md rce-font-mono rce-text-xs">
                {{ cacheLine }}
            </code>
        </AlertDescription>
    </Alert>
</template>

<script setup>
import { computed } from 'vue'
import { TriangleAlert } from 'lucide-vue-next'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'

const props = defineProps({
    wpCache: {
        type: Object,
        required: true,
    },
})

const configPath = computed(() => props.wpCache.config_path || 'the site config file')
const cacheLine = computed(() => props.wpCache.line || "define('WP_CACHE', true);")
</script>

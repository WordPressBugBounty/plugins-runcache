<template>
    <pre
        :class="prismClass"
        class="rce-border rce-rounded-lg rce-bg-white rce-text-sm rce-px-2 rce-py-2 rce-min-h-10 rce-overflow-x-auto"><code 
        ref="codeElement" 
        class="rce-bg-transparent rce-whitespace-pre" 
        v-if="code" 
        v-html="highlightedCode"></code>
        <!-- using slot for the code -->
        <code 
        ref="codeElement"
        class="rce-bg-transparent rce-whitespace-pre" 
        v-else><slot /></code></pre>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
// Import only the core Prism functionality
import Prism from 'prismjs/components/prism-core'
import 'prismjs/themes/prism.css'

// Only import the languages we actually use
import 'prismjs/components/prism-bash'
import 'prismjs/components/prism-nginx'

const props = defineProps({
    code: {
        type: String,
        default: null,
    },
    language: {
        type: String,
        default: 'bash',
    },
})

const codeElement = ref(null)

const prismClass = computed(() => `language-${props.language}`)

const highlightedCode = computed(() => {
    if (!props.code) return ''

    try {
        return Prism.highlight(props.code, Prism.languages[props.language], props.language)
    } catch (error) {
        console.warn('Prism highlighting failed:', error)
        return props.code
    }
})

onMounted(async () => {
    await nextTick()
    if (!props.code && codeElement.value) {
        Prism.highlightElement(codeElement.value)
    }
})

watch(
    () => props.code,
    async () => {
        if (!props.code) {
            await nextTick()
            if (codeElement.value) {
                Prism.highlightElement(codeElement.value)
            }
        }
    }
)
</script>

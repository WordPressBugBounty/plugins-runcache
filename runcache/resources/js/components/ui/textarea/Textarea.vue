<script setup>
import { useVModel, useTextareaAutosize } from "@vueuse/core";
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
  defaultValue: { type: [String, Number], required: false },
  modelValue: { type: [String, Number], required: false },
  autoResize: { type: Boolean, default: false },
  minHeight: { type: [String, Number], default: 60 },
  maxHeight: { type: [String, Number], default: 300 },
});

const emits = defineEmits(["update:modelValue"]);

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
});

const { textarea } = useTextareaAutosize({
  input: modelValue,
  onResize: () => {
    if (textarea.value && props.autoResize) {
      const minH = typeof props.minHeight === 'number' ? `${props.minHeight}px` : props.minHeight;
      const maxH = typeof props.maxHeight === 'number' ? `${props.maxHeight}px` : props.maxHeight;
      
      textarea.value.style.minHeight = minH;
      textarea.value.style.maxHeight = maxH;
      
      // Enable scrolling when content exceeds maxHeight
      const scrollHeight = textarea.value.scrollHeight;
      const maxHeightPx = typeof props.maxHeight === 'number' ? props.maxHeight : parseInt(props.maxHeight);
      
      if (scrollHeight > maxHeightPx) {
        textarea.value.style.overflowY = 'auto';
      } else {
        textarea.value.style.overflowY = 'hidden';
      }
    }
  },
});
</script>

<template>
  <textarea
    ref="textarea"
    v-model="modelValue"
    :class="cn('rce-flex rce-min-h-[60px] rce-w-full rce-rounded-md rce-border rce-border-input rce-bg-transparent rce-px-3 rce-py-2 rce-text-sm placeholder:rce-text-muted-foreground focus-visible:rce-outline-none focus-visible:rce-ring-1 focus-visible:rce-ring-offset-2 focus-visible:rce-ring-primary-300 disabled:rce-cursor-not-allowed disabled:rce-opacity-50',
        props.class,
      )
    "
    :style="autoResize ? { resize: 'none' } : {}"
  />
</template>

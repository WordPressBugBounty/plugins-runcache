<script setup>
import { useVModel } from "@vueuse/core";
import { cn } from "@/utils/utils";

const props = defineProps({
  defaultValue: { type: [String, Number], required: false },
  modelValue: { type: [String, Number], required: false },
  class: { type: null, required: false },
});

const emits = defineEmits(["update:modelValue"]);

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
});
</script>

<template>
  <input
    v-model="modelValue"
    :class="
      cn(
        'rce-flex rce-h-10 rce-w-full rce-rounded-md rce-border rce-border-input rce-bg-transparent rce-px-3 rce-py-1 rce-text-sm rce-transition-colors file:rce-border-0 file:rce-bg-transparent file:rce-text-sm file:rce-font-medium placeholder:rce-text-muted-foreground focus-visible:rce-outline-none focus-visible:rce-ring-1 focus-visible:rce-ring-offset-2 focus-visible:rce-ring-primary-300 disabled:rce-cursor-not-allowed disabled:rce-opacity-50',
        props.class,
      )
    "
  />
</template>

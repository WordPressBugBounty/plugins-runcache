<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div :class="cn('rce-text-sm [&_p]:rce-leading-relaxed rce-text-gray-600 [&_ul]:rce-list-disc [&_ul]:rce-list-inside [&_ul_li::marker]:rce-text-gray-400', props.class)">
    <slot />
  </div>
</template>

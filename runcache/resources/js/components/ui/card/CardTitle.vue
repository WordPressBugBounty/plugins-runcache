<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <h3 :class="cn('rce-font-semibold rce-text-md rce-leading-none rce-tracking-tight', props.class)">
    <slot />
  </h3>
</template>

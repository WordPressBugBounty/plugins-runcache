<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <h5 :class="cn('rce-pl-6 rce-mb-3 rce-font-medium md:rce-leading-none rce-tracking-tight', props.class)">
    <slot />
  </h5>
</template>

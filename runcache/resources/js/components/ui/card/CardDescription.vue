<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <p :class="cn('rce-text-sm rce-text-muted-foreground', props.class)">
    <slot />
  </p>
</template>

<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div
    :class="cn('rce-rounded-xl rce-border rce-bg-card rce-text-card-foreground rce-shadow-xs', props.class)"
  >
    <slot />
  </div>
</template>

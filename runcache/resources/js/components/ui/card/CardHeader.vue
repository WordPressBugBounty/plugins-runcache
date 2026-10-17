<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div :class="cn('rce-flex rce-flex-col rce-gap-y-1.5 rce-p-6', props.class)">
    <slot />
  </div>
</template>

<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div :class="cn('rce-p-6 rce-pt-0', props.class)">
    <slot />
  </div>
</template>

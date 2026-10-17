<script setup>
import { cn } from '@/utils/utils';

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div :class="cn('rce-grid rce-gap-1.5 rce-p-4 rce-text-center sm:rce-text-left', props.class)">
    <slot />
  </div>
</template>

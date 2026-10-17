<script setup>
import { cn } from '@/utils/utils';

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div :class="cn('rce-mt-auto rce-flex rce-flex-col rce-gap-2 rce-p-4', props.class)">
    <slot />
  </div>
</template>

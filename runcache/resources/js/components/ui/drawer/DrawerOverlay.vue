<script setup>
import { reactiveOmit } from '@vueuse/core';
import { DrawerOverlay } from 'vaul-vue';
import { cn } from '@/utils/utils';

const props = defineProps({
  forceMount: { type: Boolean, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});

const delegatedProps = reactiveOmit(props, 'class');
</script>

<template>
  <DrawerOverlay
    v-bind="delegatedProps"
    :class="cn('rce-fixed rce-inset-0 rce-z-50 rce-bg-black/80', props.class)"
  />
</template>

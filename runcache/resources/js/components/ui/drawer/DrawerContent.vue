<script setup>
import { reactiveOmit } from '@vueuse/core';
import { useForwardPropsEmits } from 'reka-ui';
import { DrawerContent, DrawerPortal } from 'vaul-vue';
import { cn } from '@/utils/utils';
import DrawerOverlay from './DrawerOverlay.vue';

const props = defineProps({
  forceMount: { type: Boolean, required: false },
  disableOutsidePointerEvents: { type: Boolean, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});
const emits = defineEmits([
  'escapeKeyDown',
  'pointerDownOutside',
  'focusOutside',
  'interactOutside',
  'openAutoFocus',
  'closeAutoFocus',
]);

const delegatedProps = reactiveOmit(props, 'class');
const forwardedProps = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <DrawerPortal>
    <DrawerOverlay />
    <DrawerContent
      v-bind="forwardedProps"
      :class="
        cn(
          'rce-fixed rce-inset-x-0 rce-bottom-0 rce-z-50 rce-mt-24 rce-flex rce-h-auto rce-flex-col rce-rounded-xl rce-border rce-bg-background',
          props.class,
        )
      "
    >
      <div class="rce-mx-auto rce-mt-4 rce-h-1.5 rce-w-[100px] rce-rounded-full rce-bg-muted" />
      <slot />
    </DrawerContent>
  </DrawerPortal>
</template>

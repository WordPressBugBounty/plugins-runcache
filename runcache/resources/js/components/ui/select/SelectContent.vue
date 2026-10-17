<script setup>
import { reactiveOmit } from "@vueuse/core";
import {
  SelectContent,
  SelectPortal,
  SelectViewport,
  useForwardPropsEmits,
} from "reka-ui";
import { cn } from "@/utils/utils";
import { SelectScrollDownButton, SelectScrollUpButton } from ".";

defineOptions({
  inheritAttrs: false,
});

const props = defineProps({
  forceMount: { type: Boolean, required: false },
  position: { type: String, required: false, default: "popper" },
  bodyLock: { type: Boolean, required: false },
  side: { type: null, required: false },
  sideOffset: { type: Number, required: false },
  sideFlip: { type: Boolean, required: false },
  align: { type: null, required: false },
  alignOffset: { type: Number, required: false },
  alignFlip: { type: Boolean, required: false },
  avoidCollisions: { type: Boolean, required: false },
  collisionBoundary: { type: null, required: false },
  collisionPadding: { type: [Number, Object], required: false },
  arrowPadding: { type: Number, required: false },
  sticky: { type: String, required: false },
  hideWhenDetached: { type: Boolean, required: false },
  positionStrategy: { type: String, required: false },
  updatePositionStrategy: { type: String, required: false },
  disableUpdateOnLayoutShift: { type: Boolean, required: false },
  prioritizePosition: { type: Boolean, required: false },
  reference: { type: null, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});
const emits = defineEmits([
  "closeAutoFocus",
  "escapeKeyDown",
  "pointerDownOutside",
]);

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <SelectPortal>
    <SelectContent
      v-bind="{ ...forwarded, ...$attrs }"
      :class="
        cn(
          'rce-relative rce-z-50 rce-max-h-96 rce-min-w-32 rce-overflow-hidden rce-rounded-md rce-border rce-bg-popover rce-text-popover-foreground rce-shadow-sm data-[state=open]:rce-animate-in data-[state=closed]:rce-animate-out data-[state=closed]:rce-fade-out-0 data-[state=open]:rce-fade-in-0 data-[state=closed]:rce-zoom-out-95 data-[state=open]:rce-zoom-in-95 data-[side=bottom]:rce-slide-in-from-top-2 data-[side=left]:rce-slide-in-from-right-2 data-[side=right]:rce-slide-in-from-left-2 data-[side=top]:rce-slide-in-from-bottom-2',
          position === 'rce-popper' &&
            'data-[side=bottom]:rce-translate-y-1 data-[side=left]:rce--translate-x-1 data-[side=right]:rce-translate-x-1 data-[side=top]:rce--translate-y-1',
          props.class,
        )
      "
    >
      <SelectScrollUpButton />
      <SelectViewport
        :class="
          cn(
            'rce-p-1',
            position === 'rce-popper' &&
              'rce-h-[--reka-select-trigger-height] rce-w-full rce-min-w-[--reka-select-trigger-width]',
          )
        "
      >
        <slot />
      </SelectViewport>
      <SelectScrollDownButton />
    </SelectContent>
  </SelectPortal>
</template>

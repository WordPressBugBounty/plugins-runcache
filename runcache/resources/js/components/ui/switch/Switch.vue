<script setup>
import { reactiveOmit } from "@vueuse/core";
import { SwitchRoot, SwitchThumb, useForwardPropsEmits } from "reka-ui";
import { cn } from "@/utils/utils";

const props = defineProps({
  defaultValue: { type: Boolean, required: false },
  modelValue: { type: [Boolean, null], required: false },
  disabled: { type: Boolean, required: false },
  id: { type: String, required: false },
  value: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  name: { type: String, required: false },
  required: { type: Boolean, required: false },
  class: { type: null, required: false },
});

const emits = defineEmits(["update:modelValue"]);

const delegatedProps = reactiveOmit(props, "class");

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
  <SwitchRoot
    v-bind="forwarded"
    :class="cn('rce-peer rce-inline-flex rce-h-5 rce-w-9 rce-shrink-0 rce-cursor-pointer rce-items-center rce-rounded-full rce-border-2 rce-border-transparent rce-transition-colors focus-visible:rce-outline-none focus-visible:rce-ring-2 focus-visible:rce-ring-ring focus-visible:rce-ring-offset-2 focus-visible:rce-ring-offset-background disabled:rce-cursor-not-allowed disabled:rce-opacity-50 data-[state=checked]:rce-bg-primary data-[state=unchecked]:rce-bg-input',
        props.class,
      )
    "
  >
    <SwitchThumb
      :class="cn('rce-pointer-events-none rce-flex rce-items-center rce-justify-center rce-h-4 rce-w-4 rce-rounded-full rce-bg-background rce-ring-0 rce-transition-transform data-[state=checked]:rce-translate-x-4 data-[state=unchecked]:rce-translate-x-0 [&[data-state=checked]>span]:rce-bg-primary-400 [&[data-state=unchecked]>span]:rce-bg-gray-400',
        )
      "
    >
      <span class="rce-h-2 rce-w-0.5 rce-rounded-full rce-transition-colors"></span>
      <slot name="thumb" />
    </SwitchThumb>
  </SwitchRoot>
</template>

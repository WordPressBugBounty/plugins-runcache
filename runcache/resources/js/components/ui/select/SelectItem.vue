<script setup>
import { reactiveOmit } from "@vueuse/core";
import { Check } from "lucide-vue-next";
import {
  SelectItem,
  SelectItemIndicator,
  SelectItemText,
  useForwardProps,
} from "reka-ui";
import { cn } from "@/utils/utils";

const props = defineProps({
  value: { type: null, required: true },
  disabled: { type: Boolean, required: false },
  textValue: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});

const delegatedProps = reactiveOmit(props, "class");

const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <SelectItem
    v-bind="forwardedProps"
    :class="
      cn(
        'rce-relative rce-flex rce-w-full rce-cursor-default rce-select-none rce-items-center rce-rounded-sm rce-py-1.5 rce-pl-2 rce-pr-8 rce-text-sm rce-outline-none focus:rce-bg-accent focus:rce-text-accent-foreground data-[disabled]:rce-pointer-events-none data-[disabled]:rce-opacity-50',
        props.class,
      )
    "
  >
    <span class="rce-absolute rce-right-2 rce-flex rce-h-3.5 rce-w-3.5 rce-items-center rce-justify-center">
      <SelectItemIndicator>
        <Check class="rce-h-4 rce-w-4" />
      </SelectItemIndicator>
    </span>

    <SelectItemText>
      <slot />
    </SelectItemText>
  </SelectItem>
</template>

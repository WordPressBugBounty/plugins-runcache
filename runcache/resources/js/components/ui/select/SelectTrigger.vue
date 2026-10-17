<script setup>
import { reactiveOmit } from "@vueuse/core";
import { ChevronDown } from "lucide-vue-next";
import { SelectIcon, SelectTrigger, useForwardProps } from "reka-ui";
import { cn } from "@/utils/utils";

const props = defineProps({
  disabled: { type: Boolean, required: false },
  reference: { type: null, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});

const delegatedProps = reactiveOmit(props, "class");

const forwardedProps = useForwardProps(delegatedProps);
</script>

<template>
  <SelectTrigger
    v-bind="forwardedProps"
    :class="
      cn(
        'rce-flex rce-h-10 rce-w-full rce-items-center rce-justify-between rce-whitespace-nowrap rce-rounded-md rce-border rce-border-input rce-bg-transparent rce-px-3 rce-py-2 rce-text-sm rce-ring-offset-background data-[placeholder]:rce-text-muted-foreground focus:rce-outline-none focus:rce-ring-1 focus:rce-ring-offset-2 focus:rce-ring-primary-300 disabled:rce-cursor-not-allowed disabled:rce-opacity-50 [&>span]:rce-truncate rce-text-start',
        props.class,
      )
    "
  >
    <slot />
    <SelectIcon as-child>
      <ChevronDown class="rce-w-4 rce-h-4 rce-opacity-50 rce-shrink-0" />
    </SelectIcon>
  </SelectTrigger>
</template>

<script setup>
import { reactiveOmit } from "@vueuse/core";
import { Label } from "reka-ui";
import { cn } from "@/utils/utils";

const props = defineProps({
  for: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});

const delegatedProps = reactiveOmit(props, "class");
</script>

<template>
  <Label
    v-bind="delegatedProps"
    :class="cn('rce-text-sm rce-font-medium peer-disabled:rce-cursor-not-allowed peer-disabled:rce-opacity-70',
        props.class,
      )
    "
  >
    <slot />
  </Label>
</template>

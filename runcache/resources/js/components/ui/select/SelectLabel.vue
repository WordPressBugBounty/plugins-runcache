<script setup>
import { SelectLabel } from "reka-ui";
import { cn } from "@/utils/utils";

const props = defineProps({
  for: { type: String, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});
</script>

<template>
  <SelectLabel :class="cn('rce-px-2 rce-py-1.5 rce-text-sm rce-font-semibold', props.class)">
    <slot />
  </SelectLabel>
</template>

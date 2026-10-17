<script setup>
import { reactiveOmit } from "@vueuse/core";
import { SelectSeparator } from "reka-ui";
import { cn } from "@/utils/utils";

const props = defineProps({
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
  class: { type: null, required: false },
});

const delegatedProps = reactiveOmit(props, "class");
</script>

<template>
  <SelectSeparator
    v-bind="delegatedProps"
    :class="cn('rce--mx-1 rce-my-1 rce-h-px rce-bg-muted', props.class)"
  />
</template>

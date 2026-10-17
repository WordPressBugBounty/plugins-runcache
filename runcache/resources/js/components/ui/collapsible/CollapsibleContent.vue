<script setup>
import { CollapsibleContent } from "reka-ui";

const props = defineProps({
  forceMount: { type: Boolean, required: false },
  asChild: { type: Boolean, required: false },
  as: { type: null, required: false },
});
</script>

<template>
  <CollapsibleContent
    v-bind="props"
    class="rce-transition-all data-[state=closed]:rce-animate-collapsible-up data-[state=open]:rce-animate-collapsible-down"
  >
    <slot />
  </CollapsibleContent>
</template>

<script setup>
import { cn } from "@/utils/utils";

const props = defineProps({
  class: { type: null, required: false },
});
</script>

<template>
  <div :class="cn('rce-flex rce-items-center rce-p-6 pt-0', props.class)">
    <slot />
  </div>
</template>

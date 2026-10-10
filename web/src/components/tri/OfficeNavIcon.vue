<script setup lang="ts">
defineProps<{ name: string }>()
</script>

<template>
  <span
    aria-hidden="true"
    class="inline-block shrink-0 bg-current"
    :style="{
      mask: `url(/assets/tri/navigation-20260922/${name}.svg) center / contain no-repeat`,
      WebkitMask: `url(/assets/tri/navigation-20260922/${name}.svg) center / contain no-repeat`,
    }"
  />
</template>

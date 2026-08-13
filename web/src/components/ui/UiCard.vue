<script setup lang="ts">
withDefaults(defineProps<{
  padding?: boolean
}>(), {
  padding: false,
})
</script>

<template>
  <div
    class="bg-surface border border-neutral-200 rounded-lg shadow-xs"
    :class="padding ? 'p-5' : ''"
  >
    <slot />
  </div>
</template>

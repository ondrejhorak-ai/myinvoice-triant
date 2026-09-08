<script setup lang="ts">
defineProps<{
  blocks?: number
}>()
</script>

<template>
  <div class="animate-pulse space-y-4" aria-hidden="true">
    <div class="h-8 w-1/3 max-w-xs rounded-md bg-neutral-200" />
    <div
      v-for="n in (blocks ?? 3)"
      :key="n"
      class="rounded-xl border border-neutral-200 bg-surface p-5 space-y-3"
    >
      <div class="h-4 w-1/4 rounded bg-neutral-200" />
      <div class="h-3 w-full rounded bg-neutral-100" />
      <div class="h-3 w-5/6 rounded bg-neutral-100" />
      <div class="h-3 w-2/3 rounded bg-neutral-100" />
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between pb-5 mb-6 border-b border-neutral-200">
    <div class="min-w-0">
      <h1 class="text-2xl font-semibold text-neutral-900 tracking-tight">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-sm text-neutral-500">{{ subtitle }}</p>
      <slot name="below" />
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 items-center gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>

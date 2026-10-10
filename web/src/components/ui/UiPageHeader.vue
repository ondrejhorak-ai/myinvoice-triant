<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-7">
    <div class="min-w-0">
      <h1 class="text-[28px] leading-9 font-semibold text-neutral-900 ">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-sm text-neutral-600">{{ subtitle }}</p>
      <slot name="below" />
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap shrink-0 items-center gap-2 sm:gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>

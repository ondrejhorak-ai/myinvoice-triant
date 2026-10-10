<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
  /** Podtitul jako název záznamu (14/20, semibold), ne jako popis stránky. */
  subtitleStrong?: boolean
}>()
</script>

<template>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-7">
    <div class="min-w-0">
      <h1 class="text-[28px] leading-9 font-semibold text-neutral-900 ">{{ title }}</h1>
      <p v-if="subtitle" :class="subtitleStrong ? 'mt-2 text-sm font-semibold leading-5 text-neutral-500' : 'mt-1 text-sm text-neutral-600'">{{ subtitle }}</p>
      <slot name="below" />
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap shrink-0 items-center gap-2 sm:gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>

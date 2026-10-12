<script setup lang="ts">
import UiButton from './UiButton.vue'

defineProps<{
  title: string
  message?: string
  cta?: string
  to?: string
  compact?: boolean
}>()
</script>

<template>
  <div :class="compact ? 'px-5 py-6' : 'px-6 py-10'">
    <p class="text-sm text-neutral-400">{{ title }}</p>
    <p v-if="message" class="mt-1.5 max-w-sm text-sm text-neutral-400">{{ message }}</p>
    <UiButton v-if="cta && to" :to="to" size="sm" class="mt-5">{{ cta }}</UiButton>
    <div v-else-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>

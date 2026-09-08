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
  <div class="text-center" :class="compact ? 'px-4 py-8' : 'px-6 py-14'">
    <div
      class="mx-auto rounded-full bg-neutral-100 flex items-center justify-center mb-4"
      :class="compact ? 'w-10 h-10' : 'w-14 h-14'"
    >
      <svg class="text-neutral-400" :class="compact ? 'w-5 h-5' : 'w-7 h-7'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 0a9 9 0 1 1 16.5 0 9 9 0 0 1-16.5 0zm8.25-3v6m0 0 2.25-2.25M12 15l-2.25-2.25"/>
      </svg>
    </div>
    <p class="font-semibold text-neutral-900" :class="compact ? 'text-sm' : 'text-base'">{{ title }}</p>
    <p v-if="message" class="text-sm text-neutral-500 mt-1.5 max-w-sm mx-auto">{{ message }}</p>
    <UiButton v-if="cta && to" :to="to" size="sm" class="mt-5">{{ cta }}</UiButton>
    <div v-else-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>

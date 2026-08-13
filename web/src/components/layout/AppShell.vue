<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import TriLogo from './tri/TriLogo.vue'
defineProps<{ title?: string }>()
const { t } = useI18n()
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">
    <header class="border-b border-neutral-200 bg-surface shadow-xs">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center gap-3">
        <TriLogo class="h-8 w-auto text-neutral-900" />
        <p class="text-sm text-neutral-500 leading-tight">{{ title ?? t('app.subtitle') }}</p>
      </div>
    </header>
    <main class="flex-1 flex items-center justify-center px-4 py-12">
      <slot />
    </main>
  </div>
</template>

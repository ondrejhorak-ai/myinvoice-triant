<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'

/**
 * Přímé odkazy na TRI agendy v horní liště (Kontakty / Zakázky / Faktury TRI).
 * Na užších obrazovkách se schovají — položky zůstávají dostupné v draweru.
 */
const { t } = useI18n()
const route = useRoute()

const links = computed(() => [
  { to: '/tri/contacts', label: t('nav.tri_contacts') },
  { to: '/tri/jobs', label: t('nav.tri_jobs') },
  { to: '/tri/invoices', label: t('nav.tri_invoices') },
])

function isActive(to: string): boolean {
  return route.path === to || route.path.startsWith(to + '/')
}
</script>

<template>
  <nav class="hidden md:flex items-center gap-1" aria-label="TRIANT">
    <RouterLink
      v-for="link in links"
      :key="link.to"
      :to="link.to"
      active-class=""
      exact-active-class=""
      class="inline-flex items-center h-8 px-3 rounded-md text-sm transition-colors leading-tight whitespace-nowrap"
      :class="isActive(link.to)
        ? 'bg-primary-50 text-primary-700 font-medium'
        : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'"
    >{{ link.label }}</RouterLink>
  </nav>
</template>

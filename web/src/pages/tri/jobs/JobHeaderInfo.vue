<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import OfficeRoleTag from '@/components/tri/OfficeRoleTag.vue'
import type { TriJobContact } from '@/api/tri'

const props = defineProps<{
  contacts: TriJobContact[]
  siteStreet?: string | null
  siteCity?: string | null
  siteZip?: string | null
}>()

const { t } = useI18n()

const address = computed(() => [props.siteStreet, props.siteZip, props.siteCity].filter(Boolean).join(', '))
const hasSiteAddress = computed(() =>
  Boolean(props.siteStreet || props.siteCity || props.siteZip),
)

function contactName(c: TriJobContact): string {
  const person = [c.first_name, c.last_name].filter(Boolean).join(' ').trim()
  return person || c.company_name
}

function contactSubtitle(c: TriJobContact): string | null {
  const person = [c.first_name, c.last_name].filter(Boolean).join(' ').trim()
  return person && c.company_name && person !== c.company_name ? c.company_name : null
}
</script>

<template>
  <div class="grid sm:grid-cols-2 gap-6 p-5">
    <div class="min-w-0">
      <h2 class="text-base font-semibold text-neutral-900 mb-4">{{ t('tri.quote.header_contacts') }}</h2>
      <ul v-if="contacts.length" class="space-y-3 text-sm">
        <li v-for="c in contacts" :key="c.client_id" class="flex flex-col gap-1 bg-primary-50 p-4 text-[13px]">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="font-medium text-neutral-900">{{ contactName(c) }}</span>
            <OfficeRoleTag v-for="tag in c.tags" :key="tag.id" :name="tag.name" :slug="tag.slug" />
          </div>
          <span v-if="contactSubtitle(c)" class="text-xs text-neutral-500">{{ contactSubtitle(c) }}</span>
          <a v-if="c.main_email" :href="`mailto:${c.main_email}`" class="text-neutral-600 hover:text-primary-600 break-all">{{ c.main_email }}</a>
          <a v-if="c.phone" :href="`tel:${c.phone}`" class="text-neutral-600 hover:text-primary-600">{{ c.phone }}</a>
        </li>
      </ul>
      <p v-else class="text-sm text-neutral-500">{{ t('tri.quote.header_no_contacts') }}</p>
    </div>

    <div class="min-w-0">
      <h2 class="text-base font-semibold text-neutral-900 mb-4">{{ t('tri.quote.header_site_address') }}</h2>
      <p v-if="hasSiteAddress" class="text-sm text-neutral-700 leading-relaxed">
        <template v-if="siteStreet">{{ siteStreet }}<br></template>
        {{ [siteZip, siteCity].filter(Boolean).join(' ') }}
      </p>
      <p v-else class="text-sm text-neutral-500">{{ t('tri.quote.header_no_address') }}</p>
      <div class="flex flex-wrap gap-4 mt-4 text-[11px] text-primary-700">
        <a v-for="provider in ['Mapy.cz', 'Google Maps']" :key="provider" :href="hasSiteAddress ? (provider === 'Mapy.cz' ? 'https://mapy.cz/zakladni?q=' : 'https://www.google.com/maps/search/?api=1&query=') + encodeURIComponent(address) : undefined" :aria-disabled="!hasSiteAddress" :title="hasSiteAddress ? address : t('tri.quote.header_no_address')" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1">{{ provider }}<img src="/assets/tri/external.svg" class="w-3 h-3" alt="" /></a>
      </div>
    </div>
  </div>
</template>

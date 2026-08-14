<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TriJobContact } from '@/api/tri'

const props = defineProps<{
  contacts: TriJobContact[]
  siteStreet?: string | null
  siteCity?: string | null
  siteZip?: string | null
}>()

const { t } = useI18n()

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
  <div class="grid md:grid-cols-2 md:divide-x divide-neutral-200">
    <div class="p-5">
      <h2 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('tri.quote.header_contacts') }}</h2>
      <ul v-if="contacts.length" class="space-y-3 text-sm">
        <li v-for="c in contacts" :key="c.client_id" class="flex flex-col gap-0.5">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="font-medium text-neutral-900">{{ contactName(c) }}</span>
            <span
              v-for="tag in c.tags"
              :key="tag.id"
              class="text-xs px-1.5 rounded text-white"
              :style="{ backgroundColor: tag.color }"
            >{{ tag.name }}</span>
          </div>
          <span v-if="contactSubtitle(c)" class="text-xs text-neutral-500">{{ contactSubtitle(c) }}</span>
          <a v-if="c.main_email" :href="`mailto:${c.main_email}`" class="text-neutral-600 hover:text-primary-600 break-all">{{ c.main_email }}</a>
          <a v-if="c.phone" :href="`tel:${c.phone}`" class="text-neutral-600 hover:text-primary-600">{{ c.phone }}</a>
        </li>
      </ul>
      <p v-else class="text-sm text-neutral-500">{{ t('tri.quote.header_no_contacts') }}</p>
    </div>

    <div class="p-5">
      <h2 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('tri.quote.header_site_address') }}</h2>
      <p v-if="hasSiteAddress" class="text-sm text-neutral-700 leading-relaxed">
        <template v-if="siteStreet">{{ siteStreet }}<br></template>
        {{ [siteZip, siteCity].filter(Boolean).join(' ') }}
      </p>
      <p v-else class="text-sm text-neutral-500">{{ t('tri.quote.header_no_address') }}</p>
    </div>
  </div>
</template>

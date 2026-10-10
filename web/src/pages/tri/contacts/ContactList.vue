<script setup lang="ts">
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

import { ref, onMounted, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { clientsApi, type Client } from '@/api/clients'
import { triApi, type TriTag } from '@/api/tri'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiSelect from '@/components/ui/UiSelect.vue'
import UiTable from '@/components/ui/UiTable.vue'
import Modal from '@/components/ui/Modal.vue'
import OfficeRoleTag from '@/components/tri/OfficeRoleTag.vue'
import { apiErrorMessage } from '@/api/errors'
import { clientIsIncomplete } from '@/utils/clientCompleteness'

const { t } = useI18n()
const auth = useAuthStore()

const items = ref<Client[]>([])
const error = ref('')
const filtersOpen = ref(false)
const draftArchived = ref<boolean | 'all'>(false)
const draftRole = ref<number | null>(null)
let request = 0
function openFilters() { draftArchived.value = showArchived.value; draftRole.value = triTagFilter.value; filtersOpen.value = true }
function applyFilters() { showArchived.value = draftArchived.value; triTagFilter.value = draftRole.value; filtersOpen.value = false }

onBeforeUnmount(() => { if (searchTimeout) clearTimeout(searchTimeout) })
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const showArchived = ref<boolean | 'all'>(false)
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
const triTags = ref<TriTag[]>([])
const triTagFilter = ref<number | null>(null)
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'archive', active: () => showArchived.value !== 'all', label: () => `${t('office.archive_filter')}: ${t(showArchived.value ? 'office.archived_only' : 'office.not_archived')}`, clear: () => { showArchived.value = 'all' } },
  { id: 'role', active: () => triTagFilter.value !== null, label: () => `${t('tri.tags.label')}: ${triTags.value.find(tag => tag.id === triTagFilter.value)?.name ?? '#' + triTagFilter.value}`, clear: () => { triTagFilter.value = null } },
])
let searchTimeout: ReturnType<typeof setTimeout> | null = null



async function load(reset = true) {
  if (!reset && (loading.value || loadingMore.value)) return
  const seq = ++request
  const next = reset ? 1 : page.value + 1
  error.value = ''
  if (reset) {
    loading.value = true
    items.value = []
    page.value = 1; pages.value = 1; total.value = 0

  } else {
    loadingMore.value = true

  }
  try {
    const r = await clientsApi.list({
      q: search.value,
      archived: showArchived.value,
      sort: sort.value,
      role: 'all',
      tri_tag_id: triTagFilter.value ?? undefined,
      tri_stats: true,
      page: next,
    })
    if (seq !== request) return
    page.value = next
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
  } catch (e) {
    if (seq === request) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (seq !== request) return
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(async () => {
  load(true)
  triTags.value = await triApi.tags.list().catch(() => [])
})
watch([showArchived, sort, triTagFilter], () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

function kindKey(kind?: string | null): string {
  return kind === 'person' || kind === 'private' ? 'tri.contacts.kind_person' : 'tri.contacts.kind_company'
}

function awaitingMyucto(c: Client): boolean {
  if (c.myucto_id) return false
  return !((c.party_kind === 'person' || c.party_kind === 'private') && c.company_client_id)
}

const navigateRow = useRowLink()
function openContact(c: Client, e?: MouseEvent) {
  navigateRow(`/tri/contacts/${c.id}`, e)
}
</script>

<template>
<div>
  <UiPageHeader :title="t('tri.contacts.title')" :subtitle="t('office.contacts_subtitle')"><template #actions><UiButton v-if="auth.canWrite" to="/tri/contacts/new">{{ t('tri.contacts.new') }}</UiButton></template></UiPageHeader>
  <div class="office-filters">
    <UiInput v-model="search" type="search" class="w-full sm:max-w-[520px]" :label="t('common.search')" :placeholder="t('office.contacts_search')" />
    <OfficeFilterButton :count="conditions.length" :expanded="filtersOpen" @click="openFilters" />
    <UiSelect v-model="sort" class="sm:ml-auto w-full sm:w-[230px]" :label="t('common.sort_by')"><option value="name">{{ t('common.sort_name') }}</option><option value="revenue">{{ t('common.sort_revenue') }}</option><option value="last_activity">{{ t('common.sort_last_activity') }}</option></UiSelect>
  </div>
  <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />
  <p class="text-xs text-neutral-500 mb-4">{{ t('office.loaded', {n:items.length,total}) }}</p>
  <div v-if="error" class="office-error" role="alert">{{ error }} <UiButton variant="secondary" @click="load(true)">{{ t('office.retry') }}</UiButton></div>
  <UiCard>
    <TableSkeleton v-if="loading" :rows="7" :cols="6" />
    <EmptyState v-else-if="!items.length && !error" :title="t('tri.contacts.no_data')"><UiButton variant="secondary" @click="clearFilters">{{ t('office.clear_filters') }}</UiButton></EmptyState>
    <UiTable v-else><template #head><tr><th class="px-4 text-left">{{ t('office.contact') }}</th><th class="px-4 text-left">{{ t('office.email_phone') }}</th><th class="px-4 text-left">{{ t('tri.tags.label') }}</th><th class="px-4 text-right">{{ t('tri.jobs.title') }}</th><th class="px-4 text-left">{{ t('common.last_activity') }}</th><th class="px-4 text-right">{{ t('common.revenue') }}</th></tr></template>
      <tr v-for="c in items" :key="c.id" class="cursor-pointer" @click="openContact(c,$event)" @auxclick.prevent="openContact(c,$event)">
        <td class="px-4 py-4 min-w-[200px]"><RouterLink :to="`/tri/contacts/${c.id}`" class="font-semibold text-neutral-900" @click.stop>{{ c.company_name }}</RouterLink><div class="mt-1 flex flex-wrap gap-1"><UiBadge>{{ t(kindKey(c.party_kind)) }}</UiBadge><UiBadge v-if="awaitingMyucto(c)">{{ t('tri.contacts.not_in_myucto') }}</UiBadge><UiBadge v-if="c.archived_at">{{ t('common.archived') }}</UiBadge></div><p v-if="clientIsIncomplete(c)" class="text-warning-600 text-xs mt-1" :title="t('client.incomplete_list_hint')">{{ t('office.incomplete_contact') }}</p></td>
        <td class="px-4 py-4"><a v-if="c.main_email" :href="`mailto:${c.main_email}`" class="block text-primary-700 break-all" @click.stop>{{ c.main_email }}</a><span v-else>—</span><a v-if="c.phone" :href="`tel:${c.phone}`" class="block text-neutral-600 mt-1" @click.stop>{{ c.phone }}</a><span v-else class="block">—</span></td>
        <td class="px-4"><div class="flex flex-wrap gap-1"><OfficeRoleTag v-for="tag in c.tri_tags" :key="tag.id" :name="tag.name" :slug="tag.slug" /><span v-if="!c.tri_tags?.length">—</span></div></td>
        <td class="px-4 text-right text-primary-700">{{ c.tri_jobs_count || '—' }}</td>
        <td class="px-4 text-neutral-600 whitespace-nowrap">{{ c.tri_last_job_date ? formatDate(c.tri_last_job_date) : '—' }}</td>
        <td class="px-4 text-right font-semibold whitespace-nowrap">{{ c.tri_revenue ? formatMoney(c.tri_revenue, 'CZK', 0) : '—' }}</td>
      </tr>
    </UiTable>
  </UiCard>
  <div v-if="page < pages && !loading" class="flex justify-center p-6"><UiButton variant="secondary" :loading="loadingMore" @click="load(false)">{{ t('office.more') }}</UiButton></div>
  <Modal v-if="filtersOpen" :title="t('office.filters')" width-class="max-w-lg" @close="filtersOpen=false"><div class="office-page grid gap-4"><UiSelect v-model="draftRole" :label="t('tri.tags.label')"><option :value="null">{{ t('common.all') }}</option><option v-for="tag in triTags" :key="tag.id" :value="tag.id">{{ tag.name }}</option></UiSelect><UiSelect v-model="draftArchived" :label="t('office.archive_filter')"><option value="all">{{ t('common.all') }}</option><option :value="false">{{ t('office.not_archived') }}</option><option :value="true">{{ t('office.archived_only') }}</option></UiSelect></div><template #footer><UiButton variant="secondary" @click="clearFilters(); filtersOpen=false">{{ t('office.clear_filters') }}</UiButton><UiButton @click="applyFilters">{{ t('office.apply_filters') }}</UiButton></template></Modal>
</div>
</template>

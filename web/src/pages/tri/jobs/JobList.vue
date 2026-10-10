<script setup lang="ts">
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

import { ref, onMounted, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob } from '@/api/tri'
import { useAuthStore } from '@/stores/auth'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import Modal from '@/components/ui/Modal.vue'
import OfficeMenu from '@/components/tri/OfficeMenu.vue'
import {useRouter} from 'vue-router'
import UiTable from '@/components/ui/UiTable.vue'
import { usePagedRows } from '@/composables/tri/usePagedRows'
import { useRowLink } from '@/composables/useRowLink'

const { t } = useI18n()
const auth = useAuthStore()
const router=useRouter()
const navigateRow = useRowLink()

const q = ref('')
const status = ref('')

const statusLabels: Record<string, string> = {
  active: 'tri.jobs.status_active',
  confirmed: 'tri.jobs.status_confirmed',
  rejected: 'tri.jobs.status_rejected',
  completed: 'tri.jobs.status_completed',
}

const archive = ref('active')
const filtersOpen = ref(false)
const draftStatus = ref('')
const draftArchive = ref('active')
function openFilters() { draftStatus.value = status.value; draftArchive.value = archive.value; filtersOpen.value = true }
function applyFilters() { status.value = draftStatus.value; archive.value = draftArchive.value; filtersOpen.value = false }
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'status', active: () => !!status.value, label: () => `${t('tri.jobs.status')}: ${t(statusLabels[status.value] || status.value)}`, clear: () => { status.value = '' } },
  { id: 'archive', active: () => archive.value !== 'all', label: () => `${t('office.archive_filter')}: ${t(archive.value === 'archived' ? 'office.archived_only' : 'office.not_archived')}`, clear: () => { archive.value = 'all' } },
])

function statusBadgeVariant(s: string): 'primary' | 'success' | 'danger' | 'neutral' {
  if (s === 'active') return 'primary'
  if (s === 'confirmed') return 'success'
  if (s === 'rejected') return 'danger'
  return 'neutral'
}

const { items, loading, loadingMore, error, page, pages, total, load } = usePagedRows<TriJob>((page) => triApi.jobs.list({ q: q.value, status: status.value, archive: archive.value, per_page: 25, page }))
let timer: ReturnType<typeof setTimeout>
onBeforeUnmount(() => clearTimeout(timer))

function openJob(job: TriJob, e?: MouseEvent) {
  navigateRow({ name: 'tri-job-detail', params: { id: job.id } }, e)
}

onMounted(() => load())
watch([status, archive], () => load())
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(), 250) })
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.jobs.title')" :subtitle="t('office.jobs_subtitle')">
      <template #actions><UiButton v-if="auth.canWrite" to="/tri/jobs/new">{{ t('tri.jobs.new') }}</UiButton></template>
    </UiPageHeader>
    <div class="office-filters">
      <UiInput v-model="q" type="search" class="w-full sm:max-w-[420px]" :label="t('common.search')" :placeholder="t('office.jobs_search')" />
      <OfficeFilterButton :count="conditions.length" :expanded="filtersOpen" @click="openFilters" />
      <span class="sm:ml-auto text-xs text-neutral-500">{{ t('office.loaded', { n: items.length, total }) }}</span>
    </div>
    <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />
    <div v-if="error" class="office-error" role="alert">{{ error }} <UiButton variant="secondary" @click="load(true)">{{ t('office.retry') }}</UiButton></div>
    <UiCard>
      <TableSkeleton v-if="loading" :rows="8" :cols="5" />
      <EmptyState v-else-if="!items.length && !error" :title="t('tri.jobs.no_jobs')" />
      <UiTable v-else table-class="table-fixed min-w-[950px]">
        <template #head><tr><th class="text-left px-4 w-[98px]">{{ t('tri.jobs.number') }}</th><th class="text-left px-4">{{ t('tri.jobs.title_field') }}</th><th class="text-left px-4">{{ t('tri.jobs.customer') }}</th><th class="text-left px-4 w-[198px]">{{ t('office.author') }}</th><th class="text-left px-4 w-[156px]">{{ t('tri.jobs.status') }}</th><th class="w-14"><span class="sr-only">{{t('office.actions')}}</span></th></tr></template>
        <tr v-for="job in items" :key="job.id" class="cursor-pointer" @click="openJob(job, $event)" @auxclick.prevent="openJob(job, $event)">
          <td class="px-4 text-primary-700"><RouterLink :to="{ name: 'tri-job-detail', params: { id: job.id } }" @click.stop>{{ job.number }}</RouterLink></td>
          <td class="px-4 font-semibold text-neutral-900 min-w-[220px]">{{ job.title }}</td>
          <td class="px-4 text-neutral-600">{{ job.customer_name || '—' }}</td>
          <td class="px-4 text-neutral-600">{{ job.owner_name || '—' }}</td>
          <td class="px-4"><UiBadge :variant="statusBadgeVariant(job.status)">{{ t(statusLabels[job.status] || job.status) }}</UiBadge></td>
          <td @click.stop><OfficeMenu><button @click="router.push(`/tri/jobs/${job.id}`)">{{t('common.detail')}}</button><button v-if="auth.canWrite" @click="router.push(`/tri/jobs/${job.id}/edit`)">{{t('common.edit')}}</button></OfficeMenu></td>
        </tr>
      </UiTable>
      <div v-if="page < pages && !loading" class="p-4 flex justify-center"><UiButton variant="secondary" :loading="loadingMore" @click="load(false)">{{ t('office.more') }}</UiButton></div>
    </UiCard>
    <Modal v-if="filtersOpen" :title="t('office.filters')" @close="filtersOpen=false">
      <div class="office-page grid gap-4">
        <label class="grid gap-1">{{ t('tri.jobs.status') }}<select v-model="draftStatus" class="border px-3 bg-surface"><option value="">{{ t('common.all') }}</option><option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ t(label) }}</option></select></label>
        <label class="grid gap-1">{{ t('office.archive_filter') }}<select v-model="draftArchive" class="border px-3 bg-surface"><option value="all">{{ t('common.all') }}</option><option value="active">{{ t('office.not_archived') }}</option><option value="archived">{{ t('office.archived_only') }}</option></select></label>
      </div>
      <template #footer><UiButton variant="secondary" @click="clearFilters(); filtersOpen=false">{{ t('office.clear_filters') }}</UiButton><UiButton @click="applyFilters">{{ t('office.apply_filters') }}</UiButton></template>
    </Modal>
  </div>
</template>

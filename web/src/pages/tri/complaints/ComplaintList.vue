<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriComplaint, type TriJob } from '@/api/tri'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/composables/useFormat'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiTable from '@/components/ui/UiTable.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useRowLink } from '@/composables/useRowLink'
import ComplaintFormModal from './ComplaintFormModal.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const navigateRow = useRowLink()

const items = ref<TriComplaint[]>([])
const jobs = ref<TriJob[]>([])
const loading = ref(false)
const jobId = ref(typeof route.query.job_id === 'string' ? route.query.job_id : '')
const status = ref(typeof route.query.status === 'string' ? route.query.status : '')
const formOpen = ref(false)

async function loadJobs() {
  const r = await triApi.jobs.list({ per_page: 100 })
  jobs.value = r.data
}

async function load() {
  loading.value = true
  try {
    const params: Record<string, string | number> = {}
    if (jobId.value) params.job_id = Number(jobId.value)
    if (status.value) params.status = status.value
    const r = await triApi.complaints.list(params)
    items.value = r.data
  } finally {
    loading.value = false
  }
}

function openRow(row: TriComplaint, e?: MouseEvent) {
  navigateRow({ name: 'tri-complaint-detail', params: { id: row.id } }, e)
}

function onCreated(row: TriComplaint) {
  formOpen.value = false
  void router.push({ name: 'tri-complaint-detail', params: { id: row.id } })
}

onMounted(async () => {
  await loadJobs()
  await load()
})
watch([jobId, status], () => load())
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.complaints.title')" :subtitle="t('tri.complaints.subtitle')">
      <template #actions>
        <UiButton v-if="auth.canWrite" size="sm" @click="formOpen = true">
          <template #icon>
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
          </template>
          {{ t('tri.complaints.new') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <UiCard>
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap gap-3">
        <select v-model="jobId" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface min-w-[12rem]">
          <option value="">{{ t('tri.complaints.filter_job_all') }}</option>
          <option v-for="j in jobs" :key="j.id" :value="String(j.id)">{{ j.number }} — {{ j.title }}</option>
        </select>
        <select v-model="status" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="">{{ t('tri.complaints.filter_status_all') }}</option>
          <option value="open">{{ t('tri.complaints.status_open') }}</option>
          <option value="closed">{{ t('tri.complaints.status_closed') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="5" />

      <EmptyState v-else-if="items.length === 0" :title="t('tri.complaints.no_data')">
        <UiButton v-if="auth.canWrite" size="sm" @click="formOpen = true">
          {{ t('tri.complaints.create_first') }}
        </UiButton>
      </EmptyState>

      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.complaints.title_label') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.complaints.job') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.complaints.status') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.complaints.created_col') }}</th>
            </tr>
          </template>
          <tr
            v-for="row in items"
            :key="row.id"
            class="cursor-pointer"
            @click="openRow(row, $event)"
            @auxclick.prevent="openRow(row, $event)"
          >
            <td class="px-4 py-3 font-medium text-neutral-900">{{ row.title }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ row.job_number }} — {{ row.job_title }}</td>
            <td class="px-4 py-3">
              <UiBadge :variant="row.status === 'closed' ? 'neutral' : 'warning'">
                {{ t(`tri.complaints.status_${row.status}`) }}
              </UiBadge>
            </td>
            <td class="px-4 py-3 text-neutral-600">{{ formatDate(row.created_at.slice(0, 10)) }}</td>
          </tr>
        </UiTable>
      </div>

      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="row in items"
          :key="`m-${row.id}`"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3"
          @click="openRow(row, $event)"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="font-medium text-sm text-neutral-900 truncate">{{ row.title }}</span>
            <UiBadge :variant="row.status === 'closed' ? 'neutral' : 'warning'">
              {{ t(`tri.complaints.status_${row.status}`) }}
            </UiBadge>
          </div>
          <div class="mt-1 text-xs text-neutral-500">{{ row.job_number }} · {{ formatDate(row.created_at.slice(0, 10)) }}</div>
        </div>
      </div>
    </UiCard>

    <ComplaintFormModal
      v-if="formOpen"
      :job-id="jobId ? Number(jobId) : undefined"
      :jobs="jobs"
      @close="formOpen = false"
      @created="onCreated"
    />
  </div>
</template>

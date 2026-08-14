<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriTraveler, type TriJob } from '@/api/tri'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiTable from '@/components/ui/UiTable.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import { useRowLink } from '@/composables/useRowLink'

const { t } = useI18n()
const navigateRow = useRowLink()

const items = ref<TriTraveler[]>([])
const jobs = ref<TriJob[]>([])
const loading = ref(false)
const jobId = ref('')
const status = ref('')

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
    const r = await triApi.travelers.list(params)
    items.value = r.data
  } finally {
    loading.value = false
  }
}

function openRow(row: TriTraveler, e?: MouseEvent) {
  navigateRow({ name: 'tri-traveler-detail', params: { id: row.id } }, e)
}

onMounted(async () => {
  await loadJobs()
  await load()
})
watch([jobId, status], () => load())
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.travelers.title')" :subtitle="t('tri.travelers.subtitle')" />

    <UiCard>
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap gap-3">
        <select v-model="jobId" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface min-w-[12rem]">
          <option value="">{{ t('tri.travelers.filter_job_all') }}</option>
          <option v-for="j in jobs" :key="j.id" :value="String(j.id)">{{ j.number }} — {{ j.title }}</option>
        </select>
        <select v-model="status" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="">{{ t('tri.travelers.filter_status_all') }}</option>
          <option value="open">{{ t('tri.travelers.status_open') }}</option>
          <option value="done">{{ t('tri.travelers.status_done') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="5" />

      <EmptyState
        v-else-if="items.length === 0"
        :title="t('tri.travelers.no_data')"
      />

      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.number') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.job') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.item') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.travelers.quantity') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.status') }}</th>
            </tr>
          </template>
          <tr
            v-for="row in items"
            :key="row.id"
            class="cursor-pointer"
            @click="openRow(row, $event)"
            @auxclick.prevent="openRow(row, $event)"
          >
            <td class="px-4 py-3 font-mono text-neutral-900">{{ row.number }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ row.job_number }}</td>
            <td class="px-4 py-3">
              <span v-if="row.designation" class="font-mono text-neutral-500 mr-1.5">{{ row.designation }}</span>
              {{ row.title }}
            </td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.quantity }} {{ row.unit }}</td>
            <td class="px-4 py-3">
              <UiBadge :variant="row.status === 'done' ? 'success' : 'primary'">
                {{ t(`tri.travelers.status_${row.status}`) }}
              </UiBadge>
            </td>
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
            <span class="font-mono text-sm">{{ row.number }}</span>
            <UiBadge :variant="row.status === 'done' ? 'success' : 'primary'">
              {{ t(`tri.travelers.status_${row.status}`) }}
            </UiBadge>
          </div>
          <div class="mt-1 text-sm text-neutral-900 truncate">{{ row.title }}</div>
          <div class="text-xs text-neutral-500">{{ row.job_number }} · {{ row.quantity }} {{ row.unit }}</div>
        </div>
      </div>
    </UiCard>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
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
import UiTable from '@/components/ui/UiTable.vue'
import { useRowLink } from '@/composables/useRowLink'

const { t } = useI18n()
const auth = useAuthStore()
const navigateRow = useRowLink()

const items = ref<TriJob[]>([])
const loading = ref(false)
const q = ref('')
const status = ref('')

const statusLabels: Record<string, string> = {
  active: 'tri.jobs.status_active',
  confirmed: 'tri.jobs.status_confirmed',
  rejected: 'tri.jobs.status_rejected',
  completed: 'tri.jobs.status_completed',
}

function statusBadgeVariant(s: string): 'primary' | 'success' | 'danger' | 'neutral' {
  if (s === 'active') return 'primary'
  if (s === 'confirmed') return 'success'
  if (s === 'rejected') return 'danger'
  return 'neutral'
}

async function load() {
  loading.value = true
  try {
    const r = await triApi.jobs.list({
      q: q.value || undefined,
      status: status.value || undefined,
      per_page: 50,
    } as Record<string, string | number>)
    items.value = r.data
  } finally {
    loading.value = false
  }
}

function openJob(job: TriJob, e?: MouseEvent) {
  navigateRow({ name: 'tri-job-detail', params: { id: job.id } }, e)
}

onMounted(() => load())
watch([q, status], () => load())
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.jobs.title')">
      <template #actions>
        <UiButton v-if="auth.canWrite" to="/tri/jobs/new" size="sm">
          <template #icon>
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
          </template>
          {{ t('tri.jobs.new') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <UiCard>
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center gap-3">
        <UiInput
          v-model="q"
          type="search"
          size="sm"
          class="flex-1"
          :placeholder="t('common.search')"
        >
          <template #prefix>
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z"/></svg>
          </template>
        </UiInput>
        <select
          v-model="status"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface shadow-xs outline-none focus-ring"
        >
          <option value="">{{ t('common.all') }}</option>
          <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ t(label) }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="8" :cols="6" />

      <EmptyState
        v-else-if="items.length === 0"
        :title="t('tri.jobs.no_jobs')"
        :cta="auth.canWrite ? t('tri.jobs.new') : undefined"
        to="/tri/jobs/new"
      />

      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.number') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.title_field') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.customer') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.assignees') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.status') }}</th>
              <th class="px-4 py-2.5 w-28"></th>
            </tr>
          </template>
          <tr
            v-for="job in items"
            :key="job.id"
            class="cursor-pointer"
            @click="openJob(job, $event)"
            @auxclick.prevent="openJob(job, $event)"
          >
            <td class="px-4 py-3 font-mono text-sm text-neutral-900">{{ job.number }}</td>
            <td class="px-4 py-3 font-medium text-neutral-900">{{ job.title }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ job.customer_name || '—' }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ job.assignees?.map((a) => a.name).join(', ') || '—' }}</td>
            <td class="px-4 py-3">
              <UiBadge :variant="statusBadgeVariant(job.status)">{{ t(statusLabels[job.status] || job.status) }}</UiBadge>
            </td>
            <td class="px-4 py-3 text-right" @click.stop>
              <UiButton :to="{ name: 'tri-job-detail', params: { id: job.id } }" variant="ghost" size="sm">
                {{ t('common.detail') }}
              </UiButton>
            </td>
          </tr>
        </UiTable>
      </div>

      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="job in items"
          :key="`m-${job.id}`"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3"
          @click="openJob(job, $event)"
          @auxclick.prevent="openJob(job, $event)"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono text-sm font-medium text-neutral-900">{{ job.number }}</div>
            <UiBadge :variant="statusBadgeVariant(job.status)">{{ t(statusLabels[job.status] || job.status) }}</UiBadge>
          </div>
          <div class="mt-1 font-medium text-neutral-900 truncate">{{ job.title }}</div>
          <div class="mt-1 text-xs text-neutral-500 truncate">
            {{ job.customer_name || '—' }}
            <span v-if="job.assignees?.length" class="text-neutral-400"> · {{ job.assignees.map((a) => a.name).join(', ') }}</span>
          </div>
        </div>
      </div>
    </UiCard>
  </div>
</template>

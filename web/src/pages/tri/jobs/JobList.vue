<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob } from '@/api/tri'
import { useAuthStore } from '@/stores/auth'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()

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

onMounted(() => load())
watch([q, status], () => load())
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('tri.jobs.title') }}</h1>
      <RouterLink
        v-if="auth.canWrite"
        to="/tri/jobs/new"
        class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('tri.jobs.new') }}
      </RouterLink>
    </div>

    <div class="flex flex-wrap gap-3 mb-4">
      <input
        v-model="q"
        type="search"
        class="max-w-xs h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        :placeholder="t('common.search')"
      />
      <select
        v-model="status"
        class="max-w-[200px] h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
      >
        <option value="">{{ t('common.all') }}</option>
        <option v-for="(label, key) in statusLabels" :key="key" :value="key">{{ t(label) }}</option>
      </select>
    </div>

    <TableSkeleton v-if="loading" :rows="8" />
    <EmptyState v-else-if="items.length === 0" :title="t('tri.jobs.no_jobs')">
      <RouterLink
        v-if="auth.canWrite"
        to="/tri/jobs/new"
        class="cursor-pointer inline-flex items-center gap-1.5 mt-2 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('tri.jobs.new') }}
      </RouterLink>
    </EmptyState>
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50">
            <tr>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.number') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.title_field') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.customer') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.assignees') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.status') }}</th>
              <th class="text-right text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="job in items"
              :key="job.id"
              class="cursor-pointer hover:bg-neutral-50 transition border-b border-neutral-100"
              @click="router.push({ name: 'tri-job-detail', params: { id: job.id } })"
            >
              <td class="px-3 py-2 font-mono text-sm">{{ job.number }}</td>
              <td class="px-3 py-2">{{ job.title }}</td>
              <td class="px-3 py-2">{{ job.customer_name || '—' }}</td>
              <td class="px-3 py-2">{{ job.assignees?.map((a) => a.name).join(', ') || '—' }}</td>
              <td class="px-3 py-2">
                <span class="text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t(statusLabels[job.status] || job.status) }}</span>
              </td>
              <td class="px-3 py-2 text-right">
                <RouterLink
                  :to="{ name: 'tri-job-detail', params: { id: job.id } }"
                  class="text-primary-600 hover:text-primary-700 text-sm"
                  @click.stop
                >
                  {{ t('tri.jobs.detail') }}
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

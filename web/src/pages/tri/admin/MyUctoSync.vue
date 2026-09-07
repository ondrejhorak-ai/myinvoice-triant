<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type MyUctoSyncStatus } from '@/api/tri'
import { useToast } from '@/composables/useToast'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'

const { t } = useI18n()
const toast = useToast()
const loading = ref(false)
const syncing = ref(false)
const data = ref<MyUctoSyncStatus | null>(null)

async function load() {
  loading.value = true
  try {
    data.value = await triApi.myucto.status()
  } catch {
    toast.error(t('common.error'))
  } finally {
    loading.value = false
  }
}

async function sync(full: boolean) {
  syncing.value = true
  try {
    const res = await triApi.myucto.sync(full)
    toast.success(t('tri.myucto.synced', { n: res.records }))
    await load()
  } catch {
    toast.error(t('common.error'))
  } finally {
    syncing.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div class="max-w-4xl">
    <UiPageHeader :title="t('tri.myucto.title')" :subtitle="t('tri.myucto.subtitle')">
      <template #actions>
        <UiButton variant="secondary" size="sm" :disabled="loading || syncing" @click="load()">
          {{ t('common.refresh') }}
        </UiButton>
        <UiButton variant="outline" size="sm" :disabled="syncing || !data?.enabled" @click="sync(true)">
          {{ t('tri.myucto.sync_full') }}
        </UiButton>
        <UiButton size="sm" :loading="syncing" :disabled="!data?.enabled" @click="sync(false)">
          {{ t('tri.myucto.sync_now') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <p v-if="loading && !data" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <template v-else-if="data">
      <div class="flex flex-wrap items-center gap-3 mb-6">
        <UiBadge v-if="!data.enabled" variant="warning">{{ t('tri.myucto.disabled') }}</UiBadge>
        <UiBadge v-else-if="data.health" variant="success">{{ t('tri.myucto.health_ok') }}</UiBadge>
        <UiBadge v-else variant="danger">{{ t('tri.myucto.health_fail') }}</UiBadge>
        <span v-if="data.health?.version" class="text-sm text-neutral-500">{{ data.health.version }}</span>
        <span v-if="data.health_error" class="text-sm text-danger-600">{{ data.health_error }}</span>
        <a
          v-if="data.public_url"
          :href="data.public_url"
          target="_blank"
          rel="noopener"
          class="text-sm text-primary-700 hover:underline"
        >{{ t('tri.myucto.open_myucto') }}</a>
        <span v-if="data.rate_limit?.remaining" class="text-xs text-neutral-400">
          {{ t('tri.myucto.rate_limit') }}: {{ data.rate_limit.remaining }}/{{ data.rate_limit.limit || '?' }}
        </span>
      </div>

      <UiCard class="mb-6" padding>
        <h2 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('tri.myucto.counts') }}</h2>
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
          <div>
            <dt class="text-neutral-500">{{ t('tri.myucto.clients') }}</dt>
            <dd class="text-lg font-semibold">{{ data.counts.clients }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('tri.myucto.projects') }}</dt>
            <dd class="text-lg font-semibold">{{ data.counts.projects }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('tri.myucto.invoices') }}</dt>
            <dd class="text-lg font-semibold">{{ data.counts.invoices }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('tri.myucto.vat_rates') }}</dt>
            <dd class="text-lg font-semibold">{{ data.counts.vat_rates }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard class="mb-6 overflow-x-auto" padding>
        <h2 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('tri.myucto.state') }}</h2>
        <table class="w-full text-sm">
          <thead class="text-xs uppercase text-neutral-500">
            <tr>
              <th class="text-left py-2 pr-3">{{ t('tri.myucto.key') }}</th>
              <th class="text-left py-2 pr-3">{{ t('tri.myucto.last_ok') }}</th>
              <th class="text-left py-2 pr-3">{{ t('tri.myucto.last_run') }}</th>
              <th class="text-left py-2">{{ t('tri.myucto.last_error') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in data.sync_state" :key="row.key" class="border-t border-neutral-100">
              <td class="py-2 pr-3 font-medium">{{ row.key }}</td>
              <td class="py-2 pr-3">{{ row.last_ok_at || t('tri.myucto.never') }}</td>
              <td class="py-2 pr-3">{{ row.last_run_at || '–' }}</td>
              <td class="py-2 text-danger-600">{{ row.last_error || '–' }}</td>
            </tr>
            <tr v-if="data.sync_state.length === 0">
              <td colspan="4" class="py-3 text-neutral-500">{{ t('tri.myucto.never') }}</td>
            </tr>
          </tbody>
        </table>
      </UiCard>

      <UiCard class="overflow-x-auto" padding>
        <h2 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('tri.myucto.log') }}</h2>
        <p v-if="data.recent_log.length === 0" class="text-sm text-neutral-500">{{ t('tri.myucto.no_log') }}</p>
        <ul v-else class="text-sm divide-y divide-neutral-100">
          <li v-for="item in data.recent_log" :key="item.id" class="py-2">
            <span class="font-medium">{{ item.kind }}</span>
            <span class="text-neutral-400 mx-2">{{ item.created_at }}</span>
            <span :class="item.http_status && item.http_status >= 400 ? 'text-danger-600' : 'text-neutral-600'">
              {{ item.message }}
            </span>
          </li>
        </ul>
      </UiCard>
    </template>
  </div>
</template>

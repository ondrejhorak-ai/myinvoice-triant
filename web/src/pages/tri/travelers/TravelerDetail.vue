<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriTraveler, type TriTravelerStation } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiTable from '@/components/ui/UiTable.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const id = computed(() => Number(route.params.id))
const traveler = ref<TriTraveler | null>(null)
const loading = ref(true)
const saving = ref(false)
const hoursDraft = ref<Record<string, string>>({})

function formatHoursInput(value: number | null | undefined): string {
  if (value == null) return ''
  return String(value).replace('.', ',')
}

function hydrate(row: TriTraveler) {
  const next: Record<string, string> = {}
  for (const op of row.operations ?? []) {
    next[op.station] = formatHoursInput(op.hours)
  }
  hoursDraft.value = next
}

async function load() {
  loading.value = true
  try {
    traveler.value = await triApi.travelers.get(id.value)
    hydrate(traveler.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

function operationsPayload() {
  return (traveler.value?.operations ?? []).map((op) => ({
    station: op.station as TriTravelerStation,
    hours: hoursDraft.value[op.station] ?? '',
  }))
}

async function save(status?: 'open' | 'done') {
  if (!traveler.value) return
  saving.value = true
  try {
    traveler.value = await triApi.travelers.saveOperations(traveler.value.id, {
      operations: operationsPayload(),
      ...(status ? { status } : {}),
    })
    hydrate(traveler.value)
    if (status === 'done') toast.success(t('tri.travelers.marked_done'))
    else if (status === 'open') toast.success(t('tri.travelers.reopened'))
    else toast.success(t('tri.travelers.hours_saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

onMounted(() => load())
watch(id, () => load())

function openPdf() {
  if (!traveler.value) return
  window.open(triApi.travelers.pdfUrl(traveler.value.id, false), '_blank')
}

const hoursTotal = computed(() => traveler.value?.hours_total ?? 0)

function formatHours(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}
</script>

<template>
  <div>
    <CardSkeleton v-if="loading" :blocks="2" />
    <template v-else-if="traveler">
      <UiPageHeader
        :title="traveler.number"
        :subtitle="`${traveler.job_number} — ${traveler.job_title}`"
      >
        <template #actions>
          <UiBadge :variant="traveler.status === 'done' ? 'success' : 'primary'">
            {{ t(`tri.travelers.status_${traveler.status}`) }}
          </UiBadge>
          <UiButton variant="outline" size="sm" @click="openPdf">
            {{ t('tri.travelers.download_pdf') }}
          </UiButton>
          <UiButton :to="{ name: 'tri-job-detail', params: { id: traveler.job_id } }" variant="ghost" size="sm">
            {{ t('tri.travelers.back_to_job') }}
          </UiButton>
        </template>
      </UiPageHeader>

      <div class="grid gap-4 lg:grid-cols-2">
        <UiCard>
          <div class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.item') }}</h3>
          </div>
          <dl class="p-5 space-y-3 text-sm">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.designation') }}</dt>
              <dd class="mt-1 font-mono">{{ traveler.designation || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.item_title') }}</dt>
              <dd class="mt-1 font-medium text-neutral-900">{{ traveler.title }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.description') }}</dt>
              <dd class="mt-1 whitespace-pre-wrap text-neutral-700">{{ traveler.description || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.quantity') }}</dt>
              <dd class="mt-1 tabular-nums">{{ traveler.quantity }} {{ traveler.unit }}</dd>
            </div>
          </dl>
        </UiCard>

        <UiCard>
          <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
            <div>
              <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.stations') }}</h3>
              <p class="mt-0.5 text-xs text-neutral-500">{{ t('tri.travelers.hours_hint') }}</p>
            </div>
            <div class="text-sm tabular-nums font-medium text-neutral-700">
              {{ t('tri.travelers.hours_total') }}: {{ formatHours(hoursTotal) }}
            </div>
          </div>
          <UiTable>
            <template #head>
              <tr>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.station') }}</th>
                <th class="text-right px-4 py-2.5 font-medium w-32">{{ t('tri.travelers.hours') }}</th>
              </tr>
            </template>
            <tr v-for="op in traveler.operations ?? []" :key="op.station">
              <td class="px-4 py-2.5">{{ t(`tri.travelers.station_${op.station}`) }}</td>
              <td class="px-4 py-2.5 text-right">
                <input
                  v-if="auth.canWrite"
                  v-model="hoursDraft[op.station]"
                  type="text"
                  inputmode="decimal"
                  class="w-24 h-9 px-2 text-right font-mono text-sm border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring"
                >
                <span v-else class="tabular-nums font-mono">{{ op.hours ?? '—' }}</span>
              </td>
            </tr>
          </UiTable>
          <div v-if="auth.canWrite" class="px-5 py-3 border-t border-neutral-200 flex flex-wrap gap-2 justify-end">
            <UiButton
              v-if="traveler.status === 'open'"
              variant="outline"
              size="sm"
              :loading="saving"
              @click="save('done')"
            >
              {{ t('tri.travelers.mark_done') }}
            </UiButton>
            <UiButton
              v-else
              variant="outline"
              size="sm"
              :loading="saving"
              @click="save('open')"
            >
              {{ t('tri.travelers.reopen') }}
            </UiButton>
            <UiButton size="sm" :loading="saving" @click="save()">
              {{ t('tri.travelers.save_hours') }}
            </UiButton>
          </div>
        </UiCard>
      </div>
    </template>
  </div>
</template>

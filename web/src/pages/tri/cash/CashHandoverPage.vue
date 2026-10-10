<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriCashHandover, type TriCashHandoverPreview } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import OfficeBreadcrumb from '@/components/tri/OfficeBreadcrumb.vue'

const { t } = useI18n()
const toast = useToast()

const counted = ref('')
const keep = ref('5000.00')
const book = ref('0.00')
const note = ref('')
const preview = ref<TriCashHandoverPreview | null>(null)
const history = ref<TriCashHandover[]>([])
const error = ref('')
const saving = ref(false)
let token = crypto.randomUUID()
let timer = 0

function money(value: string): string {
  return formatMoney(Number(value))
}

const lowFloat = computed(() => preview.value !== null && Number(preview.value.counted) < Number(preview.value.keep))
const canConfirm = computed(() => {
  if (!preview.value || saving.value) return false
  return preview.value.documents.length > 0 || Number(preview.value.remitted) > 0
})

function explain(err: unknown): string {
  const msg = apiErrorMessage(err, t('common.error'))
  if (msg === 'NOTHING_TO_HAND_OVER') return t('tri.cash.nothing')
  if (msg === 'INVALID_AMOUNT') return t('tri.cash.amount')
  if (msg === 'INVALID_DATE') return t('tri.cash.date')
  return msg
}

async function refreshPreview() {
  if (!counted.value.trim()) {
    preview.value = null
    return
  }
  try {
    preview.value = await triApi.cash.previewHandover({
      counted: counted.value.replace(',', '.'),
      keep: keep.value.replace(',', '.') || '5000.00',
    })
    error.value = ''
  } catch (err) {
    preview.value = null
    error.value = explain(err)
  }
}

async function loadHistory() {
  const result = await triApi.cash.list({ page: 1, per_page: 1 })
  history.value = result.handovers
  book.value = result.summary.drawer
}

async function confirm() {
  if (!canConfirm.value) return
  saving.value = true
  error.value = ''
  try {
    await triApi.cash.createHandover({
      counted: counted.value.replace(',', '.'),
      keep: keep.value.replace(',', '.') || '5000.00',
      note: note.value,
      client_token: token,
    })
    toast.success(t('common.saved'))
    counted.value = ''
    note.value = ''
    preview.value = null
    token = crypto.randomUUID()
    await loadHistory()
  } catch (err) {
    error.value = explain(err)
  } finally {
    saving.value = false
  }
}

watch([counted, keep], () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => void refreshPreview(), 250)
})
onMounted(() => void loadHistory())
</script>

<template>
  <div>
    <OfficeBreadcrumb :items="[{ label: t('tri.cash.title'), to: { name: 'tri-cash' } }, { label: t('tri.cash.handover_title') }]" />
    <UiPageHeader :title="t('tri.cash.handover_title')" :subtitle="t('tri.cash.handover_subtitle')" />

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(280px,1fr)]">
      <UiCard padding>
        <h2 class="text-base font-semibold mb-4">{{ t('tri.cash.count') }}</h2>
        <div class="office-page grid gap-4">
          <p class="flex justify-between text-sm"><span>{{ t('tri.cash.book') }}</span><strong>{{ money(preview?.book || book) }}</strong></p>
          <UiInput v-model="counted" inputmode="decimal" :label="t('tri.cash.count')" :hint="t('tri.cash.float_hint')" />
          <p v-if="preview" class="flex justify-between text-sm"><span>{{ t('tri.cash.difference') }}</span><strong>{{ money(preview.difference) }}</strong></p>
          <p class="flex justify-between text-sm"><span>{{ t('tri.cash.keep') }}</span><strong>{{ money(keep || '5000.00') }}</strong></p>
          <UiInput v-model="keep" inputmode="decimal" :label="t('tri.cash.keep')" />
          <UiInput v-model="note" :label="t('tri.cash.note')" />
          <p class="text-xs text-neutral-500">{{ t('tri.cash.paper') }}</p>
        </div>
      </UiCard>
      <UiCard padding>
        <h2 class="text-base font-semibold mb-4">{{ t('tri.cash.summary') }}</h2>
        <dl class="grid gap-3 text-sm">
          <div class="flex justify-between gap-3"><dt>{{ t('tri.cash.documents') }}</dt><dd class="font-semibold">{{ preview?.documents.length ?? 0 }}</dd></div>
          <div class="flex justify-between gap-3"><dt>{{ t('tri.cash.remit') }}</dt><dd class="font-semibold">{{ preview ? money(preview.remitted) : '—' }}</dd></div>
          <div class="flex justify-between gap-3"><dt>{{ t('tri.cash.remain') }}</dt><dd class="font-semibold">{{ preview ? money(preview.remain) : '—' }}</dd></div>
        </dl>
        <p class="mt-4 text-sm text-neutral-600">{{ t('tri.cash.not_posted') }}</p>
        <p v-if="lowFloat" class="mt-3 text-sm text-warning-600">{{ t('tri.cash.low_float') }}</p>
        <p v-if="error" class="mt-3 text-sm text-danger-600">{{ error }}</p>
        <UiButton class="mt-4" :disabled="!canConfirm" :loading="saving" @click="confirm">{{ t('tri.cash.confirm') }}</UiButton>
      </UiCard>
    </div>

    <UiCard class="mt-4">
      <h2 class="px-4 pt-4 text-base font-semibold">{{ t('tri.cash.documents') }}</h2>
      <p v-if="!preview || preview.documents.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('tri.cash.nothing') }}</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead><tr>
            <th class="text-left">{{ t('tri.cash.date') }}</th>
            <th class="text-left">{{ t('tri.cash.link') }}</th>
            <th class="text-left">{{ t('tri.cash.kind') }}</th>
            <th class="text-right">{{ t('tri.cash.amount') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="doc in preview.documents" :key="doc.id">
              <td>{{ formatDate(doc.occurred_on) }}</td>
              <td>{{ doc.description }}</td>
              <td>{{ t(`tri.cash.kind_${doc.kind}`) }}</td>
              <td class="text-right font-semibold">{{ money(doc.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiCard>

    <UiCard padding class="mt-4">
      <h2 class="text-base font-semibold mb-3">{{ t('tri.cash.history') }}</h2>
      <p v-if="history.length === 0" class="text-sm text-neutral-500">{{ t('tri.cash.empty') }}</p>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="row in history" :key="row.id" class="py-3 text-sm">
          <div class="flex justify-between gap-2 font-semibold">
            <span>{{ row.number }} · {{ formatDate(row.handed_on) }}</span>
            <span>{{ money(row.remitted_amount) }} / {{ money(row.keep_amount) }}</span>
          </div>
          <div v-if="row.handed_by_name" class="text-xs text-neutral-500 mt-1">{{ row.handed_by_name }}</div>
        </li>
      </ul>
    </UiCard>
  </div>
</template>

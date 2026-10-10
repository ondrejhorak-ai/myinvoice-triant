<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { triApi, type TriCashKind, type TriCashMovement } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiTable from '@/components/ui/UiTable.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiSelect from '@/components/ui/UiSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import Modal from '@/components/ui/Modal.vue'
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const summary = ref({ drawer: '0.00', card_open: '0.00', open_documents: 0, float: '5000.00' })
const rows = ref<TriCashMovement[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const page = ref(1)
const pages = ref(1)
const search = ref('')
const kind = ref('')
const handed = ref('all')
const filtersOpen = ref(false)
const draftKind = ref('')
const draftHanded = ref('all')
const formOpen = ref(false)
const saving = ref(false)
const formError = ref('')
const form = ref({ kind: 'cash_in' as TriCashKind, amount: '', occurred_on: today(), description: '', note: '' })
let token = ''

const kinds: TriCashKind[] = ['cash_in', 'cash_out', 'card']

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function money(value: string): string {
  return formatMoney(Number(value))
}

function signed(row: TriCashMovement): string {
  if (row.kind === 'cash_in') return `+${money(row.amount)}`
  if (row.kind === 'cash_out' || row.kind === 'remittance') return `−${money(row.amount)}`
  return money(row.amount)
}

const kindLabel = computed(() => (value: string) => t(`tri.cash.kind_${value}`))
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'kind', active: () => kind.value !== '', label: () => kindLabel.value(kind.value), clear: () => { kind.value = '' } },
  {
    id: 'handed',
    active: () => handed.value !== 'all',
    label: () => handed.value === 'open' ? t('tri.cash.handed_open') : t('tri.cash.handed_done'),
    clear: () => { handed.value = 'all' },
  },
])

function openFilters() {
  draftKind.value = kind.value
  draftHanded.value = handed.value
  filtersOpen.value = true
}

function applyCashFilters() {
  kind.value = draftKind.value
  handed.value = draftHanded.value
  filtersOpen.value = false
}

async function load(reset = true) {
  if (reset) {
    page.value = 1
    loading.value = true
  } else {
    loadingMore.value = true
  }
  try {
    const params: Record<string, string | number> = { page: page.value, per_page: 30 }
    if (search.value.trim()) params.q = search.value.trim()
    if (kind.value) params.kind = kind.value
    if (handed.value) params.handed = handed.value
    const result = await triApi.cash.list(params)
    summary.value = result.summary
    pages.value = result.meta.pages
    rows.value = reset ? result.data : rows.value.concat(result.data)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function more() {
  if (page.value >= pages.value) return
  page.value += 1
  void load(false)
}

function openForm() {
  form.value = { kind: 'cash_in', amount: '', occurred_on: today(), description: '', note: '' }
  formError.value = ''
  token = crypto.randomUUID()
  formOpen.value = true
}

function explain(err: unknown): string {
  const msg = apiErrorMessage(err, t('common.error'))
  if (msg === 'INVALID_AMOUNT') return t('tri.cash.amount')
  if (msg === 'INVALID_DESCRIPTION') return t('tri.cash.description')
  if (msg === 'INVALID_DATE') return t('tri.cash.date')
  if (msg === 'INVALID_KIND') return t('tri.cash.kind')
  return msg
}

async function save() {
  saving.value = true
  formError.value = ''
  try {
    await triApi.cash.createMovement({
      kind: form.value.kind,
      amount: form.value.amount.replace(',', '.'),
      occurred_on: form.value.occurred_on,
      description: form.value.description,
      note: form.value.note,
      client_token: token,
    })
    formOpen.value = false
    toast.success(t('tri.cash.saved'))
    await load(true)
  } catch (err) {
    formError.value = explain(err)
  } finally {
    saving.value = false
  }
}

let searchTimer = 0
watch(search, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => void load(true), 250)
})
watch([kind, handed], () => void load(true))
onMounted(() => void load(true))
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.cash.title')" :subtitle="t('tri.cash.subtitle')">
      <template #actions>
        <UiButton v-if="auth.canWrite" @click="openForm">{{ t('tri.cash.new_movement') }}</UiButton>
      </template>
    </UiPageHeader>

    <div class="grid gap-4 sm:grid-cols-3 mb-6">
      <div class="office-stat">
        <p class="text-xs text-neutral-500">{{ t('tri.cash.drawer') }}</p>
        <strong>{{ money(summary.drawer) }}</strong>
        <p class="mt-1 text-xs text-neutral-500">{{ t('tri.cash.drawer_hint') }}</p>
      </div>
      <div class="office-stat">
        <p class="text-xs text-neutral-500">{{ t('tri.cash.cards') }}</p>
        <strong>{{ money(summary.card_open) }}</strong>
        <p class="mt-1 text-xs text-neutral-500">{{ t('tri.cash.cards_hint') }}</p>
      </div>
      <div class="office-stat">
        <p class="text-xs text-neutral-500">{{ t('tri.cash.open_docs') }}</p>
        <strong>{{ summary.open_documents }}</strong>
        <p class="mt-1 text-xs text-neutral-500">{{ t('tri.cash.open_docs_hint') }}</p>
      </div>
    </div>

    <div v-if="auth.canWrite" class="flex flex-wrap gap-2 mb-6">
      <UiButton @click="openForm">{{ t('tri.cash.new_movement') }}</UiButton>
      <UiButton variant="secondary" @click="router.push({ name: 'tri-cash-handover' })">{{ t('tri.cash.handover') }}</UiButton>
    </div>

    <div class="office-filters">
      <UiInput v-model="search" type="search" class="w-full sm:max-w-[520px]" :label="t('common.search')" />
      <OfficeFilterButton :count="conditions.length" :expanded="filtersOpen" @click="openFilters" />
    </div>
    <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />

    <UiCard>

      <TableSkeleton v-if="loading" :rows="6" :cols="5" />
      <EmptyState v-else-if="rows.length === 0" :title="t('tri.cash.empty')">
        <UiButton v-if="auth.canWrite" size="sm" @click="openForm">{{ t('tri.cash.new_movement') }}</UiButton>
      </EmptyState>

      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.date') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.link') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.kind') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.handed') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.cash.amount') }}</th>
            </tr>
          </template>
          <tr v-for="row in rows" :key="row.id">
            <td class="px-4 py-3 whitespace-nowrap">{{ formatDate(row.occurred_on) }}</td>
            <td class="px-4 py-3">
              <div class="font-medium text-neutral-900">{{ row.description }}</div>
              <div v-if="row.note" class="text-xs text-neutral-500">{{ row.note }}</div>
            </td>
            <td class="px-4 py-3">{{ kindLabel(row.kind) }}</td>
            <td class="px-4 py-3">
              <UiBadge :variant="row.handover_id ? 'success' : 'warning'">
                {{ row.handover_number || t('tri.cash.handed_open') }}
              </UiBadge>
            </td>
            <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ signed(row) }}</td>
          </tr>
        </UiTable>
      </div>

      <div v-if="!loading && rows.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="row in rows" :key="`m-${row.id}`" class="px-4 py-3">
          <div class="flex items-center justify-between gap-2">
            <span class="font-medium text-sm">{{ kindLabel(row.kind) }}</span>
            <span class="font-semibold text-sm">{{ signed(row) }}</span>
          </div>
          <div class="mt-1 text-sm text-neutral-800">{{ row.description }}</div>
          <div class="mt-1 text-xs text-neutral-500">{{ formatDate(row.occurred_on) }} · {{ row.handover_number || t('tri.cash.handed_open') }}</div>
        </div>
      </div>

      <div v-if="page < pages" class="flex justify-center py-4">
        <UiButton variant="secondary" :loading="loadingMore" @click="more">{{ t('office.more') }}</UiButton>
      </div>
    </UiCard>

    <Modal v-if="formOpen" :title="t('tri.cash.new_movement')" width-class="max-w-lg" @close="formOpen = false">
      <form class="office-page grid gap-4" @submit.prevent="save">
        <UiSelect v-model="form.kind" :label="t('tri.cash.kind')">
          <option v-for="item in kinds" :key="item" :value="item">{{ kindLabel(item) }}</option>
        </UiSelect>
        <UiInput v-model="form.amount" inputmode="decimal" :label="t('tri.cash.amount')" required />
        <UiInput v-model="form.occurred_on" type="date" :label="t('tri.cash.date')" required />
        <UiInput v-model="form.description" :label="t('tri.cash.description')" required />
        <UiInput v-model="form.note" :label="t('tri.cash.note')" />
        <p class="text-xs text-neutral-500">{{ t('tri.cash.paper') }}</p>
        <p v-if="formError" class="text-sm text-danger-600">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <UiButton type="button" variant="secondary" @click="formOpen = false">{{ t('common.cancel') }}</UiButton>
          <UiButton type="submit" :loading="saving">{{ t('common.save') }}</UiButton>
        </div>
      </form>
    </Modal>

    <Modal v-if="filtersOpen" :title="t('office.filters')" width-class="max-w-lg" @close="filtersOpen = false">
      <div class="office-page grid gap-4">
        <UiSelect v-model="draftKind" :label="t('tri.cash.kind')">
          <option value="">{{ t('tri.cash.kind_all') }}</option>
          <option value="cash_in">{{ t('tri.cash.kind_cash_in') }}</option>
          <option value="cash_out">{{ t('tri.cash.kind_cash_out') }}</option>
          <option value="card">{{ t('tri.cash.kind_card') }}</option>
          <option value="remittance">{{ t('tri.cash.kind_remittance') }}</option>
        </UiSelect>
        <UiSelect v-model="draftHanded" :label="t('tri.cash.handed')">
          <option value="all">{{ t('tri.cash.handed_all') }}</option>
          <option value="open">{{ t('tri.cash.handed_open') }}</option>
          <option value="handed">{{ t('tri.cash.handed_done') }}</option>
        </UiSelect>
      </div>
      <template #footer>
        <UiButton variant="secondary" @click="clearFilters(); filtersOpen = false">{{ t('office.clear_filters') }}</UiButton>
        <UiButton @click="applyCashFilters">{{ t('office.apply_filters') }}</UiButton>
      </template>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriCashAuditEntry, type TriCashKind, type TriCashMovement, type TriJob } from '@/api/tri'
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
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const summary = ref({ drawer: '0.00', to_hand_over: '0.00', unaccounted: 0 })
const rows = ref<TriCashMovement[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const page = ref(1)
const pages = ref(1)
const search = ref('')
const kind = ref('')
const accounted = ref('open')
const dateFrom = ref('')
const dateTo = ref('')
const party = ref('')
const trash = ref(false)
const filtersOpen = ref(false)
const draftKind = ref('')
const draftAccounted = ref('open')
const draftFrom = ref('')
const draftTo = ref('')
const draftParty = ref('')
const draftTrash = ref(false)
const formOpen = ref(false)
const saving = ref(false)
const formError = ref('')
const editingId = ref<number | null>(null)
const readOnly = ref(false)
const audit = ref<TriCashAuditEntry[]>([])
const selected = ref<number[]>([])
const marking = ref(false)
const users = ref<Array<{ id: number; name: string }>>([])
const jobs = ref<TriJob[]>([])
const editingJob = ref<{ id: number; label: string } | null>(null)
const editingAuthor = ref<string | null>(null)
const confirmState = ref<null | { message: string; run: () => Promise<void> }>(null)
const form = ref(emptyForm())
let token = ''

const kinds: TriCashKind[] = ['cash_in', 'cash_out', 'card']

function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function emptyForm() {
  return {
    kind: 'cash_in' as TriCashKind,
    amount: '',
    occurred_on: today(),
    description: '',
    note: '',
    party_user_id: auth.user ? String(auth.user.id) : '',
    job_id: null as number | null,
    accounted: false,
  }
}

function money(value: string): string {
  return formatMoney(Number(value))
}

function signed(row: TriCashMovement): string {
  if (row.kind === 'cash_in') return `+${money(row.amount)}`
  if (row.kind === 'cash_out') return `−${money(row.amount)}`
  return money(row.amount)
}

function kindVariant(value: string): 'success' | 'danger' | 'primary' {
  if (value === 'cash_in') return 'success'
  if (value === 'cash_out') return 'danger'
  return 'primary'
}

function jobLabel(row: { job_number?: string | null; job_title?: string | null }): string {
  const number = row.job_number?.trim() ?? ''
  const title = row.job_title?.trim() ?? ''
  return title ? `${number} ${title}` : number
}

const kindLabel = computed(() => (value: string) => t(`tri.cash.kind_${value}`))
const partyName = computed(() => {
  const id = Number(party.value)
  return users.value.find((user) => user.id === id)?.name ?? party.value
})
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'kind', active: () => kind.value !== '', label: () => kindLabel.value(kind.value), clear: () => { kind.value = '' } },
  {
    id: 'accounted',
    active: () => accounted.value !== 'all',
    label: () => accounted.value === 'open' ? t('tri.cash.accounted_open') : t('tri.cash.accounted_done'),
    clear: () => { accounted.value = 'all' },
  },
  { id: 'from', active: () => dateFrom.value !== '', label: () => `${t('tri.cash.date_from')} ${formatDate(dateFrom.value)}`, clear: () => { dateFrom.value = '' } },
  { id: 'to', active: () => dateTo.value !== '', label: () => `${t('tri.cash.date_to')} ${formatDate(dateTo.value)}`, clear: () => { dateTo.value = '' } },
  { id: 'party', active: () => party.value !== '', label: () => partyName.value, clear: () => { party.value = '' } },
  { id: 'trash', active: () => trash.value, label: () => t('tri.cash.trash'), clear: () => { trash.value = false } },
])

const selectableIds = computed(() => rows.value.filter((row) => !row.accounted && !row.deleted_at).map((row) => row.id))
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every((id) => selected.value.includes(id)))
const jobOptions = computed(() => jobs.value.map((job) => ({
  value: job.id,
  label: `${job.number} — ${job.title}`,
  secondary: job.customer_name ?? undefined,
})))
const selectedJobOption = computed(() => {
  if (form.value.job_id == null) return null
  return jobOptions.value.find((option) => option.value === form.value.job_id)
    ?? (editingJob.value && editingJob.value.id === form.value.job_id
      ? { value: editingJob.value.id, label: editingJob.value.label }
      : null)
})

function setJob(value: number | null) {
  form.value.job_id = value
}

function canEdit(row: TriCashMovement): boolean {
  if (!auth.canWrite || row.deleted_at) return false
  if (row.accounted && !auth.isAdmin) return false
  return true
}

function openFilters() {
  draftKind.value = kind.value
  draftAccounted.value = accounted.value
  draftFrom.value = dateFrom.value
  draftTo.value = dateTo.value
  draftParty.value = party.value
  draftTrash.value = trash.value
  filtersOpen.value = true
}

function applyCashFilters() {
  if (auth.isAdmin && draftTrash.value && draftAccounted.value === 'open') {
    draftAccounted.value = 'all'
  }
  kind.value = draftKind.value
  accounted.value = draftAccounted.value
  dateFrom.value = draftFrom.value
  dateTo.value = draftTo.value
  party.value = draftParty.value
  trash.value = auth.isAdmin && draftTrash.value
  filtersOpen.value = false
}

async function load(reset = true) {
  if (reset) {
    page.value = 1
    loading.value = true
    selected.value = []
  } else {
    loadingMore.value = true
  }
  try {
    const params: Record<string, string | number | boolean> = { page: page.value, per_page: 30 }
    if (search.value.trim()) params.q = search.value.trim()
    if (kind.value) params.kind = kind.value
    if (accounted.value) params.accounted = accounted.value
    if (dateFrom.value) params.date_from = dateFrom.value
    if (dateTo.value) params.date_to = dateTo.value
    if (party.value) params.party_user_id = party.value
    if (trash.value) params.trash = '1'
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

async function ensureLookups() {
  if (users.value.length === 0) {
    users.value = await triApi.jobs.listUsers().catch(() => [])
  }
  if (jobs.value.length === 0) {
    jobs.value = (await triApi.jobs.list({ per_page: 200 }).catch(() => ({ data: [] as TriJob[] }))).data
  }
}

function fillForm(row: TriCashMovement) {
  form.value = {
    kind: (row.kind === 'cash_in' || row.kind === 'cash_out' || row.kind === 'card') ? row.kind : 'cash_out',
    amount: row.amount,
    occurred_on: row.occurred_on.slice(0, 10),
    description: row.description,
    note: row.note ?? '',
    party_user_id: row.party_user_id ? String(row.party_user_id) : '',
    job_id: row.job_id,
    accounted: row.accounted,
  }
  editingJob.value = row.job_id ? { id: row.job_id, label: jobLabel(row) || String(row.job_id) } : null
  editingAuthor.value = row.recorded_by_name
}

async function openForm() {
  editingId.value = null
  readOnly.value = false
  audit.value = []
  editingJob.value = null
  editingAuthor.value = null
  form.value = emptyForm()
  formError.value = ''
  token = crypto.randomUUID()
  formOpen.value = true
  await ensureLookups()
}

async function openEdit(row: TriCashMovement) {
  editingId.value = row.id
  readOnly.value = !canEdit(row)
  fillForm(row)
  audit.value = []
  formError.value = ''
  formOpen.value = true
  await ensureLookups()
  try {
    const detail = await triApi.cash.getMovement(row.id)
    fillForm(detail.movement)
    audit.value = detail.audit
    readOnly.value = !canEdit(detail.movement)
  } catch {
    /* seznam už řádek ukázal */
  }
}

function explain(err: unknown): string {
  const msg = apiErrorMessage(err, t('common.error'))
  if (msg === 'INVALID_AMOUNT') return t('tri.cash.amount')
  if (msg === 'INVALID_DESCRIPTION') return t('tri.cash.name')
  if (msg === 'INVALID_DATE') return t('tri.cash.date')
  if (msg === 'INVALID_KIND') return t('tri.cash.kind')
  return msg
}

function ask(message: string, run: () => Promise<void>) {
  confirmState.value = { message, run }
}

async function runConfirm() {
  const pending = confirmState.value
  confirmState.value = null
  if (pending) await pending.run()
}

function remove(row: TriCashMovement) {
  if (!canEdit(row)) return
  ask(t('tri.cash.delete_confirm'), async () => {
    try {
      await triApi.cash.deleteMovement(row.id)
      toast.success(t('tri.cash.deleted'))
      await load(true)
    } catch (err) {
      toast.error(explain(err))
    }
  })
}

function restore(row: TriCashMovement) {
  ask(t('tri.cash.restore'), async () => {
    try {
      await triApi.cash.restoreMovement(row.id)
      toast.success(t('tri.cash.restored'))
      await load(true)
    } catch (err) {
      toast.error(explain(err))
    }
  })
}

function toggle(id: number, checked: boolean) {
  selected.value = checked ? [...selected.value, id] : selected.value.filter((item) => item !== id)
}

function togglePage(checked: boolean) {
  selected.value = checked ? [...selectableIds.value] : []
}

function markSelected() {
  if (!auth.isAdmin || selected.value.length === 0) return
  const ids = [...selected.value]
  ask(t('tri.cash.mark_confirm'), async () => {
    marking.value = true
    try {
      const result = await triApi.cash.accountMovements(ids)
      toast.success(t('tri.cash.marked', { n: result.updated }))
      await load(true)
    } catch (err) {
      toast.error(explain(err))
    } finally {
      marking.value = false
    }
  })
}

async function save() {
  if (readOnly.value) return
  saving.value = true
  formError.value = ''
  try {
    const payload: Record<string, unknown> = {
      kind: form.value.kind,
      amount: form.value.amount.replace(/\s/g, '').replace(',', '.'),
      occurred_on: form.value.occurred_on,
      description: form.value.description,
      note: form.value.note,
      party_user_id: form.value.party_user_id ? Number(form.value.party_user_id) : null,
      job_id: form.value.job_id,
    }
    if (auth.isAdmin) payload.accounted = form.value.accounted
    if (editingId.value) {
      await triApi.cash.updateMovement(editingId.value, payload)
      toast.success(t('tri.cash.updated'))
    } else {
      await triApi.cash.createMovement({ ...payload, client_token: token })
      toast.success(t('tri.cash.saved'))
    }
    formOpen.value = false
    await load(true)
  } catch (err) {
    formError.value = explain(err)
  } finally {
    saving.value = false
  }
}

function stamp(value: string): string {
  const date = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(date.getTime())) return formatDate(value)
  return date.toLocaleString('cs-CZ', { dateStyle: 'medium', timeStyle: 'short' })
}

function auditTitle(entry: TriCashAuditEntry): string {
  const key = `tri.cash.audit_${entry.action}`
  const label = t(key)
  const who = entry.user_name ?? ''
  return label === key ? entry.action : `${label}${who ? ' ' + who : ''}`
}

function auditValue(field: string, value: string | number | boolean | null): string {
  if (value == null || value === '') return '—'
  if (field === 'accounted') return value === true || value === 1 || value === '1' ? t('common.yes') : t('common.no')
  if (field === 'kind') return kindLabel.value(String(value))
  if (field === 'amount') return money(String(value))
  if (field === 'occurred_on') return formatDate(String(value))
  return String(value)
}

const auditFields = ['description', 'occurred_on', 'amount', 'kind', 'party_name', 'job_label', 'note', 'accounted'] as const

function fieldLabel(field: string): string {
  if (field === 'description') return t('tri.cash.name')
  if (field === 'party_name') return t('tri.cash.party')
  if (field === 'job_label') return t('tri.cash.job')
  if (field === 'note') return t('tri.cash.note')
  if (field === 'kind') return t('tri.cash.kind')
  if (field === 'amount') return t('tri.cash.amount')
  if (field === 'occurred_on') return t('tri.cash.date')
  return t('tri.cash.accounted')
}

let searchTimer = 0
watch(search, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => void load(true), 250)
})
watch([kind, accounted, dateFrom, dateTo, party, trash], () => void load(true))
onMounted(() => {
  void ensureLookups()
  void load(true)
})
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
        <p class="text-xs text-neutral-500">{{ t('tri.cash.to_hand_over') }}</p>
        <strong>{{ money(summary.to_hand_over) }}</strong>
        <p class="mt-1 text-xs text-neutral-500">{{ t('tri.cash.to_hand_over_hint') }}</p>
      </div>
      <div class="office-stat">
        <p class="text-xs text-neutral-500">{{ t('tri.cash.unaccounted') }}</p>
        <strong>{{ summary.unaccounted }}</strong>
        <p class="mt-1 text-xs text-neutral-500">{{ t('tri.cash.unaccounted_hint') }}</p>
      </div>
    </div>

    <div class="office-filters">
      <UiInput v-model="search" type="search" class="w-full sm:max-w-[520px]" :label="t('common.search')" />
      <OfficeFilterButton :count="conditions.length" :expanded="filtersOpen" @click="openFilters" />
      <UiButton
        v-if="auth.isAdmin && !trash && selected.length"
        :loading="marking"
        @click="markSelected"
      >
        {{ t('tri.cash.mark_accounted') }} ({{ selected.length }})
      </UiButton>
    </div>
    <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />

    <UiCard>
      <TableSkeleton v-if="loading" :rows="6" :cols="8" />
      <EmptyState v-else-if="rows.length === 0" :title="trash ? t('tri.cash.empty_trash') : accounted === 'open' ? t('tri.cash.empty_open') : t('tri.cash.empty')">
        <UiButton v-if="auth.canWrite && !trash" size="sm" @click="openForm">{{ t('tri.cash.new_movement') }}</UiButton>
      </EmptyState>

      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th v-if="auth.isAdmin && !trash" class="px-4 py-2.5">
                <input
                  type="checkbox"
                  class="rounded border-neutral-300 text-primary-600"
                  :checked="allSelected"
                  :aria-label="t('tri.cash.select_page')"
                  @change="togglePage(($event.target as HTMLInputElement).checked)"
                />
              </th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.name') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.date') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.cash.amount') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.kind') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.party') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.created_by') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.job') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.cash.accounted') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </template>
          <tr v-for="row in rows" :key="row.id">
            <td v-if="auth.isAdmin && !trash" class="px-4 py-3">
              <input
                v-if="!row.accounted"
                type="checkbox"
                class="rounded border-neutral-300 text-primary-600"
                :checked="selected.includes(row.id)"
                :aria-label="row.description"
                @change="toggle(row.id, ($event.target as HTMLInputElement).checked)"
              />
            </td>
            <td class="px-4 py-3">
              <div class="font-medium text-neutral-900">{{ row.description }}</div>
              <div v-if="row.note" class="text-xs text-neutral-500">{{ row.note }}</div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">{{ formatDate(row.occurred_on) }}</td>
            <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ signed(row) }}</td>
            <td class="px-4 py-3"><UiBadge :variant="kindVariant(row.kind)">{{ kindLabel(row.kind) }}</UiBadge></td>
            <td class="px-4 py-3">{{ row.party_name || '—' }}</td>
            <td class="px-4 py-3">{{ row.recorded_by_name || '—' }}</td>
            <td class="px-4 py-3">
              <RouterLink
                v-if="row.job_id"
                :to="{ name: 'tri-job-detail', params: { id: row.job_id } }"
                class="text-primary-700 hover:underline"
              >
                {{ jobLabel(row) }}
              </RouterLink>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-4 py-3">
              <UiBadge :variant="row.accounted ? 'success' : 'warning'">
                {{ row.accounted ? t('common.yes') : t('common.no') }}
              </UiBadge>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button type="button" class="cursor-pointer text-sm text-primary-700" @click="openEdit(row)">
                {{ canEdit(row) ? t('tri.cash.edit') : t('tri.cash.detail') }}
              </button>
              <button v-if="canEdit(row)" type="button" class="ml-3 cursor-pointer text-sm text-danger-600" @click="remove(row)">
                {{ t('common.delete') }}
              </button>
              <button v-else-if="auth.isAdmin && row.deleted_at" type="button" class="ml-3 cursor-pointer text-sm text-primary-700" @click="restore(row)">
                {{ t('tri.cash.restore') }}
              </button>
            </td>
          </tr>
        </UiTable>
      </div>

      <div v-if="!loading && rows.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="row in rows" :key="`m-${row.id}`" class="px-4 py-3">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="font-medium text-sm text-neutral-900">{{ row.description }}</div>
              <div class="mt-1 flex flex-wrap items-center gap-2">
                <UiBadge :variant="kindVariant(row.kind)">{{ kindLabel(row.kind) }}</UiBadge>
                <UiBadge :variant="row.accounted ? 'success' : 'warning'">
                  {{ row.accounted ? t('common.yes') : t('common.no') }}
                </UiBadge>
              </div>
            </div>
            <span class="font-semibold text-sm whitespace-nowrap">{{ signed(row) }}</span>
          </div>
          <div class="mt-1 text-xs text-neutral-500">
            {{ formatDate(row.occurred_on) }}
            <span v-if="row.party_name"> · {{ row.party_name }}</span>
            <span v-if="row.recorded_by_name"> · {{ t('tri.cash.created_by') }} {{ row.recorded_by_name }}</span>
            <span v-if="row.job_id"> · {{ jobLabel(row) }}</span>
          </div>
          <div class="mt-2 flex items-center gap-3">
            <input
              v-if="auth.isAdmin && !trash && !row.accounted"
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600"
              :checked="selected.includes(row.id)"
              :aria-label="row.description"
              @change="toggle(row.id, ($event.target as HTMLInputElement).checked)"
            />
            <button type="button" class="cursor-pointer text-sm text-primary-700" @click="openEdit(row)">
              {{ canEdit(row) ? t('tri.cash.edit') : t('tri.cash.detail') }}
            </button>
            <button v-if="canEdit(row)" type="button" class="cursor-pointer text-sm text-danger-600" @click="remove(row)">
              {{ t('common.delete') }}
            </button>
            <button v-else-if="auth.isAdmin && row.deleted_at" type="button" class="cursor-pointer text-sm text-primary-700" @click="restore(row)">
              {{ t('tri.cash.restore') }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="page < pages" class="flex justify-center py-4">
        <UiButton variant="secondary" :loading="loadingMore" @click="more">{{ t('office.more') }}</UiButton>
      </div>
    </UiCard>

    <Modal v-if="formOpen" :title="editingId ? (readOnly ? t('tri.cash.detail') : t('tri.cash.edit')) : t('tri.cash.new_movement')" width-class="max-w-lg" @close="formOpen = false">
      <form class="office-page grid gap-4" @submit.prevent="save">
        <p v-if="readOnly" class="text-sm text-neutral-500">{{ t('tri.cash.readonly') }}</p>
        <p v-if="editingId" class="text-sm text-neutral-700">
          {{ t('tri.cash.created_by') }}: {{ editingAuthor || '—' }}
        </p>
        <UiInput v-model="form.description" :label="t('tri.cash.name')" :disabled="readOnly" required />
        <UiInput v-model="form.occurred_on" type="date" :label="t('tri.cash.date')" :disabled="readOnly" required />
        <UiInput v-model="form.amount" inputmode="decimal" :label="t('tri.cash.amount')" :disabled="readOnly" required />
        <UiSelect v-model="form.kind" :label="t('tri.cash.kind')" :disabled="readOnly">
          <option v-for="item in kinds" :key="item" :value="item">{{ kindLabel(item) }}</option>
        </UiSelect>
        <UiSelect v-model="form.party_user_id" :label="t('tri.cash.party')" :disabled="readOnly">
          <option value="">—</option>
          <option v-for="user in users" :key="user.id" :value="String(user.id)">{{ user.name }}</option>
        </UiSelect>
        <div>
          <p class="block text-xs font-semibold text-neutral-600 mb-1">{{ t('tri.cash.job') }}</p>
          <SearchableSelect
            :model-value="form.job_id"
            :options="jobOptions"
            :selected-option="selectedJobOption"
            :placeholder="t('tri.cash.no_job')"
            :disabled="readOnly"
            @update:model-value="setJob"
          />
        </div>
        <label v-if="auth.isAdmin" class="flex items-center gap-2 text-sm text-neutral-800">
          <input v-model="form.accounted" type="checkbox" class="rounded border-neutral-300 text-primary-600" :disabled="readOnly" />
          {{ t('tri.cash.accounted') }}
        </label>
        <UiInput v-model="form.note" :label="t('tri.cash.note')" :disabled="readOnly" />
        <p class="text-xs text-neutral-500">{{ t('tri.cash.paper') }}</p>
        <div v-if="editingId" class="border-t border-neutral-100 pt-3">
          <p class="text-xs font-semibold text-neutral-600 mb-2">{{ t('tri.cash.audit') }}</p>
          <p v-if="audit.length === 0" class="text-sm text-neutral-500">{{ t('tri.cash.audit_empty') }}</p>
          <ul v-else class="space-y-3">
            <li v-for="entry in audit" :key="entry.id" class="text-sm">
              <div class="text-neutral-800">{{ auditTitle(entry) }}</div>
              <div class="text-xs text-neutral-500">{{ stamp(entry.created_at) }}</div>
              <ul v-if="entry.action === 'updated' && entry.changes" class="mt-1 space-y-0.5 text-xs text-neutral-600">
                <li v-for="field in auditFields" :key="field">
                  <template v-if="entry.changes[field]">
                    {{ fieldLabel(field) }}: {{ auditValue(field, entry.changes[field].from) }} → {{ auditValue(field, entry.changes[field].to) }}
                  </template>
                </li>
              </ul>
            </li>
          </ul>
        </div>
        <p v-if="formError" class="text-sm text-danger-600">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <UiButton type="button" variant="secondary" @click="formOpen = false">{{ readOnly ? t('common.close') : t('common.cancel') }}</UiButton>
          <UiButton v-if="!readOnly" type="submit" :loading="saving">{{ t('common.save') }}</UiButton>
        </div>
      </form>
    </Modal>

    <Modal v-if="filtersOpen" :title="t('office.filters')" width-class="max-w-lg" @close="filtersOpen = false">
      <div class="office-page grid gap-4">
        <UiSelect v-model="draftAccounted" :label="t('tri.cash.accounted')">
          <option value="open">{{ t('tri.cash.accounted_open') }}</option>
          <option value="done">{{ t('tri.cash.accounted_done') }}</option>
          <option value="all">{{ t('tri.cash.accounted_all') }}</option>
        </UiSelect>
        <UiSelect v-model="draftKind" :label="t('tri.cash.kind')">
          <option value="">{{ t('tri.cash.kind_all') }}</option>
          <option value="cash_in">{{ t('tri.cash.kind_cash_in') }}</option>
          <option value="cash_out">{{ t('tri.cash.kind_cash_out') }}</option>
          <option value="card">{{ t('tri.cash.kind_card') }}</option>
        </UiSelect>
        <UiInput v-model="draftFrom" type="date" :label="t('tri.cash.date_from')" />
        <UiInput v-model="draftTo" type="date" :label="t('tri.cash.date_to')" />
        <UiSelect v-model="draftParty" :label="t('tri.cash.party')">
          <option value="">{{ t('tri.cash.party_all') }}</option>
          <option v-for="user in users" :key="user.id" :value="String(user.id)">{{ user.name }}</option>
        </UiSelect>
        <label v-if="auth.isAdmin" class="flex items-center gap-2 text-sm">
          <input v-model="draftTrash" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('tri.cash.trash') }}
        </label>
      </div>
      <template #footer>
        <UiButton variant="secondary" @click="clearFilters(); filtersOpen = false">{{ t('office.clear_filters') }}</UiButton>
        <UiButton @click="applyCashFilters">{{ t('office.apply_filters') }}</UiButton>
      </template>
    </Modal>

    <Modal v-if="confirmState" :title="t('tri.cash.title')" width-class="max-w-md" @close="confirmState = null">
      <p class="text-sm text-neutral-800">{{ confirmState.message }}</p>
      <template #footer>
        <UiButton variant="secondary" @click="confirmState = null">{{ t('common.cancel') }}</UiButton>
        <UiButton @click="runConfirm">{{ t('common.yes') }}</UiButton>
      </template>
    </Modal>
  </div>
</template>

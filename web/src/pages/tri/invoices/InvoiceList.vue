<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { invoicesApi, type MonthGroup, type InvoiceListItem } from '@/api/invoices'
import { triInvoicesApi } from '@/api/triInvoices'
import { triApi, type TriJob } from '@/api/tri'
import { formatMoney, formatDate, formatMonth, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useRowLink } from '@/composables/useRowLink'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'

const { t, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/tri/invoices/new') })

const router = useRouter()
const route = useRoute()

const groups = ref<MonthGroup[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<string>('')
const typeFilter = ref<string>('')
const clientFilter = ref<number | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref<string>('')
const dateTo = ref<string>('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
const currencyFilter = ref<string>('')
const jobFilter = ref<number | ''>('')
const clients = ref<Client[]>([])
const triJobs = ref<TriJob[]>([])
const currencies = ref<Currency[]>([])

const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function hasPositiveAmountToPay(inv: InvoiceListItem): boolean {
  if (!['invoice', 'proforma'].includes(inv.invoice_type)) return true
  return Number(inv.amount_to_pay ?? 0) > 0
}

function toggleSelected(id: number) {
  const i = selectedIds.value.indexOf(id)
  if (i === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(i, 1)
}

async function bulkReissue() {
  if (selectedIds.value.length === 0) return
  if (!confirm(t('invoice.bulk_clone_confirm', { n: selectedIds.value.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkReissue(selectedIds.value, { increment_month_in_descriptions: true })
    selectedIds.value = []
    if (r.errors.length) {
      toast.warning(t('invoice.bulk_reissue_partial', { ok: r.created.length, err: r.errors.length }))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: r.created.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reissue_failed'))
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné odeslání klientům — pouze faktury se status issued/sent/reminded/paid + ne cancellation
const sendableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

// Hromadné vystavení — jen drafty. Řadíme podle issue_date asc, pak id asc, aby varsymboly šly sekvenčně.
const issuableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && inv.status === 'draft')
    .sort((a, b) => (a.issue_date || '').localeCompare(b.issue_date || '') || (a.id - b.id))
})

// Hromadné označení za zaplacené — jen issued/sent/reminded (ne paid, ne cancelled, ne draft, ne cancellation)
const markPayableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
      && hasPositiveAmountToPay(inv)
    )
})

// Hromadná upomínka — jen běžné faktury (ne proforma/dobropis/storno) ve stavu issued/sent/reminded,
// po splatnosti a placené bankovním převodem (kartové/hotovostní úhrady se neupomínají).
const reminderSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => {
      if (!ids.has(inv.id)) return false
      if (inv.invoice_type !== 'invoice') return false
      if (!['issued', 'sent', 'reminded'].includes(inv.status)) return false
      if (!hasPositiveAmountToPay(inv)) return false
      if ((inv.payment_method ?? 'bank_transfer') !== 'bank_transfer') return false
      const due = new Date(inv.due_date)
      return due < today
    })
})

async function bulkSendReminders() {
  const list = reminderSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_reminder_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_reminder_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkSendReminders(list.map(i => i.id))
    selectedIds.value = []
    if (r.errors.length) {
      const detail = r.errors.map(e => `#${e.invoice_id}: ${e.error}`).join('\n')
      toast.warning(t('invoice.bulk_reminder_partial', { ok: r.sent.length, err: r.errors.length }) + '\n' + detail)
    } else {
      toast.success(t('invoice.bulk_reminder_success', { n: r.sent.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reminder_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkMarkPaid() {
  const list = markPayableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_mark_paid_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_mark_paid_confirm', { n: list.length }))) return
  const today = new Date().toISOString().slice(0, 10)
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await triInvoicesApi.markPaid(inv.id, today)
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    const msg = errors.length
      ? t('invoice.bulk_mark_paid_partial', { ok: okCount, err: errors.length })
      : t('invoice.bulk_mark_paid_success', { n: okCount })
    if (errors.length) {
      toast.warning(msg + '\n' + errors.join('\n'))
    } else {
      toast.success(msg)
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkIssue() {
  const list = issuableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_issue_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_issue_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await triInvoicesApi.issue(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`#${inv.id}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_issue_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_issue_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkSend() {
  const list = sendableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_send_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_send_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await triInvoicesApi.send(inv.id, {})
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_send_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function exportCsv() {
  try {
    const r = await invoicesApi.exportCsv({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
    })
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.csv_export_failed'))
  }
}

function mergeGroups(existing: MonthGroup[], incoming: MonthGroup[]): MonthGroup[] {
  const byMonth = new Map<string, MonthGroup>()
  for (const g of existing) byMonth.set(g.month, g)
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, g)
      continue
    }
    cur.invoices.push(...g.invoices)
    cur.count += g.count
    // Merge totals_per_currency
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = Math.round((found.without_vat + t.without_vat) * 100) / 100
        found.vat         = Math.round((found.vat         + t.vat)         * 100) / 100
        found.with_vat    = Math.round((found.with_vat    + t.with_vat)    * 100) / 100
      } else {
        cur.totals_per_currency.push({ ...t })
      }
    }
  }
  return Array.from(byMonth.values()).sort((a, b) => b.month.localeCompare(a.month))
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const params: Record<string, string | number> = { page: page.value }
    if (search.value) params.q = search.value
    if (statusFilter.value) params['filter[status]'] = statusFilter.value
    if (typeFilter.value) params['filter[type]'] = typeFilter.value
    if (clientFilter.value !== '') params['filter[client_id]'] = Number(clientFilter.value)
    if (jobFilter.value !== '') params['filter[tri_job_id]'] = Number(jobFilter.value)
    if (dateFrom.value || dateTo.value) {
      if (dateFrom.value) params['filter[date_from]'] = dateFrom.value
      if (dateTo.value) params['filter[date_to]'] = dateTo.value
    } else if (yearFilter.value !== '') {
      params['filter[year]'] = Number(yearFilter.value)
      if (monthFilter.value !== '') params['filter[month]'] = Number(monthFilter.value)
    }
    if (currencyFilter.value) params['filter[currency]'] = currencyFilter.value
    if (overdueOnly.value) params['filter[overdue]'] = '1'
    if (unpaidOnly.value) params['filter[unpaid_only]'] = '1'

    const result = await triApi.invoices.list(params)
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

// Sync filtrů s URL query (stejný pattern jako PurchaseInvoiceList) — detekuje menu
// link click přes route.query change z !empty na empty → reset.
const DEFAULT_YEAR = new Date().getFullYear()

onMounted(async () => {
  loadFiltersFromQuery(route.query)
  // Načti seznam klientů + měn pro select (paralelně s prvním load)
  clientsApi.list({ archived: false, per_page: 200, role: 'customers' }).then(r => { clients.value = r.data }).catch(() => {})
  triApi.jobs.list({ per_page: 200 }).then(r => { triJobs.value = r.data }).catch(() => {})
  codebooksApi.currencies().then(r => {
    const seen = new Set<string>()
    currencies.value = r.filter(c => c.is_active && !seen.has(c.code) && seen.add(c.code))
  }).catch(() => {})
  await load(true)
})

function loadFiltersFromQuery(q: typeof route.query) {
  statusFilter.value = typeof q.status === 'string' ? q.status : ''
  typeFilter.value   = typeof q.type === 'string' ? q.type : ''
  clientFilter.value = typeof q.client_id === 'string' && q.client_id !== '' ? Number(q.client_id) : ''
  overdueOnly.value  = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value   = q.unpaid === '1' || q.unpaid === 'true'
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value) ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  jobFilter.value = typeof q.tri_job_id === 'string' && q.tri_job_id !== '' ? Number(q.tri_job_id) : ''
  search.value       = typeof q.q === 'string' ? q.q : ''
}

let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (typeFilter.value) q.type = typeFilter.value
  if (clientFilter.value !== '') q.client_id = String(clientFilter.value)
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (jobFilter.value !== '') q.tri_job_id = String(jobFilter.value)
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (search.value) q.q = search.value
  router.replace({ query: q })
}

watch([statusFilter, typeFilter, clientFilter, jobFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, currencyFilter], () => {
  syncFiltersToUrl()
  load(true)
})
// Když se vyčistí rok (vše/range), automaticky zrušit i měsíční filtr.
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })
watch([dateFrom, dateTo], ([f, to]) => { if (f || to) monthFilter.value = '' })
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load(true) }, 300)
})

// Reset filtrů při menu link click (route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    suppressUrlSync = true
    statusFilter.value = ''
    typeFilter.value = ''
    clientFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    currencyFilter.value = ''
    jobFilter.value = ''
    search.value = ''
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

const loadedCount = computed(() => groups.value.reduce((s, g) => s + g.count, 0))

const navigateRow = useRowLink()
function openInvoice(inv: InvoiceListItem, e?: MouseEvent) {
  navigateRow(`/tri/invoices/${inv.id}`, e)
}

// Year dropdown — distinct roky z `invoices` aktuálního supplier (issue #33).
// Composable doplňuje aktuální + minulý rok + aktuálně zvolený rok z URL.
const yearOptions = useYearOptions('invoices', yearFilter)

// `tm()` vrací raw translation message (pole), kdežto `t()` na poli vrátí stringified verzi.
// `rt()` zformátuje jednotlivé položky pole (pro případnou interpolaci).
const monthOptions = computed(() => (tm('common.months_short') as unknown as string[]).map(m => rt(m)))
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.invoices.title')" :subtitle="t('invoice.subtitle_grouping')">
      <template #actions>
        <UiButton v-if="false && (issuableSelected.length > 0) && auth.canWrite" size="sm" :loading="bulkBusy" :disabled="bulkBusy" @click="bulkIssue">
          {{ bulkBusy ? '…' : t('invoice.bulk_issue', { n: issuableSelected.length }) }}
        </UiButton>
        <UiButton v-if="false && (selectedIds.length > 0) && auth.canWrite" variant="outline" size="sm" :loading="bulkBusy" :disabled="bulkBusy" @click="bulkReissue">
          {{ bulkBusy ? '…' : t('invoice.bulk_reissue', { n: selectedIds.length }) }}
        </UiButton>
        <UiButton v-if="false && (markPayableSelected.length > 0) && auth.canWrite" variant="outline" size="sm" :loading="bulkBusy" :disabled="bulkBusy" @click="bulkMarkPaid">
          {{ bulkBusy ? '…' : t('invoice.bulk_mark_paid', { n: markPayableSelected.length }) }}
        </UiButton>
        <UiButton v-if="false && (sendableSelected.length > 0) && auth.canWrite" size="sm" :loading="bulkBusy" :disabled="bulkBusy" @click="bulkSend">
          {{ bulkBusy ? '…' : t('invoice.bulk_send', { n: sendableSelected.length }) }}
        </UiButton>
        <UiButton v-if="false && (reminderSelected.length > 0) && auth.canWrite" size="sm" :loading="bulkBusy" :disabled="bulkBusy" @click="bulkSendReminders">
          {{ bulkBusy ? '…' : t('invoice.bulk_reminder', { n: reminderSelected.length }) }}
        </UiButton>
        <UiButton v-if="auth.canWrite" to="/tri/invoices/new" size="sm">
          {{ t('tri.invoices.new') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <UiCard class="mb-4">
      <div class="p-3 flex flex-wrap items-center gap-2">
        <UiInput
          v-model="search"
          type="search"
          size="sm"
          class="flex-1 min-w-48"
          :placeholder="t('invoice.search_placeholder')"
        />
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm shadow-xs outline-none focus-ring">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="draft">{{ t('status.draft') }}</option>
          <option value="issued">{{ t('status.issued') }}</option>
          <option value="sent">{{ t('status.sent') }}</option>
          <option value="reminded">{{ t('status.reminded') }}</option>
          <option value="paid">{{ t('status.paid') }}</option>
          <option value="cancelled">{{ t('status.cancelled') }}</option>
        </select>
        <select v-model="typeFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm shadow-xs outline-none focus-ring">
          <option value="">{{ t('invoice.all_types') }}</option>
          <option value="invoice">{{ t('type.invoice') }}</option>
          <option value="proforma">{{ t('type.proforma') }}</option>
          <option value="credit_note">{{ t('type.credit_note') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="clientFilter === '' ? null : clientFilter"
            @update:model-value="(v) => clientFilter = v === null ? '' : v"
            :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('project.all_clients')"
          />
        </div>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="jobFilter === '' ? null : jobFilter"
            @update:model-value="(v) => jobFilter = v === null ? '' : v"
            :options="triJobs.map(j => ({ value: j.id, label: `${j.number} — ${j.title}`, secondary: j.customer_name ?? undefined }))"
            :placeholder="t('tri.invoices.job_label')"
          />
        </div>
        <select v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm shadow-xs outline-none focus-ring">
          <option value="">{{ t('invoice.all_currencies') }}</option>
          <option v-for="c in currencies" :key="c.id" :value="c.code">{{ c.code }}</option>
        </select>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm shadow-xs outline-none focus-ring disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm shadow-xs outline-none focus-ring disabled:opacity-50"
          :title="t('invoice.month_filter')">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" placeholder="Od"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring" title="Datum od" />
        <input v-model="dateTo" type="date" placeholder="Do"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring" title="Datum do" />
        <UiButton v-if="dateFrom || dateTo" variant="ghost" size="sm" @click="dateFrom = ''; dateTo = ''">{{ t('invoice.clear_date_filter') }}</UiButton>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.overdue_only') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.unpaid_only') }}
        </label>
        <UiButton variant="outline" size="sm" class="ml-auto" @click="exportCsv">
          {{ t('invoice.csv_export') }}
        </UiButton>
      </div>
    </UiCard>

    <UiCard v-if="loading">
      <TableSkeleton :rows="8" :cols="7" />
    </UiCard>

    <UiCard v-else-if="!groups.length">
      <EmptyState :title="t('tri.invoices.no_data_all')" :cta="t('tri.invoices.new')" to="/tri/invoices/new" />
    </UiCard>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('invoice.summary_count', { n: total, m: groups.length }) }}</span>
        <span v-if="total > loadedCount">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- Skupiny po měsících -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="sticky top-16 z-[5] flex items-center justify-between bg-neutral-50/95 backdrop-blur border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-center gap-3">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.count }} {{ g.count === 1 ? t('invoice.doc_1') : (g.count < 5 ? t('invoice.doc_2_4') : t('invoice.doc_5plus')) }}</span>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span v-for="t in g.totals_per_currency" :key="t.currency" class="font-mono">
              <span class="text-neutral-500">{{ t.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(t.with_vat, t.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-neutral-500 text-[12px] uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 w-10"></th>
                <th class="text-left px-4 py-2 font-medium w-32">Var. symbol</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('invoice.client_project') }}</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('tri.invoices.job_label') }}</th>
                <th class="text-center px-4 py-2 font-medium">Typ</th>
                <th class="text-center px-4 py-2 font-medium">DUZP / Vystaveno</th>
                <th class="text-center px-4 py-2 font-medium">Splatnost</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="text-center px-4 py-2 font-medium">Stav</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr
                v-for="inv in g.invoices"
                :key="inv.id"
                @click="openInvoice(inv, $event)"
                @auxclick.prevent="openInvoice(inv, $event)"
                class="cursor-pointer hover:bg-neutral-50 transition"
                :class="invoiceRowClass(inv.due_date, inv.status)"
              >
                <td class="px-2 py-2.5 text-center" @click.stop>
                  <input
                    type="checkbox"
                    :checked="selectedIds.includes(inv.id)"
                    @change="toggleSelected(inv.id)"
                    class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  />
                </td>
                <td class="px-4 py-2.5 font-mono text-xs">
                  <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                  <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="font-medium text-neutral-900">{{ inv.client_company_name }}</div>
                  <div v-if="inv.project_name" class="text-xs text-neutral-500 truncate max-w-md">{{ inv.project_name }}</div>
                </td>
                <td class="px-4 py-2.5 text-xs text-neutral-600 max-w-[180px] truncate" @click.stop>
                  <RouterLink
                    v-if="inv.tri_job_id"
                    :to="{ name: 'tri-job-detail', params: { id: inv.tri_job_id } }"
                    class="text-primary-700 hover:underline font-mono"
                  >
                    {{ inv.tri_job_number }}
                  </RouterLink>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ formatDate(inv.tax_date || inv.issue_date) }}
                </td>
                <td class="px-4 py-2.5 text-center text-xs">
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                    {{ formatDate(inv.due_date) }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-right font-mono">
                  {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                </td>
                <td class="px-4 py-2.5 text-center" @click.stop>
                  <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                    {{ statusLabel(inv.status) }}
                  </span>
                  <span v-if="inv.sent_at" class="ml-1 text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                    :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                  <span v-if="inv.reminder_count > 0" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                    :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden bg-surface border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            @click="openInvoice(inv, $event)"
            @auxclick.prevent="openInvoice(inv, $event)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="invoiceRowClass(inv.due_date, inv.status)"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="mt-0.5 w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate">{{ inv.client_company_name }}</div>
                  <div class="font-mono text-sm font-semibold whitespace-nowrap">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                  <div class="truncate">
                    <span class="font-mono">
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                    </span>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ typeLabel(inv.invoice_type) }}</span>
                    <span v-if="inv.project_name" class="text-neutral-400"> · </span>
                    <span v-if="inv.project_name" class="truncate">{{ inv.project_name }}</span>
                    <span v-if="inv.tri_job_number" class="text-neutral-400"> · </span>
                    <RouterLink
                      v-if="inv.tri_job_id"
                      :to="{ name: 'tri-job-detail', params: { id: inv.tri_job_id } }"
                      class="text-primary-700 hover:underline font-mono"
                      @click.stop
                    >
                      {{ inv.tri_job_number }}
                    </RouterLink>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                  <div class="text-xs text-neutral-600 whitespace-nowrap">
                    {{ formatDate(inv.tax_date || inv.issue_date) }}
                    <span class="text-neutral-400"> → </span>
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1 flex-wrap justify-end" @click.stop>
                    <span v-if="inv.sent_at" class="text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                      :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                    <span v-if="inv.reminder_count > 0" class="text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                      :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                    <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ statusLabel(inv.status) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="page < pages" class="text-center mt-3">
        <UiButton size="sm" :loading="loadingMore" :disabled="loadingMore" @click="load(false)">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
        </UiButton>
      </div>
    </div>
  </div>
</template>

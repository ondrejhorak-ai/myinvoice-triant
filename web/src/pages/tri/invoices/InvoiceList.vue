<script setup lang="ts">
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { type MonthGroup, type InvoiceListItem } from '@/api/invoices'

import { triApi, type TriJob } from '@/api/tri'
import { formatMoney, formatDate, formatMonth, statusLabel, typeLabel, isOverdue } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useRowLink } from '@/composables/useRowLink'

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

import Modal from '@/components/ui/Modal.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import { apiErrorMessage } from '@/api/errors'
import UiInput from '@/components/ui/UiInput.vue'

const { t, tm, rt } = useI18n()

const auth = useAuthStore()

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/tri/invoices/new') })

const router = useRouter()
const route = useRoute()

const groups = ref<MonthGroup[]>([])
const invoiceView = ref<'all' | 'counter'>('all')
const counterRows = computed(() => groups.value.flatMap((g) => g.invoices))
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

const error = ref('')
const filtersOpen = ref(false)
const draft = ref<Record<string, any>>({})
let request = 0
let searchTimeout: ReturnType<typeof setTimeout> | null = null
function openFilters() {
  draft.value = { status: statusFilter.value, type: typeFilter.value, client: clientFilter.value, job: jobFilter.value, year: yearFilter.value, month: monthFilter.value, from: dateFrom.value, to: dateTo.value, currency: currencyFilter.value, overdue: overdueOnly.value, unpaid: unpaidOnly.value }
  filtersOpen.value = true
}
function applyFilters(clear = false) {
  const d = clear ? { year: '', month: '', client: '', job: '' } : draft.value
  statusFilter.value = d.status || ''; typeFilter.value = d.type || ''; clientFilter.value = d.client ?? ''; jobFilter.value = d.job ?? ''
  yearFilter.value = d.year ?? ''; monthFilter.value = d.month ?? ''; dateFrom.value = d.from || ''; dateTo.value = d.to || ''
  currencyFilter.value = d.currency || ''; overdueOnly.value = !!d.overdue; unpaidOnly.value = !!d.unpaid
  filtersOpen.value = false
}
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'status', active: () => !!statusFilter.value, label: () => `${t('invoice.status_label')}: ${statusFilter.value.split(',').map(statusLabel).join(', ')}`, clear: () => { statusFilter.value = '' } },
  { id: 'type', active: () => !!typeFilter.value, label: () => `${t('invoice.type')}: ${typeFilter.value.split(',').map(typeLabel).join(', ')}`, clear: () => { typeFilter.value = '' } },
  { id: 'client', active: () => clientFilter.value !== '', label: () => `${t('invoice.client')}: ${clients.value.find(c => c.id === clientFilter.value)?.company_name ?? '#' + clientFilter.value}`, clear: () => { clientFilter.value = '' } },
  { id: 'job', active: () => jobFilter.value !== '', label: () => `${t('tri.invoices.job_label')}: ${triJobs.value.find(j => j.id === jobFilter.value)?.number ?? '#' + jobFilter.value}`, clear: () => { jobFilter.value = '' } },
  { id: 'year', active: () => yearFilter.value !== '' && !dateFrom.value && !dateTo.value, label: () => `${t('office.year')}: ${yearFilter.value}`, clear: () => { yearFilter.value = ''; monthFilter.value = '' } },
  { id: 'month', active: () => monthFilter.value !== '' && yearFilter.value !== '' && !dateFrom.value && !dateTo.value, label: () => `${t('invoice.month_filter')}: ${monthOptions.value[Number(monthFilter.value) - 1]}`, clear: () => { monthFilter.value = '' } },
  { id: 'range', active: () => !!dateFrom.value || !!dateTo.value, label: () => [dateFrom.value ? `${t('office.date_from')}: ${formatDate(dateFrom.value)}` : '', dateTo.value ? `${t('office.date_to')}: ${formatDate(dateTo.value)}` : ''].filter(Boolean).join(' – '), clear: () => { dateFrom.value = ''; dateTo.value = '' } },
  { id: 'currency', active: () => !!currencyFilter.value, label: () => `${t('common.currency')}: ${currencyFilter.value}`, clear: () => { currencyFilter.value = '' } },
  { id: 'overdue', active: () => overdueOnly.value, label: () => t('invoice.overdue_only'), clear: () => { overdueOnly.value = false } },
  { id: 'unpaid', active: () => unpaidOnly.value, label: () => t('invoice.unpaid_only'), clear: () => { unpaidOnly.value = false } },
])
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
  if (!reset && (loading.value || loadingMore.value)) return
  const seq = ++request
  const next = reset ? 1 : page.value + 1
  error.value = ''
  if (reset) {
    loading.value = true
    groups.value = []

  } else {
    loadingMore.value = true

  }
  try {
    const params: Record<string, string | number> = { page: next }
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
    if (invoiceView.value === 'counter') params['filter[prodejna]'] = '1'

    const result = await triApi.invoices.list(params)
    if (seq !== request) return
    page.value = next
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } catch (e) {
    if (seq === request) error.value = apiErrorMessage(e,t('common.error'))
  } finally {
    if (seq !== request) return
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
  invoiceView.value = q.view === 'counter' ? 'counter' : 'all'
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
  if (invoiceView.value === 'counter') q.view = 'counter'
  router.replace({ query: q })
}

function setInvoiceView(next: 'all' | 'counter') {
  invoiceView.value = next
  if (next === 'counter') {
    statusFilter.value = ''
    typeFilter.value = ''
    unpaidOnly.value = false
    overdueOnly.value = false
    jobFilter.value = ''
  }
  syncFiltersToUrl()
  void load(true)
}

watch([statusFilter, typeFilter, clientFilter, jobFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, currencyFilter], () => {
  syncFiltersToUrl()
  load(true)
})
// Když se vyčistí rok (vše/range), automaticky zrušit i měsíční filtr.
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })
watch([dateFrom, dateTo], ([f, to]) => { if (f || to) { yearFilter.value = ''; monthFilter.value = '' } })
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
onBeforeUnmount(() => { if (searchTimeout) clearTimeout(searchTimeout) })
</script>

<template>
<div>
  <UiPageHeader :title="t('tri.invoices.title')" :subtitle="invoiceView === 'counter' ? t('tri.invoices.counter_subtitle') : t('office.invoices_subtitle')"><template #actions><UiButton v-if="auth.canWrite" to="/tri/invoices/new">{{ t('tri.invoices.new') }}</UiButton></template></UiPageHeader>
  <div class="flex flex-wrap gap-2 mb-4">
    <UiButton :variant="invoiceView === 'all' ? 'primary' : 'secondary'" @click="setInvoiceView('all')">{{ t('tri.invoices.view_all') }}</UiButton>
    <UiButton :variant="invoiceView === 'counter' ? 'primary' : 'secondary'" @click="setInvoiceView('counter')">{{ t('tri.invoices.view_counter') }}</UiButton>
  </div>
  <p v-if="invoiceView === 'counter'" class="mb-4 border border-neutral-200 bg-primary-50 px-4 py-3 text-sm text-neutral-700">{{ t('tri.invoices.counter_notice') }}</p>
  <div class="office-filters"><UiInput v-model="search" type="search" class="w-full sm:max-w-[520px]" :label="t('common.search')" :placeholder="t('invoice.search_placeholder')" /><OfficeFilterButton :count="conditions.length" :expanded="filtersOpen" @click="openFilters" /></div>
  <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />
  <div v-if="error" class="office-error" role="alert">{{ error }} <UiButton variant="secondary" @click="load(true)">{{ t('office.retry') }}</UiButton></div>
  <TableSkeleton v-if="loading" :rows="8" :cols="8" />
  <EmptyState v-else-if="!groups.length && !error" :title="t('office.no_invoices')"><UiButton variant="secondary" @click="applyFilters(true)">{{ t('office.clear_filters') }}</UiButton></EmptyState>
  <template v-else-if="!error && invoiceView === 'counter'">
    <div class="border border-neutral-200 bg-surface overflow-x-auto">
      <table class="w-full">
        <thead><tr>
          <th class="text-left px-4">{{ t('invoice.varsymbol') }}</th>
          <th class="text-left px-4">{{ t('invoice.client') }}</th>
          <th class="text-left px-4">{{ t('tri.invoices.paid_on') }}</th>
          <th class="text-left px-4">{{ t('invoice.status_label') }}</th>
          <th class="text-right px-4">{{ t('tri.invoices.total') }}</th>
        </tr></thead>
        <tbody class="divide-y divide-neutral-200">
          <tr v-for="inv in counterRows" :key="inv.id" class="cursor-pointer hover:bg-neutral-50" @click="openInvoice(inv,$event)">
            <td class="px-4"><RouterLink :to="`/tri/invoices/${inv.id}`" class="text-primary-700" @click.stop>{{ inv.varsymbol || `#${inv.id}` }}</RouterLink></td>
            <td class="px-4">{{ inv.client_company_name || '—' }}</td>
            <td class="px-4 whitespace-nowrap">{{ formatDate(inv.paid_at) }}</td>
            <td class="px-4"><UiBadge variant="paid">{{ statusLabel(inv.status) }}</UiBadge></td>
            <td class="px-4 text-right font-semibold whitespace-nowrap">{{ formatMoney(inv.total_with_vat, inv.currency) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="page < pages" class="flex justify-center py-4"><UiButton variant="secondary" :loading="loadingMore" @click="load(false)">{{ t('office.more') }}</UiButton></div>
  </template>
  <template v-else-if="!error">
    <p class="text-xs text-neutral-500 mb-6">{{ t('office.loaded', { n: loadedCount, total }) }}</p>
    <section v-for="g in groups" :key="g.month" class="mb-6 border border-neutral-200 bg-surface">
      <header class="sticky top-14 lg:top-0 z-[5] px-4 py-4 flex flex-wrap justify-between items-center gap-3 bg-primary-50">
        <div class="flex gap-4 items-center"><h2>{{ formatMonth(g.month) }}</h2><span class="text-xs text-neutral-500">{{ g.count }}</span></div>
        <div class="flex flex-wrap gap-4 text-primary-700 text-sm"><span v-for="tot in g.totals_per_currency" :key="tot.currency">{{ formatMoney(tot.with_vat,tot.currency) }}</span></div>
      </header>
      <div class="overflow-x-auto"><table class="w-full"><thead><tr><th class="text-left px-4">{{ t('invoice.varsymbol') }}</th><th class="text-left px-4">{{ t('invoice.client') }}</th><th class="text-left px-4">{{ t('tri.invoices.job_label') }}</th><th class="text-left px-4">{{ t('invoice.type') }}</th><th class="text-left px-4">{{ t('invoice.issue_date') }}</th><th class="text-left px-4">{{ t('invoice.due_date') }}</th><th class="text-left px-4">{{ t('invoice.status_label') }}</th><th class="text-right px-4">{{ t('invoice.amount_to_pay') }}</th></tr></thead>
        <tbody class="divide-y divide-neutral-200"><tr v-for="inv in g.invoices" :key="inv.id" class="cursor-pointer hover:bg-neutral-50" @click="openInvoice(inv,$event)" @auxclick.prevent="openInvoice(inv,$event)">
          <td class="px-4 text-primary-700 whitespace-nowrap"><RouterLink :to="`/tri/invoices/${inv.id}`" @click.stop>{{ inv.varsymbol || `${t('status.draft')} #${inv.id}` }}</RouterLink></td>
          <td class="px-4 min-w-[160px]">{{ inv.client_company_name || '—' }}</td>
          <td class="px-4"><RouterLink v-if="inv.tri_job_id" :to="`/tri/jobs/${inv.tri_job_id}`" class="text-primary-700" @click.stop>{{ inv.tri_job_number }}</RouterLink><span v-else>—</span></td>
          <td class="px-4">{{ typeLabel(inv.invoice_type) }}</td><td class="px-4 whitespace-nowrap">{{ formatDate(inv.issue_date) }}</td><td class="px-4 whitespace-nowrap" :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-600' : ''">{{ formatDate(inv.due_date) }}</td>
          <td class="px-4"><UiBadge :variant="inv.status === 'paid' ? 'paid' : inv.status === 'reminded' ? 'warning' : inv.status === 'cancelled' ? 'danger' : inv.status === 'draft' ? 'draft' : 'primary'">{{ statusLabel(inv.status) }}</UiBadge></td>
          <td class="px-4 text-right font-semibold whitespace-nowrap">{{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}</td>
        </tr></tbody>
      </table></div>
    </section>
    <div v-if="page < pages" class="flex justify-center py-2"><UiButton variant="secondary" :loading="loadingMore" @click="load(false)">{{ t('office.more') }}</UiButton></div>
  </template>
  <Modal v-if="filtersOpen" :title="t('office.filters')" @close="filtersOpen=false">
    <div class="grid sm:grid-cols-2 gap-4 office-page">
      <label class="grid gap-1">{{ t('invoice.status_label') }}<select v-model="draft.status" class="border px-3 bg-surface"><option value="">{{ t('common.all') }}</option><option v-for="v in ['draft','issued','sent','reminded','paid','cancelled']" :key="v" :value="v">{{ t(`status.${v}`) }}</option></select></label>
      <label class="grid gap-1">{{ t('invoice.type') }}<select v-model="draft.type" class="border px-3 bg-surface"><option value="">{{ t('common.all') }}</option><option v-for="v in ['invoice','proforma','credit_note']" :key="v" :value="v">{{ typeLabel(v) }}</option></select></label>
      <label class="grid gap-1">{{ t('invoice.client') }}<SearchableSelect :model-value="draft.client || null" @update:model-value="draft.client = $event || ''" :options="clients.map(c=>({value:c.id,label:c.company_name}))" /></label>
      <label class="grid gap-1">{{ t('tri.invoices.job_label') }}<SearchableSelect :model-value="draft.job || null" @update:model-value="draft.job = $event || ''" :options="triJobs.map(j=>({value:j.id,label:j.number+' — '+j.title}))" /></label>
      <label class="grid gap-1">{{ t('common.currency') }}<select v-model="draft.currency" class="border px-3 bg-surface"><option value="">{{ t('common.all') }}</option><option v-for="c in currencies" :key="c.code" :value="c.code">{{ c.code }}</option></select></label>
      <label class="grid gap-1">{{ t('office.year') }}<select v-model="draft.year" :disabled="!!draft.from || !!draft.to" class="border px-3 bg-surface"><option value="">{{ t('common.all') }}</option><option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option></select></label>
      <label class="grid gap-1">{{ t('invoice.month_filter') }}<select v-model="draft.month" :disabled="!!draft.from || !!draft.to || draft.year === ''" class="border px-3 bg-surface"><option value="">{{ t('common.all') }}</option><option v-for="(m,i) in monthOptions" :key="i" :value="i+1">{{ m }}</option></select></label>
      <UiInput v-model="draft.from" type="date" :label="t('office.date_from')" /><UiInput v-model="draft.to" type="date" :label="t('office.date_to')" />
      <label class="flex items-center gap-2"><input v-model="draft.overdue" type="checkbox" />{{ t('invoice.overdue_only') }}</label><label class="flex items-center gap-2"><input v-model="draft.unpaid" type="checkbox" />{{ t('invoice.unpaid_only') }}</label>
    </div>
    <template #footer><UiButton variant="secondary" @click="applyFilters(true)">{{ t('office.clear_filters') }}</UiButton><UiButton @click="applyFilters()">{{ t('office.apply_filters') }}</UiButton></template>
  </Modal>
</div>
</template>

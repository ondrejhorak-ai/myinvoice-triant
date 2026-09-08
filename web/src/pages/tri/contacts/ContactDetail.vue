<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client, type BankLookupResult } from '@/api/clients'
import { triApi } from '@/api/tri'
import { type InvoiceListItem } from '@/api/invoices'
import { triInvoicesApi } from '@/api/triInvoices'
import { formatMoney, formatDate, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass } from '@/composables/useFormat'
import MonthlyRevenueChart from '@/components/charts/MonthlyRevenueChart.vue'
import TopProjectsBarChart from '@/components/charts/TopProjectsBarChart.vue'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import ClientTagEditor from '@/components/tri/ClientTagEditor.vue'
import ClientIncompleteBanner from '@/components/clients/ClientIncompleteBanner.vue'
import { clientMissingAddress, clientMissingEmail } from '@/utils/clientCompleteness'
import UiButton from '@/components/ui/UiButton.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiTable from '@/components/ui/UiTable.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const route = useRoute()
const router = useRouter()

const client = ref<Client | null>(null)
const myuctoBase = ref('')
const myuctoHref = computed(() => {
  if (!auth.isAdmin || !client.value?.myucto_id || !myuctoBase.value) return ''
  return `${myuctoBase.value.replace(/\/$/, '')}/clients/${client.value.myucto_id}`
})
const loading = ref(true)
const invoices = ref<InvoiceListItem[]>([])
const invoicesLoading = ref(false)
const invoicesLoadingMore = ref(false)
const invoicesTotal = ref(0)
const invoicesPage = ref(1)
const invoicesPages = ref(1)

// Detaily plátce DPH (registr plátců DPH / CRPDPH) — načítají se až na vyžádání.
const vatInfoOpen = ref(false)
const vatInfoLoading = ref(false)
const vatInfo = ref<BankLookupResult | null>(null)
const vatInfoError = ref('')

async function loadVatPayerDetails() {
  const dic = (client.value?.dic || '').replace(/\D/g, '')
  if (!dic) return
  vatInfoOpen.value = true
  vatInfoLoading.value = true
  vatInfoError.value = ''
  try {
    vatInfo.value = await clientsApi.lookupBank(dic)
  } catch (e: any) {
    vatInfoError.value = e?.response?.data?.error?.message || t('client.vat_payer_details_failed')
  } finally {
    vatInfoLoading.value = false
  }
}

// Aggregace přijatých faktur per měsíc / rok (paralel se statistikami vystavených).
// Server zatím nevrací aggregated dataset, takže computované client-side z purchaseInvoices.
//
// Multi-currency: pokud má dodavatel faktury ve více měnách (např. EUR + USD),
// přepočítáme vše na CZK přes pi.exchange_rate (CNB k DUZP — fixovaný na faktuře).
// `purchaseIsMultiCurrency` rozhodne, zda graf/totals zobrazit v CZK nebo původní měně.
const purchaseCurrencies = computed(() => {
  const s = new Set<string>()
  for (const r of client.value?.costs_by_month ?? []) {
    if (r.currency) s.add(r.currency)
  }
  return Array.from(s)
})
const purchaseIsMultiCurrency = computed(() => purchaseCurrencies.value.length > 1)
const purchaseDisplayCurrency = computed(() =>
  purchaseIsMultiCurrency.value ? 'CZK' : (purchaseCurrencies.value[0] || 'CZK')
)

function formatPaymentDue(c: Client): string {
  if (c.payment_due_default == null) return t('client.due_default')
  if (c.payment_due_unit === 'month') {
    return c.payment_due_default === 1
      ? t('client.payment_due_preset_month')
      : `${c.payment_due_default}× ${t('client.payment_due_preset_month').toLowerCase()}`
  }
  return t('client.due_days_n', { n: c.payment_due_default })
}

// Náklady čteme ze server-side agregace (client.costs_by_month / costs_by_year) — nezávislé na paginaci
// listu. Multi-currency: v multi režimu sloučíme do CZK přes total_czk, single-ccy zachová per měnu.
const purchaseByMonth = computed(() => {
  const rows = client.value?.costs_by_month ?? []
  if (purchaseIsMultiCurrency.value) {
    const m = new Map<string, number>()
    for (const r of rows) m.set(r.month, (m.get(r.month) ?? 0) + r.total_czk)
    return Array.from(m.entries())
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([month, total]) => ({ month, total, count: 0, currency: 'CZK' }))
  }
  return rows
    .slice()
    .sort((a, b) => a.month.localeCompare(b.month))
    .map(r => ({ month: r.month, total: r.total, count: 0, currency: r.currency }))
})

const purchaseByYear = computed(() => {
  const rows = client.value?.costs_by_year ?? []
  if (purchaseIsMultiCurrency.value) {
    const m = new Map<number, { total: number, count: number }>()
    for (const r of rows) {
      const v = m.get(r.year) ?? { total: 0, count: 0 }
      v.total += r.total_czk
      v.count += r.count
      m.set(r.year, v)
    }
    return Array.from(m.entries())
      .sort(([a], [b]) => b - a)
      .map(([year, v]) => ({ year: String(year), currency: 'CZK', total: v.total, count: v.count }))
  }
  return rows
    .slice()
    .sort((a, b) => b.year - a.year)
    .map(r => ({ year: String(r.year), currency: r.currency, total: r.total, count: r.count }))
})

const purchaseMonthlyChart = computed(() => ({
  labels: purchaseByMonth.value.map(r => r.month),
  values: purchaseByMonth.value.map(r => r.total),
}))

const purchaseTotalsByCurrency = computed(() => {
  const m = new Map<string, number>()
  for (const r of purchaseByYear.value) {
    m.set(r.currency, (m.get(r.currency) ?? 0) + r.total)
  }
  return Array.from(m.entries()).map(([currency, total]) => ({ currency, total }))
})

// Multi-currency revenue: pokud má klient vystavené faktury ve více měnách (např. EUR+USD),
// zobrazíme graf/tabulky v CZK (přepočet přes i.exchange_rate fixovaný k DUZP, dodaný backendem
// jako *_czk fieldy). Pro single-currency klienta zachováme původní měnu.
const revenueCurrencies = computed(() => {
  const s = new Set<string>()
  for (const r of client.value?.revenue_by_month ?? []) s.add(r.currency)
  return Array.from(s)
})
const revenueIsMultiCurrency = computed(() => revenueCurrencies.value.length > 1)
const primaryCurrency = computed(() => {
  if (revenueIsMultiCurrency.value) return 'CZK'
  // Single-ccy: nejčastější v datech, fallback default
  const tally: Record<string, number> = {}
  for (const r of client.value?.revenue_by_month ?? []) tally[r.currency] = (tally[r.currency] ?? 0) + r.total
  const top = Object.entries(tally).sort((a, b) => b[1] - a[1])[0]
  return top?.[0] || client.value?.currency_default || 'CZK'
})
const overdueAny = computed(() => (client.value?.unpaid_summary ?? []).some(u => u.overdue_count > 0))

// Single-ccy: zobrazujeme jednotlivé řádky per měna jako dnes (BC).
// Multi-ccy: agregujeme přes všechny měny do CZK přes total_czk.
const monthlyChart = computed(() => {
  if (revenueIsMultiCurrency.value) {
    // Sumace všech měn na CZK per měsíc
    const m = new Map<string, number>()
    for (const r of client.value?.revenue_by_month ?? []) {
      m.set(r.month, (m.get(r.month) ?? 0) + r.total_czk)
    }
    const sorted = Array.from(m.entries()).sort(([a], [b]) => a.localeCompare(b))
    return { labels: sorted.map(([k]) => k), values: sorted.map(([, v]) => v) }
  }
  const data = (client.value?.revenue_by_month ?? []).filter(r => r.currency === primaryCurrency.value)
  return { labels: data.map(r => r.month), values: data.map(r => r.total) }
})

const yearTable = computed(() => {
  // Multi-ccy: sloučí roky do jednoho řádku v CZK.
  if (revenueIsMultiCurrency.value) {
    const m = new Map<number, { total: number, count: number }>()
    for (const r of client.value?.revenue_by_year ?? []) {
      const v = m.get(r.year) ?? { total: 0, count: 0 }
      v.total += r.total_czk
      v.count += r.count
      m.set(r.year, v)
    }
    return Array.from(m.entries())
      .sort(([a], [b]) => b - a)
      .map(([year, v]) => ({ year, currency: 'CZK', total: v.total, count: v.count }))
  }
  return client.value?.revenue_by_year ?? []
})

const projectsChart = computed(() => {
  if (revenueIsMultiCurrency.value) {
    // Sloučí stejný projekt z různých měn → součet v CZK.
    const m = new Map<string, number>()
    for (const r of client.value?.revenue_by_project ?? []) {
      if (r.total_czk <= 0) continue
      const key = r.project_name ?? t('client.no_project')
      m.set(key, (m.get(key) ?? 0) + r.total_czk)
    }
    const entries = Array.from(m.entries()).sort(([, a], [, b]) => b - a)
    return { labels: entries.map(([k]) => k), values: entries.map(([, v]) => v) }
  }
  const data = (client.value?.revenue_by_project ?? []).filter(r => r.currency === primaryCurrency.value && r.total > 0)
  return {
    labels: data.map(r => r.project_name ?? t('client.no_project')),
    values: data.map(r => r.total),
  }
})

const projectsTable = computed(() => {
  // Single-ccy: per-currency řádky jako dnes.
  if (!revenueIsMultiCurrency.value) {
    return (client.value?.revenue_by_project ?? []).filter(r => r.total !== 0)
  }
  // Multi-ccy: sloučí projekty z různých měn (např. EUR + USD řádek stejného projektu) do CZK.
  const m = new Map<string, { project_id: number | null; project_name: string | null; total: number; count: number }>()
  for (const r of client.value?.revenue_by_project ?? []) {
    if (r.total_czk === 0) continue
    const key = `${r.project_id ?? 'none'}|${r.project_name ?? ''}`
    const v = m.get(key) ?? { project_id: r.project_id, project_name: r.project_name, total: 0, count: 0 }
    v.total += r.total_czk
    v.count += r.count
    m.set(key, v)
  }
  return Array.from(m.values())
    .sort((a, b) => b.total - a.total)
    .map(v => ({ ...v, currency: 'CZK' }))
})

// Smazat lze jen klienta bez navázaných faktur a zakázek (jinak archivovat)
const canDelete = computed(() => {
  if (!client.value) return false
  const projects = client.value.projects?.length ?? 0
  const invoices = client.value.invoices_count ?? 0
  return projects === 0 && invoices === 0
})

async function load() {
  const id = Number(route.params.id)
  loading.value = true
  invoicesLoading.value = true
  invoicesPage.value = 1
  try {
    const [c, grouped] = await Promise.all([
      clientsApi.get(id),
      triInvoicesApi.list({ 'filter[client_id]': id, page: 1 }),
    ])
    client.value = c
    invoices.value = grouped.data.flatMap(g => g.invoices)
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    loading.value = false
    invoicesLoading.value = false
  }
}

async function loadMoreInvoices() {
  if (!client.value) return
  invoicesLoadingMore.value = true
  invoicesPage.value++
  try {
    const grouped = await triInvoicesApi.list({ 'filter[client_id]': client.value.id, page: invoicesPage.value })
    invoices.value.push(...grouped.data.flatMap(g => g.invoices))
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    invoicesLoadingMore.value = false
  }
}

onMounted(async () => {
  if (auth.isAdmin) {
    try {
      myuctoBase.value = (await triApi.myucto.status()).public_url
    } catch { /* admin-only endpoint */ }
  }
  await load()
})

async function archive() {
  if (!client.value) return
  if (!confirm(t('client.archive_confirm'))) return
  await triApi.contacts.archive(client.value.id)
  router.push('/tri/contacts')
}

async function unarchive() {
  if (!client.value) return
  await triApi.contacts.unarchive(client.value.id)
  await load()
}

async function deleteClient() {
  if (!client.value) return
  if (!confirm(t('client.archive_confirm'))) return
  try {
    await triApi.contacts.archive(client.value.id)
    router.push('/tri/contacts')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('client.delete_failed'))
  }
}
</script>

<template>
  <CardSkeleton v-if="loading" :blocks="3" />

  <div v-else-if="client" class="space-y-6">
    <div>
      <RouterLink to="/tri/contacts" class="text-sm text-neutral-500 hover:text-neutral-900">{{ t('tri.contacts.back_to_list') }}</RouterLink>
      <UiPageHeader :title="client.company_name">
        <template #below>
          <div class="mt-3 max-w-md">
            <ClientTagEditor :client-id="client.id" :readonly="!auth.canWrite" />
          </div>
          <div class="text-sm text-neutral-500 mt-1.5 flex flex-wrap items-center gap-x-2">
            <span v-if="client.ic"><span>{{ t('common.ic') }}</span> <span class="font-mono">{{ client.ic }}</span></span>
            <span v-if="client.dic">· <span>{{ t('common.dic') }}</span> <span class="font-mono">{{ client.dic }}</span></span>
            <UiBadge v-if="client.archived_at" variant="draft">{{ t('common.archived') }}</UiBadge>
          </div>
        </template>
        <template #actions>
          <UiButton v-if="auth.canWrite" :to="`/tri/contacts/${client.id}/edit`" variant="secondary" size="sm">
            <template #icon>
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            </template>
            {{ t('common.edit') }}
          </UiButton>
          <UiButton v-if="myuctoHref" :href="myuctoHref" variant="outline" size="sm">
            {{ t('tri.contacts.open_in_myucto') }}
          </UiButton>
          <UiButton v-if="client.dic" variant="outline" size="sm" :loading="vatInfoLoading" :disabled="vatInfoLoading" @click="loadVatPayerDetails">
            {{ vatInfoLoading ? t('common.loading') : t('client.vat_payer_details') }}
          </UiButton>
          <UiButton v-if="!client.archived_at && auth.canWrite" variant="outline" size="sm" @click="archive">
            {{ t('common.archive') }}
          </UiButton>
          <UiButton v-else-if="auth.canWrite" variant="outline" size="sm" @click="unarchive">
            {{ t('common.restore') }}
          </UiButton>
          <UiButton v-if="(canDelete) && auth.canWrite" variant="danger" size="sm" @click="deleteClient">
            {{ t('common.delete') }}
          </UiButton>
        </template>
      </UiPageHeader>
    </div>

    <ClientIncompleteBanner
      v-if="client"
      :client="client"
      :edit-to="`/tri/contacts/${client.id}/edit`"
    />

    <!-- Detaily plátce DPH (na vyžádání z registru plátců DPH / MFČR) -->
    <UiCard v-if="vatInfoOpen" padding>
      <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('client.vat_payer_details') }}</h3>
        <button @click="vatInfoOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-sm leading-none">✕</button>
      </div>
      <div v-if="vatInfoLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="vatInfoError" class="text-sm text-danger-500">{{ vatInfoError }}</div>
      <template v-else-if="vatInfo">
        <div v-if="vatInfo.source === 'error'" class="text-sm text-warning-600">{{ t('client.vat_payer_unavailable') }}</div>
        <div v-else-if="!vatInfo.found" class="text-sm text-neutral-600">{{ t('client.vat_payer_not_registered') }}</div>
        <div v-else class="space-y-3 text-sm">
          <div class="flex items-center gap-2">
            <span class="text-neutral-500">{{ t('client.vat_payer_reliability') }}:</span>
            <span v-if="vatInfo.unreliable === true" class="px-2 py-0.5 rounded bg-danger-50 text-danger-600 font-medium">{{ t('client.vat_payer_unreliable') }}</span>
            <span v-else-if="vatInfo.unreliable === false" class="px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_reliable') }}</span>
            <span v-else class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t('client.vat_payer_unknown') }}</span>
          </div>
          <div>
            <div class="text-neutral-500 mb-1">{{ t('client.vat_payer_accounts') }}:</div>
            <ul v-if="vatInfo.accounts.length" class="space-y-1">
              <li v-for="(a, i) in vatInfo.accounts" :key="i" class="font-mono text-neutral-900">{{ a.display }}</li>
            </ul>
            <div v-else class="text-neutral-500">{{ t('client.vat_payer_no_accounts') }}</div>
          </div>
          <p class="text-xs text-neutral-400">{{ t('client.vat_payer_source') }}</p>
        </div>
      </template>
    </UiCard>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <!-- Kontakt -->
      <UiCard padding>
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('client.section_contact') }}</h3>
        <dl class="space-y-2 text-sm">
          <div>
            <dt class="text-neutral-500">{{ t('client.email') }}</dt>
            <dd class="text-neutral-900">{{ clientMissingEmail(client) ? t('client.missing_value') : client.main_email }}</dd>
          </div>
          <div v-if="client.phone">
            <dt class="text-neutral-500">{{ t('client.telephone') }}</dt>
            <dd class="text-neutral-900 font-mono">{{ client.phone }}</dd>
          </div>
        </dl>
      </UiCard>

      <!-- Adresa -->
      <UiCard padding>
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('client.section_address') }}</h3>
        <div class="text-sm text-neutral-900 leading-relaxed">
          <template v-if="clientMissingAddress(client)">
            <span class="text-neutral-400">{{ t('client.missing_value') }}</span>
          </template>
          <template v-else>
            {{ client.street }}<br />
            {{ client.zip }} {{ client.city }}<br />
            {{ client.country_iso2 }}
          </template>
        </div>
      </UiCard>

      <!-- Nastavení -->
      <UiCard padding>
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('nav.settings') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('client.language_label') }}</dt><dd class="font-mono">{{ client.language.toUpperCase() }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.currency') }}</dt><dd class="font-mono">{{ client.currency_default }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('client.due_label') }}</dt><dd>{{ formatPaymentDue(client) }}</dd></div>
          <div v-if="client.hourly_rate > 0" class="flex justify-between"><dt class="text-neutral-500">{{ t('client.hourly_rate') }}</dt><dd class="font-mono">{{ client.hourly_rate.toLocaleString('cs') }} {{ client.currency_default }}/h</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('client.rc_label') }}</dt><dd>{{ client.reverse_charge ? t('client.yes_short') : t('client.no_short') }}</dd></div>
        </dl>
      </UiCard>
    </div>

    <!-- KPI: nezaplaceno + po splatnosti -->
    <div v-if="(client.unpaid_summary?.length ?? 0) > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('client.unpaid') }}</h3>
        <div class="space-y-1">
          <div v-for="u in client.unpaid_summary || []" :key="`u-${u.currency}`" class="flex items-baseline justify-between">
            <span class="text-2xl font-semibold font-mono text-neutral-900">{{ formatMoney(u.unpaid_total, u.currency) }}</span>
            <span class="text-xs text-neutral-500 ml-3 whitespace-nowrap">{{ t('client.n_invoices', { n: u.unpaid_count }) }}</span>
          </div>
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs" :class="overdueAny ? 'border-danger-500/40' : ''">
        <h3 class="text-sm font-semibold uppercase tracking-wide mb-3" :class="overdueAny ? 'text-danger-500' : 'text-neutral-500'">{{ t('client.overdue') }}</h3>
        <div class="space-y-1">
          <div v-for="u in client.unpaid_summary || []" :key="`o-${u.currency}`" class="flex items-baseline justify-between">
            <span class="text-2xl font-semibold font-mono" :class="u.overdue_total > 0 ? 'text-danger-500' : 'text-neutral-400'">{{ formatMoney(u.overdue_total, u.currency) }}</span>
            <span class="text-xs ml-3 whitespace-nowrap" :class="u.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">{{ t('client.n_invoices', { n: u.overdue_count }) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Obrat: graf po měsících + sumace po letech -->
    <div v-if="(client.revenue_by_month?.length ?? 0) > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2 bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('client.revenue_by_month') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ primaryCurrency }}<span v-if="revenueIsMultiCurrency" class="ml-1 text-neutral-400 normal-case">({{ t('client.converted_from', { ccys: revenueCurrencies.join(', ') }) }})</span></span>
        </div>
        <MonthlyRevenueChart :labels="monthlyChart.labels" :values="monthlyChart.values" :currency="primaryCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('client.revenue_by_year') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in yearTable" :key="`${r.year}-${r.currency}`">
              <td class="py-2 text-neutral-900 font-medium">{{ r.year }}</td>
              <td class="py-2 text-right font-mono text-neutral-900">{{ formatMoney(r.total, r.currency) }}</td>
              <td class="py-2 pl-3 text-right text-xs text-neutral-500 whitespace-nowrap">{{ t('client.year_invoices', { n: r.count }) }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Náklady (přijaté faktury) — graf po měsících + sumace po letech -->
    <div v-if="purchaseByMonth.length > 0" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2 bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('client.costs_by_month') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ purchaseDisplayCurrency }}<span v-if="purchaseIsMultiCurrency" class="ml-1 text-neutral-400 normal-case">({{ t('client.converted_from', { ccys: purchaseCurrencies.join(', ') }) }})</span></span>
        </div>
        <MonthlyRevenueChart :labels="purchaseMonthlyChart.labels" :values="purchaseMonthlyChart.values" :currency="purchaseDisplayCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('client.costs_by_year') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in purchaseByYear" :key="`${r.year}-${r.currency}`">
              <td class="py-2 text-neutral-900 font-medium">{{ r.year }}</td>
              <td class="py-2 text-right font-mono text-neutral-900">{{ formatMoney(r.total, r.currency) }}</td>
              <td class="py-2 pl-3 text-right text-xs text-neutral-500 whitespace-nowrap">{{ t('client.year_invoices', { n: r.count }) }}</td>
            </tr>
            <tr v-for="t in purchaseTotalsByCurrency" :key="`total-${t.currency}`" class="font-semibold border-t-2 border-neutral-200 pt-2">
              <td class="py-2 text-neutral-700">{{ $t('client.total') }}</td>
              <td class="py-2 text-right font-mono text-neutral-700">{{ formatMoney(t.total, t.currency) }}</td>
              <td></td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Obrat podle zakázek — graf + tabulka -->
    <div v-if="projectsTable.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('client.revenue_by_project') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ primaryCurrency }}</span>
        </div>
        <TopProjectsBarChart :labels="projectsChart.labels" :values="projectsChart.values" :currency="primaryCurrency" />
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-xs">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-3">{{ t('client.revenue_by_project_table') }}</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left py-2 font-medium">{{ t('project.name') }}</th>
                <th class="text-right py-2 font-medium">{{ t('common.revenue') }}</th>
                <th class="text-right py-2 pl-3 font-medium whitespace-nowrap">{{ t('client.invoices_short') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in projectsTable" :key="`p-${r.project_id ?? 'none'}-${r.currency}`">
                <td class="py-2 truncate max-w-[220px]">
                  <span v-if="r.project_id" class="text-neutral-700">
                    {{ r.project_name }}
                  </span>
                  <span v-else class="text-neutral-400 italic">{{ t('client.no_project') }}</span>
                </td>
                <td class="py-2 text-right font-mono">{{ formatMoney(r.total, r.currency) }}</td>
                <td class="py-2 pl-3 text-right text-xs text-neutral-500">{{ r.count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Vystavené faktury — visible pokud is_customer NEBO existují vystavené faktury -->
    <UiCard v-if="client.is_customer !== false || invoices.length > 0">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold text-neutral-900">{{ t('client.issued_invoices') }} <span v-if="invoicesTotal" class="text-neutral-400 font-normal">({{ invoicesTotal }})</span></h3>
        <UiButton v-if="auth.canWrite" :to="`/tri/invoices/new?client_id=${client.id}`" size="sm">
          {{ t('invoice.new') }}
        </UiButton>
      </div>
      <TableSkeleton v-if="invoicesLoading" :rows="4" :cols="6" />
      <EmptyState v-else-if="!invoices.length" compact :title="t('common.no_data')" />
      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.type') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.issue_date') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.due_date') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('invoice.amount_to_pay') }}</th>
              <th class="text-center px-4 py-2.5 font-medium">{{ t('invoice.status_label') }}</th>
            </tr>
          </template>
          <tr v-for="inv in invoices" :key="inv.id" class="cursor-pointer"
              :class="invoiceRowClass(inv.due_date, inv.status)"
              @click="router.push(`/tri/invoices/${inv.id}`)">
            <td class="px-4 py-2.5 font-mono">{{ inv.varsymbol || `#${inv.id}` }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
            <td class="px-4 py-2.5">
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-600 font-medium' : 'text-neutral-600'">
                {{ formatDate(inv.due_date) }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right font-mono">
              {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
            </td>
            <td class="px-4 py-2.5 text-center">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
          </tr>
        </UiTable>
      </div>

      <!-- Mobile: karty -->
      <div v-if="invoices.length" class="md:hidden divide-y divide-neutral-100">
        <div v-for="inv in invoices" :key="`m-${inv.id}`"
          @click="router.push(`/tri/invoices/${inv.id}`)"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3"
          :class="invoiceRowClass(inv.due_date, inv.status)">
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-mono font-medium text-neutral-900">{{ inv.varsymbol || `#${inv.id}` }}</div>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">
              {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span>{{ typeLabel(inv.invoice_type) }}</span>
            <span>
              <span>{{ formatDate(inv.issue_date) }}</span>
              <span class="text-neutral-400 mx-1"> → </span>
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                {{ formatDate(inv.due_date) }}
              </span>
            </span>
          </div>
          <div class="mt-2">
            <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
              {{ statusLabel(inv.status) }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="invoices.length" class="px-5 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: invoices.length, total: invoicesTotal }) }}</span>
        <UiButton v-if="invoicesPage < invoicesPages" size="sm" :loading="invoicesLoadingMore" :disabled="invoicesLoadingMore" @click="loadMoreInvoices">
          {{ invoicesLoadingMore ? t('common.loading_more') : t('common.load_more') }}
        </UiButton>
      </div>
    </UiCard>

  </div>
</template>

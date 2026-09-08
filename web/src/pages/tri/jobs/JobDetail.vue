<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob, type TriVariantStatus, type TriJobInvoice, type TriJobInvoiceSummary, type TriTraveler, type TriCalendarEvent, type TriComplaint } from '@/api/tri'
import { triInvoicesApi } from '@/api/triInvoices'
import type { InvoiceListItem } from '@/api/invoices'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate, statusLabel, typeLabel, statusBadgeClass } from '@/composables/useFormat'
import JobHeaderInfo from './JobHeaderInfo.vue'
import JobActivityFeed from './JobActivityFeed.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiTable from '@/components/ui/UiTable.vue'
import UiInput from '@/components/ui/UiInput.vue'
import ComplaintFormModal from '../complaints/ComplaintFormModal.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const jobId = computed(() => Number(route.params.id))
const job = ref<TriJob | null>(null)
const loading = ref(true)
const jobInvoices = ref<TriJobInvoice[]>([])
const invoiceSummary = ref<TriJobInvoiceSummary | null>(null)
const invoicesLoading = ref(false)
const travelers = ref<TriTraveler[]>([])
const travelersLoading = ref(false)
const travelersBusy = ref(false)
const calendarEvents = ref<TriCalendarEvent[]>([])
const calendarLoading = ref(false)
const complaints = ref<TriComplaint[]>([])
const complaintsLoading = ref(false)
const complaintFormOpen = ref(false)

const advanceModalOpen = ref(false)
const advancePercent = ref(50)
const advanceAmount = ref<number | ''>('')
const advanceText = ref('')
const advanceBusy = ref(false)

const linkModalOpen = ref(false)
const linkCandidates = ref<InvoiceListItem[]>([])
const linkBusy = ref(false)
const projectBusy = ref(false)

const canInvoice = computed(() => !!job.value?.approved_variant_id && !!job.value?.customer_client_id)
const canGenerateTravelers = computed(() =>
  !!job.value?.approved_variant_id
  && (job.value?.status === 'confirmed' || job.value?.status === 'completed'),
)

const hoursSummary = computed(() => {
  const cents: Record<string, number> = {}
  let total = 0
  for (const row of travelers.value) {
    for (const op of row.operations ?? []) {
      if (op.hours == null) continue
      const value = Math.round(op.hours * 100)
      cents[op.station] = (cents[op.station] ?? 0) + value
      total += value
    }
  }
  const order = [
    'konstrukce', 'narezove_centrum', 'cnc', 'olepovacka', 'dyhovani_brouseni',
    'montaz', 'lakovna', 'brouseni', 'baleni',
  ]
  return {
    total: total / 100,
    by_station: order
      .filter((station) => (cents[station] ?? 0) > 0)
      .map((station) => ({ station, hours: (cents[station] ?? 0) / 100 })),
  }
})

function formatHours(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

const statusOptions = ['active', 'confirmed', 'rejected', 'completed'] as const

const jobCreationDate = computed(() => {
  if (!job.value) return null
  const dates = (job.value.variants ?? []).map((v) => v.job_date).filter(Boolean)
  if (dates.length) {
    return [...dates].sort().at(-1) ?? null
  }
  return job.value.created_at?.slice(0, 10) ?? null
})

function variantBadgeVariant(status: TriVariantStatus): 'draft' | 'sent' | 'paid' {
  if (status === 'approved') return 'paid'
  if (status === 'sent') return 'sent'
  return 'draft'
}

function jobStatusBadgeVariant(s: string): 'primary' | 'success' | 'danger' | 'neutral' {
  if (s === 'active') return 'primary'
  if (s === 'confirmed') return 'success'
  if (s === 'rejected') return 'danger'
  return 'neutral'
}

async function loadInvoices() {
  invoicesLoading.value = true
  try {
    const r = await triApi.jobInvoices.list(jobId.value)
    jobInvoices.value = r.invoices
    invoiceSummary.value = r.summary
  } finally {
    invoicesLoading.value = false
  }
}

async function refreshInvoices() {
  invoicesLoading.value = true
  try {
    const r = await triApi.jobInvoices.refresh(jobId.value)
    jobInvoices.value = r.invoices
    invoiceSummary.value = r.summary
    toast.success(t('tri.invoices.refreshed'))
  } catch (e: unknown) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    invoicesLoading.value = false
  }
}

async function ensureProject() {
  projectBusy.value = true
  try {
    await triApi.jobs.ensureProject(jobId.value)
    toast.success(t('tri.invoices.project_synced'))
    await load()
  } catch (e: unknown) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    projectBusy.value = false
  }
}

async function loadTravelers() {
  travelersLoading.value = true
  try {
    const r = await triApi.travelers.listForJob(jobId.value)
    travelers.value = r.data
  } finally {
    travelersLoading.value = false
  }
}

async function loadCalendar() {
  calendarLoading.value = true
  try {
    const r = await triApi.calendar.listForJob(jobId.value)
    calendarEvents.value = r.data
  } finally {
    calendarLoading.value = false
  }
}

async function loadComplaints() {
  complaintsLoading.value = true
  try {
    const r = await triApi.complaints.listForJob(jobId.value)
    complaints.value = r.data
  } finally {
    complaintsLoading.value = false
  }
}

async function generateTravelers() {
  travelersBusy.value = true
  try {
    const r = await triApi.travelers.generate(jobId.value)
    travelers.value = r.data
    toast.success(t('tri.travelers.generated', { n: r.created }))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    travelersBusy.value = false
  }
}

function openTravelersPdf() {
  window.open(triApi.travelers.jobPdfUrl(jobId.value, false), '_blank')
}

async function load() {
  loading.value = true
  try {
    job.value = await triApi.jobs.get(jobId.value)
    await Promise.all([loadInvoices(), loadTravelers(), loadCalendar(), loadComplaints()])
  } finally {
    loading.value = false
  }
}

async function changeStatus(status: string) {
  await triApi.jobs.updateStatus(jobId.value, status)
  toast.success(t('common.saved'))
  await load()
}

async function addVariant() {
  const v = await triApi.variants.create(jobId.value)
  router.push({ name: 'tri-variant-edit', params: { jobId: jobId.value, variantId: v.id } })
}

async function duplicateVariant(id: number) {
  const v = await triApi.variants.duplicate(id)
  router.push({ name: 'tri-variant-edit', params: { jobId: jobId.value, variantId: v.id } })
}

function openVariant(id: number) {
  router.push({ name: 'tri-variant-edit', params: { jobId: jobId.value, variantId: id } })
}

function openAdvanceModal() {
  advancePercent.value = 50
  advanceAmount.value = ''
  advanceText.value = job.value ? `Záloha na objednávku č.${job.value.number}` : ''
  advanceModalOpen.value = true
}

async function createAdvance() {
  if (!job.value) return
  advanceBusy.value = true
  try {
    const payload: { percent?: number; amount?: number; text?: string } = { text: advanceText.value || undefined }
    if (advanceAmount.value !== '' && Number(advanceAmount.value) > 0) {
      payload.amount = Number(advanceAmount.value)
    } else {
      payload.percent = Number(advancePercent.value) || 50
    }
    const r = await triApi.jobInvoices.createAdvance(jobId.value, payload)
    advanceModalOpen.value = false
    router.push(`/tri/invoices/${r.invoice_id}/edit`)
  } catch {
    toast.error(t('common.error'))
  } finally {
    advanceBusy.value = false
  }
}

async function createFinal() {
  if (!job.value) return
  try {
    const r = await triApi.jobInvoices.createFinal(jobId.value)
    router.push(`/tri/invoices/${r.invoice_id}/edit`)
  } catch {
    toast.error(t('common.error'))
  }
}

async function openLinkModal() {
  if (!job.value?.customer_client_id) return
  linkModalOpen.value = true
  linkBusy.value = true
  try {
    const r = await triInvoicesApi.list({ 'filter[client_id]': job.value.customer_client_id, per_page: 100 })
    const linkedIds = new Set(jobInvoices.value.map((i) => i.id))
    linkCandidates.value = r.data.flatMap((g) => g.invoices).filter((i) => !linkedIds.has(i.id))
  } finally {
    linkBusy.value = false
  }
}

async function linkInvoice(invoiceId: number) {
  linkBusy.value = true
  try {
    await triApi.invoices.setJob(invoiceId, jobId.value)
    linkModalOpen.value = false
    toast.success(t('tri.invoices.linked'))
    await loadInvoices()
  } catch {
    toast.error(t('common.error'))
  } finally {
    linkBusy.value = false
  }
}

function openInvoice(inv: TriJobInvoice) {
  if (inv.status === 'draft' && auth.canWrite) {
    router.push(`/tri/invoices/${inv.id}/edit`)
  } else {
    router.push({ name: 'tri-invoice-detail', params: { id: inv.id } })
  }
}

onMounted(() => load())
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
  <div v-else-if="job" class="space-y-6">
    <div>
      <RouterLink to="/tri/jobs" class="text-sm text-neutral-500 hover:text-neutral-900">← {{ t('tri.jobs.back_to_list') }}</RouterLink>
      <UiPageHeader :title="job.title">
        <template #below>
          <p class="mt-1.5 font-mono text-sm text-neutral-500">{{ job.number }}</p>
        </template>
        <template #actions>
          <UiBadge :variant="jobStatusBadgeVariant(job.status)">{{ t(`tri.jobs.status_${job.status}`) }}</UiBadge>
          <UiButton v-if="auth.canWrite" :to="{ name: 'tri-job-edit', params: { id: job.id } }" variant="secondary" size="sm">
            {{ t('common.edit') }}
          </UiButton>
        </template>
      </UiPageHeader>
    </div>

    <!-- Dva sloupce na širokých obrazovkách: vlevo zakázka, vpravo komunikace (chat) -->
    <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] xl:items-start">

    <div class="space-y-6 min-w-0">
    <UiCard>
      <div class="flex flex-col gap-4 p-5 border-b border-neutral-200 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.quote.header_job') }}</p>
          <p class="mt-1 text-3xl font-bold font-mono tracking-tight text-neutral-900">{{ job.number }}</p>
          <h2 class="mt-1 text-lg font-medium text-neutral-600 break-words">{{ job.title }}</h2>
        </div>

        <div class="flex flex-wrap items-start gap-5 shrink-0">
          <div>
            <span class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.jobs.status') }}</span>
            <select
              v-if="auth.canWrite"
              class="max-w-[180px] h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface shadow-xs outline-none focus-ring"
              :value="job.status"
              @change="changeStatus(($event.target as HTMLSelectElement).value)"
            >
              <option v-for="s in statusOptions" :key="s" :value="s">{{ t(`tri.jobs.status_${s}`) }}</option>
            </select>
            <p v-else class="h-10 flex items-center text-sm font-medium text-neutral-800">{{ t(`tri.jobs.status_${job.status}`) }}</p>
          </div>

          <div>
            <span class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.created_date') }}</span>
            <p class="h-10 flex items-center text-sm font-medium text-neutral-800">{{ formatDate(jobCreationDate) }}</p>
          </div>

          <div v-if="job.assignees?.length">
            <span class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.jobs.assignees') }}</span>
            <p class="min-h-10 flex items-center text-sm font-medium text-neutral-800">{{ job.assignees.map((a) => a.name).join(', ') }}</p>
          </div>
        </div>
      </div>

      <JobHeaderInfo
        :contacts="job.contacts ?? []"
        :site-street="job.site_street"
        :site-city="job.site_city"
        :site-zip="job.site_zip"
      />
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.jobs.variants') }}</h3>
        <UiButton v-if="auth.canWrite" size="sm" @click="addVariant">
          {{ t('tri.jobs.add_variant') }}
        </UiButton>
      </div>
      <div v-if="!job.variants?.length" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.no_data') }}</div>
      <div v-else>
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.variant') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.jobs.status') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.quote.price_no_vat') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.quote.created_col') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </template>
          <tr
            v-for="v in job.variants"
            :key="v.id"
            class="cursor-pointer"
            @click="openVariant(v.id)"
          >
            <td class="px-4 py-3 font-mono">{{ v.number }}</td>
            <td class="px-4 py-3">
              <UiBadge :variant="variantBadgeVariant(v.status)">{{ t(`tri.quote.status_${v.status}`) }}</UiBadge>
            </td>
            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(Math.round(v.subtotal), 'CZK', 0) }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ formatDate(v.job_date) }}</td>
            <td class="px-4 py-3 text-right" @click.stop>
              <UiButton v-if="auth.canWrite" variant="ghost" size="sm" @click="duplicateVariant(v.id)">
                {{ t('tri.jobs.duplicate_variant') }}
              </UiButton>
            </td>
          </tr>
        </UiTable>
      </div>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.travelers.section_title') }}</h3>
        <div class="flex flex-wrap gap-2">
          <UiButton
            v-if="travelers.length"
            type="button"
            variant="outline"
            size="sm"
            @click="openTravelersPdf"
          >
            {{ t('tri.travelers.print_all') }}
          </UiButton>
          <UiButton
            v-if="auth.canWrite"
            type="button"
            size="sm"
            :disabled="!canGenerateTravelers || travelersBusy"
            :loading="travelersBusy"
            :title="!canGenerateTravelers ? t('tri.travelers.need_confirmed') : undefined"
            @click="generateTravelers"
          >
            {{ t('tri.travelers.generate') }}
          </UiButton>
        </div>
      </div>
      <div v-if="travelersLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="travelers.length === 0" class="p-8 text-center text-neutral-500 text-sm">{{ t('tri.travelers.no_data') }}</div>
      <div v-else>
        <div class="px-5 py-4 border-b border-neutral-200 bg-neutral-50">
          <div class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.hours_widget') }}</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ formatHours(hoursSummary.total) }} h</div>
          <p v-if="hoursSummary.by_station.length === 0" class="mt-1 text-sm text-neutral-500">{{ t('tri.travelers.hours_empty') }}</p>
          <div v-else class="mt-2 flex flex-wrap gap-2">
            <span
              v-for="row in hoursSummary.by_station"
              :key="row.station"
              class="inline-flex items-center gap-1.5 rounded-full bg-white border border-neutral-200 px-2.5 py-1 text-xs text-neutral-700"
            >
              {{ t(`tri.travelers.station_${row.station}`) }}
              <span class="tabular-nums font-mono font-medium">{{ formatHours(row.hours) }}</span>
            </span>
          </div>
        </div>
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.number') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.item') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.travelers.quantity') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.travelers.hours') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.status') }}</th>
            </tr>
          </template>
          <tr
            v-for="row in travelers"
            :key="row.id"
            class="cursor-pointer"
            @click="router.push({ name: 'tri-traveler-detail', params: { id: row.id } })"
          >
            <td class="px-4 py-3 font-mono">{{ row.number }}</td>
            <td class="px-4 py-3">
              <span v-if="row.designation" class="font-mono text-neutral-500 mr-1.5">{{ row.designation }}</span>
              {{ row.title }}
            </td>
            <td class="px-4 py-3 text-right tabular-nums">{{ row.quantity }} {{ row.unit }}</td>
            <td class="px-4 py-3 text-right tabular-nums font-mono">{{ formatHours(row.hours_total ?? 0) }}</td>
            <td class="px-4 py-3">
              <UiBadge :variant="row.status === 'done' ? 'success' : 'primary'">
                {{ t(`tri.travelers.status_${row.status}`) }}
              </UiBadge>
            </td>
          </tr>
        </UiTable>
      </div>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.calendar.upcoming') }}</h3>
        <UiButton variant="outline" size="sm" :to="{ name: 'tri-calendar' }">
          {{ t('tri.calendar.open_calendar') }}
        </UiButton>
      </div>
      <div v-if="calendarLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="calendarEvents.length === 0" class="p-8 text-center text-neutral-500 text-sm">{{ t('tri.calendar.upcoming_empty') }}</div>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="ev in calendarEvents" :key="ev.id" class="px-5 py-3 flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-medium text-neutral-900">{{ ev.title }}</div>
            <div class="mt-0.5 text-xs text-neutral-500">
              {{ t(`tri.calendar.${ev.calendar}`) }}
              <span v-if="ev.station"> · {{ t(`tri.calendar.station_${ev.station}`) }}</span>
              · {{ formatDate(ev.starts_at.slice(0, 10)) }}
            </div>
          </div>
          <span
            class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
            :class="ev.status === 'done' ? 'bg-neutral-100 text-neutral-600' : ev.status === 'confirmed' || ev.status === 'in_progress' ? 'bg-emerald-50 text-emerald-800' : 'border border-dashed border-neutral-300 text-neutral-600'"
          >
            {{ t(`tri.calendar.status_${ev.status}`) }}
          </span>
        </li>
      </ul>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.complaints.section_title') }}</h3>
        <div class="flex flex-wrap gap-2">
          <UiButton variant="outline" size="sm" :to="{ name: 'tri-complaints', query: { job_id: String(jobId) } }">
            {{ t('tri.complaints.open_list') }}
          </UiButton>
          <UiButton v-if="auth.canWrite" type="button" size="sm" @click="complaintFormOpen = true">
            {{ t('tri.complaints.new') }}
          </UiButton>
        </div>
      </div>
      <div v-if="complaintsLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="complaints.length === 0" class="p-8 text-center text-neutral-500 text-sm">{{ t('tri.complaints.no_data') }}</div>
      <ul v-else class="divide-y divide-neutral-100">
        <li
          v-for="row in complaints"
          :key="row.id"
          class="px-5 py-3 flex items-start justify-between gap-3 cursor-pointer hover:bg-neutral-50"
          @click="router.push({ name: 'tri-complaint-detail', params: { id: row.id } })"
        >
          <div class="min-w-0">
            <div class="text-sm font-medium text-neutral-900 truncate">{{ row.title }}</div>
            <div class="mt-0.5 text-xs text-neutral-500">{{ formatDate(row.created_at.slice(0, 10)) }}</div>
          </div>
          <UiBadge :variant="row.status === 'closed' ? 'neutral' : 'warning'">
            {{ t(`tri.complaints.status_${row.status}`) }}
          </UiBadge>
        </li>
      </ul>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.invoices.section_title') }}</h3>
        <div v-if="auth.canWrite" class="flex flex-wrap gap-2">
          <UiButton
            type="button"
            variant="outline"
            size="sm"
            :disabled="invoicesLoading"
            @click="refreshInvoices"
          >
            {{ t('tri.invoices.refresh_from_myucto') }}
          </UiButton>
          <UiButton
            v-if="auth.canWrite && !job.myucto_project_id"
            type="button"
            variant="outline"
            size="sm"
            :loading="projectBusy"
            @click="ensureProject"
          >
            {{ t('tri.invoices.sync_project') }}
          </UiButton>
          <UiButton
            type="button"
            variant="outline"
            size="sm"
            :disabled="!canInvoice"
            :title="!canInvoice ? t('tri.invoices.need_approved_variant') : undefined"
            @click="openLinkModal"
          >
            {{ t('tri.invoices.link_existing') }}
          </UiButton>
          <UiButton type="button" variant="outline" size="sm" :disabled="!canInvoice" @click="openAdvanceModal">
            {{ t('tri.invoices.create_advance') }}
          </UiButton>
          <UiButton type="button" size="sm" :disabled="!canInvoice" @click="createFinal">
            {{ t('tri.invoices.create_final') }}
          </UiButton>
        </div>
      </div>

      <div v-if="invoiceSummary" class="grid grid-cols-2 md:grid-cols-4 gap-3 p-5 border-b border-neutral-200">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.invoices.summary_variant') }}</p>
          <p class="font-semibold font-mono mt-1">{{ formatMoney(invoiceSummary.variant_total_with_vat, 'CZK') }}</p>
        </div>
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.invoices.summary_advances') }}</p>
          <p class="font-semibold font-mono mt-1">{{ formatMoney(invoiceSummary.paid_advances_total, 'CZK') }}</p>
        </div>
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.invoices.summary_invoiced') }}</p>
          <p class="font-semibold font-mono mt-1">{{ formatMoney(invoiceSummary.invoiced_total, 'CZK') }}</p>
        </div>
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.invoices.summary_remaining') }}</p>
          <p class="font-semibold font-mono mt-1">{{ formatMoney(invoiceSummary.remaining_to_invoice, 'CZK') }}</p>
        </div>
      </div>

      <div v-if="invoicesLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="jobInvoices.length === 0" class="p-8 text-center text-neutral-500 text-sm">{{ t('tri.invoices.no_invoices') }}</div>
      <div v-else>
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.type') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.status_label') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('invoice.totals.total') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('invoice.amount_to_pay') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.issue_date') }}</th>
            </tr>
          </template>
          <tr
            v-for="inv in jobInvoices"
            :key="inv.id"
            class="cursor-pointer"
            @click="openInvoice(inv)"
          >
            <td class="px-4 py-3 font-mono">{{ inv.varsymbol ?? '—' }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
            <td class="px-4 py-3">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(inv.total_with_vat, 'CZK') }}</td>
            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(inv.amount_to_pay, 'CZK') }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
          </tr>
        </UiTable>
      </div>
    </UiCard>
    </div>

    <!-- Komunikace (chat + log událostí) — pravý sloupec, na užších obrazovkách pod obsahem -->
    <div class="space-y-4 min-w-0">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('tri.activity.title') }}</h2>
      <JobActivityFeed :job-id="jobId" />
    </div>

    </div>

    <!-- Modal: zálohová faktura -->
    <div v-if="advanceModalOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="advanceModalOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5 space-y-4">
        <h3 class="text-lg font-semibold text-neutral-900">{{ t('tri.invoices.create_advance') }}</h3>
        <div class="grid grid-cols-2 gap-3">
          <UiInput v-model="advancePercent" type="number" min="1" max="100" :label="t('tri.invoices.advance_percent')" />
          <UiInput v-model="advanceAmount" type="number" min="0" step="0.01" :label="t('tri.invoices.advance_amount')" :placeholder="t('tri.invoices.advance_amount_optional')" />
        </div>
        <UiInput v-model="advanceText" :label="t('tri.invoices.advance_text')" />
        <div class="flex justify-end gap-2">
          <UiButton type="button" variant="secondary" size="sm" @click="advanceModalOpen = false">{{ t('common.cancel') }}</UiButton>
          <UiButton type="button" size="sm" :loading="advanceBusy" :disabled="advanceBusy" @click="createAdvance">{{ t('common.create') }}</UiButton>
        </div>
      </div>
    </div>

    <!-- Modal: připojit fakturu -->
    <div v-if="linkModalOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="linkModalOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5 space-y-3 max-h-[80vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-neutral-900">{{ t('tri.invoices.link_existing') }}</h3>
        <p v-if="linkBusy" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <p v-else-if="linkCandidates.length === 0" class="text-sm text-neutral-500">{{ t('tri.invoices.no_link_candidates') }}</p>
        <ul v-else class="divide-y divide-neutral-100">
          <li v-for="inv in linkCandidates" :key="inv.id">
            <button
              type="button"
              class="cursor-pointer w-full text-left px-2 py-2 hover:bg-neutral-50 flex justify-between gap-2 rounded-md"
              @click="linkInvoice(inv.id)"
            >
              <span class="font-mono">{{ inv.varsymbol ?? '—' }} — {{ typeLabel(inv.invoice_type) }}</span>
              <span class="text-neutral-600">{{ formatMoney(inv.total_with_vat, inv.currency) }}</span>
            </button>
          </li>
        </ul>
        <div class="flex justify-end">
          <UiButton type="button" variant="secondary" size="sm" @click="linkModalOpen = false">{{ t('common.cancel') }}</UiButton>
        </div>
      </div>
    </div>

    <ComplaintFormModal
      v-if="complaintFormOpen"
      :job-id="jobId"
      @close="complaintFormOpen = false"
      @created="(row) => { complaintFormOpen = false; router.push({ name: 'tri-complaint-detail', params: { id: row.id } }) }"
    />
  </div>
</template>

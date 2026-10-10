<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob, type TriJobInvoice, type TriJobInvoiceSummary, type TriTraveler, type TriCalendarEvent, type TriComplaint } from '@/api/tri'
import { triInvoicesApi } from '@/api/triInvoices'
import type { InvoiceListItem } from '@/api/invoices'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate, statusLabel, typeLabel } from '@/composables/useFormat'
import JobHeaderInfo from './JobHeaderInfo.vue'
import JobActivityFeed from './JobActivityFeed.vue'
import OfficeBreadcrumb from '@/components/tri/OfficeBreadcrumb.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSelect from '@/components/ui/UiSelect.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import OfficeMenu from '@/components/tri/OfficeMenu.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ComplaintFormModal from '../complaints/ComplaintFormModal.vue'

const { t, locale } = useI18n()
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
const invoiceBlockedReason = computed(() => {
  if (!job.value || canInvoice.value) return ''
  if (!job.value.approved_variant_id) return t('tri.invoices.need_approved_variant')
  if (!job.value.customer_client_id) return t('tri.invoices.need_billing_customer')
  return ''
})
const canGenerateTravelers = computed(() =>
  !!job.value?.approved_variant_id
  && (job.value?.status === 'confirmed' || job.value?.status === 'completed'),
)

function formatHours(n: number) {
  return n.toLocaleString(locale.value === 'en' ? 'en-GB' : 'cs-CZ', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
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

function shortDay(iso: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  if (!match) return ''
  return `${Number(match[3])}. ${Number(match[2])}.`
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
  <CardSkeleton v-if="loading" :blocks="4" />
  <div v-else-if="job" class="space-y-6">
    <div>
      <OfficeBreadcrumb :items="[{label:t('tri.jobs.title'),to:'/tri/jobs'},{label:job.number}]" />
      <UiPageHeader :title="t('tri.jobs.record_title', { number: job.number })" :subtitle="job.title" subtitle-strong>
        <template #actions>
          <UiSelect
            class="w-[220px]"
            :label="t('tri.jobs.status')"
            :model-value="job.status"
            :disabled="!auth.canWrite"
            @update:model-value="changeStatus(String($event ?? ''))"
          >
            <option v-for="status in statusOptions" :key="status" :value="status">{{ t(`tri.jobs.status_${status}`) }}</option>
          </UiSelect>
          <UiButton v-if="auth.canWrite" :to="{ name: 'tri-job-edit', params: { id: job.id } }" variant="secondary">
            {{ t('tri.jobs.edit') }}
          </UiButton>
        </template>
      </UiPageHeader>
    </div>

    <!-- Dva sloupce na širokých obrazovkách: vlevo zakázka, vpravo komunikace (chat) -->
    <div class="grid gap-6 xl:grid-cols-[minmax(0,640fr)_minmax(0,448fr)] xl:items-start">

    <div class="space-y-6 min-w-0">
    <UiCard>
      <div class="p-5">
        <h2 class="mb-4">{{ t('office.basic_info') }}</h2>
        <dl class="space-y-4 text-[13px]">
          <div class="flex items-center justify-between gap-4">
            <dt class="text-neutral-500">{{ t('tri.jobs.current_status') }}</dt>
            <dd><UiBadge :variant="jobStatusBadgeVariant(job.status)">{{ t(`tri.jobs.status_${job.status}`) }}</UiBadge></dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">{{ t('tri.quote.created_date') }}</dt>
            <dd>{{ formatDate(jobCreationDate) }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="text-neutral-500">{{ t('office.author') }}</dt>
            <dd>{{ job.owner_name }}</dd>
          </div>
          <div v-if="job.assignees?.length">
            <dt class="mb-2 text-neutral-500">{{ t('tri.jobs.owners') }}</dt>
            <dd class="flex flex-wrap gap-2">
              <span v-for="person in job.assignees" :key="person.user_id" class="inline-flex h-10 items-center border border-neutral-200 px-3">{{ person.name }}</span>
            </dd>
          </div>
        </dl>
      </div>
    </UiCard>

    <UiCard>
      <JobHeaderInfo
        :contacts="job.contacts ?? []"
        :site-street="job.site_street"
        :site-city="job.site_city"
        :site-zip="job.site_zip"
      />
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.jobs.variants') }}</h3>
        <UiButton v-if="auth.canWrite" size="sm" class="self-start sm:self-auto" @click="addVariant">
          {{ t('tri.jobs.add_variant') }}
        </UiButton>
      </div>
      <EmptyState v-if="!job.variants?.length" compact :title="t('common.no_data')" />
      <ul v-else class="space-y-3 px-5 pb-5">
        <li
          v-for="v in job.variants"
          :key="v.id"
          class="flex cursor-pointer items-center justify-between gap-4 border border-neutral-200 px-4 py-3 hover:bg-neutral-50"
          @click="openVariant(v.id)"
        >
          <div class="min-w-0">
            <RouterLink :to="`/tri/jobs/${job.id}/variants/${v.id}`" class="font-medium text-neutral-900" @click.stop>{{ t('tri.jobs.variant') }} {{ v.variant_code }}</RouterLink>
            <p v-if="v.name" class="mt-0.5 text-xs text-neutral-500">{{ v.name }}</p>
          </div>
          <div class="flex shrink-0 items-start gap-3">
            <div class="text-right">
              <p class="tabular-nums font-medium">{{ formatMoney(v.total_with_vat || v.subtotal, 'CZK') }}</p>
              <p class="text-[11px] text-neutral-500">{{ v.status === 'approved' ? t('tri.jobs.variant_active') : t(`tri.quote.status_${v.status}`) }}</p>
            </div>
            <OfficeMenu v-if="auth.canWrite" @click.stop>
              <button type="button" @click="duplicateVariant(v.id)">{{ t('tri.jobs.duplicate_variant') }}</button>
            </OfficeMenu>
          </div>
        </li>
      </ul>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.calendar.upcoming') }}</h3>
        <UiButton variant="outline" size="sm" :to="{ name: 'tri-calendar' }">
          {{ t('tri.calendar.open_calendar') }}
        </UiButton>
      </div>
      <TableSkeleton v-if="calendarLoading" :rows="3" :cols="3" />
      <EmptyState v-else-if="calendarEvents.length === 0" compact :title="t('tri.calendar.upcoming_empty')" />
      <ul v-else class="space-y-2 px-4 pb-4">
        <li v-for="ev in calendarEvents" :key="ev.id" class="flex items-center gap-3">
          <div class="flex h-9 w-12 shrink-0 items-center justify-center border border-neutral-200 text-xs">{{ shortDay(ev.starts_at) }}</div>
          <div class="min-w-0">
            <div class="text-sm font-medium text-neutral-900">{{ ev.title }}</div>
            <div class="mt-0.5 text-xs text-neutral-500">
              {{ t(`tri.calendar.${ev.calendar}`) }}
              <span v-if="ev.station"> · {{ t(`tri.calendar.station_${ev.station}`) }}</span>
              <span v-if="ev.arrival_window"> · {{ ev.arrival_window }}</span>
              <span v-if="ev.crew"> · {{ ev.crew }}</span>
              · {{ t(`tri.calendar.status_${ev.status}`) }}
            </div>
          </div>
        </li>
      </ul>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <h3 class="font-semibold text-neutral-900">{{ t('tri.invoices.section_title') }}</h3>
        <div v-if="auth.canWrite" class="flex flex-wrap gap-2 items-center">
          <span :title="invoiceBlockedReason || undefined">
            <UiButton size="sm" :disabled="!canInvoice" @click="openAdvanceModal">{{t('tri.invoices.create_advance')}}</UiButton>
          </span>
          <p v-if="invoiceBlockedReason" class="text-xs text-neutral-500">{{ invoiceBlockedReason }}</p>
          <OfficeMenu><button :disabled="!canInvoice" @click="createFinal">{{t('tri.invoices.create_final')}}</button><button :disabled="!canInvoice" @click="openLinkModal">{{t('tri.invoices.link_existing')}}</button><button :disabled="invoicesLoading" @click="refreshInvoices">{{t('tri.invoices.refresh_from_myucto')}}</button><button v-if="!job.myucto_project_id" :disabled="projectBusy" @click="ensureProject">{{t('tri.invoices.sync_project')}}</button></OfficeMenu>
        </div>
      </div>

      <TableSkeleton v-if="invoicesLoading" :rows="4" :cols="6" />
      <EmptyState v-else-if="jobInvoices.length === 0" compact :title="t('tri.invoices.no_invoices')" />
      <ul v-else class="space-y-3 px-5 pt-4">
        <li
          v-for="inv in jobInvoices"
          :key="inv.id"
          class="flex cursor-pointer items-center justify-between gap-4 border border-neutral-200 px-4 py-3 hover:bg-neutral-50"
          @click="openInvoice(inv)"
        >
          <div class="min-w-0">
            <p class="font-medium text-neutral-900">{{ inv.varsymbol ?? `#${inv.id}` }} · {{ typeLabel(inv.invoice_type) }}</p>
            <p class="mt-0.5 text-xs text-neutral-500">{{ t('invoice.due_date') }} {{ formatDate(inv.due_date || inv.issue_date) }}</p>
          </div>
          <div class="text-right">
            <p class="tabular-nums font-medium">{{ formatMoney(inv.total_with_vat, 'CZK') }}</p>
            <p class="text-[11px] text-neutral-500">{{ statusLabel(inv.status) }}</p>
          </div>
        </li>
      </ul>
      <dl v-if="invoiceSummary" class="space-y-3 border-t border-neutral-200 px-5 py-4 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-neutral-500">{{ t('tri.invoices.summary_variant') }}</dt><dd class="tabular-nums">{{ formatMoney(invoiceSummary.variant_total_with_vat, 'CZK') }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-neutral-500">{{ t('tri.invoices.summary_advances') }}</dt><dd class="tabular-nums">{{ formatMoney(invoiceSummary.paid_advances_total, 'CZK') }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-neutral-500">{{ t('tri.invoices.summary_invoiced') }}</dt><dd class="tabular-nums">{{ formatMoney(invoiceSummary.invoiced_total, 'CZK') }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-neutral-500">{{ t('tri.invoices.summary_remaining') }}</dt><dd class="tabular-nums font-medium">{{ formatMoney(invoiceSummary.remaining_to_invoice, 'CZK') }}</dd></div>
      </dl>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
      <TableSkeleton v-if="travelersLoading" :rows="3" :cols="5" />
      <EmptyState v-else-if="travelers.length === 0" compact :title="t('tri.travelers.no_data')" />
      <ul v-else class="space-y-3 px-5 pb-5">
        <li
          v-for="row in travelers"
          :key="row.id"
          class="flex items-center justify-between gap-4 border border-neutral-200 px-4 py-3"
        >
          <button type="button" class="min-w-0 text-left" @click="router.push({ name: 'tri-traveler-detail', params: { id: row.id } })">
            <p class="font-medium text-neutral-900">
              <span v-if="row.designation">{{ row.designation }} · </span>{{ row.title }}
            </p>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t(`tri.travelers.status_${row.status}`) }} · {{ formatHours(row.hours_total ?? 0) }} h · {{ row.quantity }} {{ row.unit }}
            </p>
          </button>
          <button type="button" class="shrink-0 text-sm text-primary-700" @click="router.push({ name: 'tri-traveler-detail', params: { id: row.id } })">
            {{ t('common.view') }}
          </button>
        </li>
      </ul>
    </UiCard>

    <UiCard>
      <div class="px-5 py-3 border-b border-neutral-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
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
      <TableSkeleton v-if="complaintsLoading" :rows="3" :cols="3" />
      <EmptyState v-else-if="complaints.length === 0" compact :title="t('tri.complaints.no_data')" />
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
    </div>

    <JobActivityFeed :job-id="jobId" />

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

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob, type TriVariantStatus, type TriJobInvoice, type TriJobInvoiceSummary } from '@/api/tri'
import { invoicesApi, type InvoiceListItem } from '@/api/invoices'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate, statusLabel, typeLabel, statusBadgeClass } from '@/composables/useFormat'
import JobHeaderInfo from './JobHeaderInfo.vue'

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

const advanceModalOpen = ref(false)
const advancePercent = ref(50)
const advanceAmount = ref<number | ''>('')
const advanceText = ref('')
const advanceBusy = ref(false)

const linkModalOpen = ref(false)
const linkCandidates = ref<InvoiceListItem[]>([])
const linkBusy = ref(false)

const canInvoice = computed(() => !!job.value?.approved_variant_id && !!job.value?.customer_client_id)

const statusOptions = ['active', 'confirmed', 'rejected', 'completed'] as const

const jobCreationDate = computed(() => {
  if (!job.value) return null
  const dates = (job.value.variants ?? []).map((v) => v.job_date).filter(Boolean)
  if (dates.length) {
    return [...dates].sort().at(-1) ?? null
  }
  return job.value.created_at?.slice(0, 10) ?? null
})

function variantStatusClass(status: TriVariantStatus): string {
  const map: Record<TriVariantStatus, string> = {
    draft: 'bg-neutral-100 text-neutral-600',
    sent: 'bg-teal-50 text-teal-600',
    approved: 'bg-success-50 text-success-600',
  }
  return map[status] ?? 'bg-neutral-100 text-neutral-600'
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

async function load() {
  loading.value = true
  try {
    job.value = await triApi.jobs.get(jobId.value)
    await loadInvoices()
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
    const r = await invoicesApi.listGrouped({ client_id: job.value.customer_client_id, per_page: 100 })
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
  <div v-if="loading" class="text-neutral-500">{{ t('common.loading') }}</div>
  <div v-else-if="job" class="max-w-5xl space-y-4">
    <RouterLink
      to="/tri/jobs"
      class="inline-flex items-center text-sm text-neutral-600 hover:text-neutral-900"
    >
      ← {{ t('tri.jobs.back_to_list') }}
    </RouterLink>

    <!-- Záhlaví zakázky -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="flex flex-col gap-4 p-5 border-b border-neutral-200 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.quote.header_job') }}</p>
          <p class="mt-1 text-3xl font-bold font-mono tracking-tight text-neutral-900">{{ job.number }}</p>
          <h1 class="mt-1 text-lg font-medium text-neutral-600 break-words">{{ job.title }}</h1>
        </div>

        <div class="flex flex-wrap items-start gap-5 shrink-0">
          <!-- Stav zakázky -->
          <div>
            <span class="block text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.jobs.status') }}</span>
            <select
              v-if="auth.canWrite"
              class="max-w-[180px] h-10 px-3 border border-neutral-300 rounded-lg text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
              :value="job.status"
              @change="changeStatus(($event.target as HTMLSelectElement).value)"
            >
              <option v-for="s in statusOptions" :key="s" :value="s">{{ t(`tri.jobs.status_${s}`) }}</option>
            </select>
            <p v-else class="h-10 flex items-center text-sm font-medium text-neutral-800">{{ t(`tri.jobs.status_${job.status}`) }}</p>
          </div>

          <!-- Datum vytvoření (odvozené z variant) -->
          <div>
            <span class="block text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.created_date') }}</span>
            <p class="h-10 flex items-center text-sm font-medium text-neutral-800">{{ formatDate(jobCreationDate) }}</p>
          </div>

          <!-- Upravit zakázku -->
          <div v-if="auth.canWrite">
            <span class="block text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5" aria-hidden="true">&nbsp;</span>
            <RouterLink
              :to="{ name: 'tri-job-edit', params: { id: job.id } }"
              class="cursor-pointer px-4 h-10 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 font-medium rounded-lg inline-flex items-center gap-1.5"
            >
              {{ t('common.edit') }}
            </RouterLink>
          </div>
        </div>
      </div>

      <JobHeaderInfo
        :contacts="job.contacts ?? []"
        :site-street="job.site_street"
        :site-city="job.site_city"
        :site-zip="job.site_zip"
      />
    </div>

    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold">{{ t('tri.jobs.variants') }}</h2>
      <button
        v-if="auth.canWrite"
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 h-7 px-2.5 bg-primary-600 hover:bg-primary-700 text-white text-xs font-medium rounded-md"
        @click="addVariant"
      >
        {{ t('tri.jobs.add_variant') }}
      </button>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50">
            <tr>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.variant') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.jobs.status') }}</th>
              <th class="text-right text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.quote.price_no_vat') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200">{{ t('tri.quote.created_col') }}</th>
              <th class="text-right text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b border-neutral-200"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="v in job.variants"
              :key="v.id"
              class="border-b border-neutral-100 hover:bg-neutral-50 cursor-pointer"
              @click="openVariant(v.id)"
            >
              <td class="px-3 py-2 font-mono">{{ v.number }}</td>
              <td class="px-3 py-2">
                <span class="text-xs px-2 py-0.5 rounded" :class="variantStatusClass(v.status)">
                  {{ t(`tri.quote.status_${v.status}`) }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">{{ formatMoney(Math.round(v.subtotal), 'CZK', 0) }}</td>
              <td class="px-3 py-2">{{ formatDate(v.job_date) }}</td>
              <td class="px-3 py-2 text-right">
                <button
                  v-if="auth.canWrite"
                  type="button"
                  class="text-sm text-neutral-600 hover:text-neutral-900"
                  @click.stop="duplicateVariant(v.id)"
                >
                  {{ t('tri.jobs.duplicate_variant') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Faktury -->
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold">{{ t('tri.invoices.section_title') }}</h2>
      <div v-if="auth.canWrite" class="flex flex-wrap gap-2">
        <button
          type="button"
          class="cursor-pointer inline-flex items-center h-7 px-2.5 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50"
          :disabled="!canInvoice"
          :title="!canInvoice ? t('tri.invoices.need_approved_variant') : undefined"
          @click="openLinkModal"
        >
          {{ t('tri.invoices.link_existing') }}
        </button>
        <button
          type="button"
          class="cursor-pointer inline-flex items-center h-7 px-2.5 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50"
          :disabled="!canInvoice"
          @click="openAdvanceModal"
        >
          {{ t('tri.invoices.create_advance') }}
        </button>
        <button
          type="button"
          class="cursor-pointer inline-flex items-center h-7 px-2.5 bg-primary-600 hover:bg-primary-700 text-white text-xs font-medium rounded-md disabled:opacity-50"
          :disabled="!canInvoice"
          @click="createFinal"
        >
          {{ t('tri.invoices.create_final') }}
        </button>
      </div>
    </div>

    <div v-if="invoiceSummary" class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div class="bg-surface border border-neutral-200 rounded-lg p-3 text-sm">
        <p class="text-neutral-500 text-xs">{{ t('tri.invoices.summary_variant') }}</p>
        <p class="font-semibold mt-1">{{ formatMoney(invoiceSummary.variant_total_with_vat, 'CZK') }}</p>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-3 text-sm">
        <p class="text-neutral-500 text-xs">{{ t('tri.invoices.summary_advances') }}</p>
        <p class="font-semibold mt-1">{{ formatMoney(invoiceSummary.paid_advances_total, 'CZK') }}</p>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-3 text-sm">
        <p class="text-neutral-500 text-xs">{{ t('tri.invoices.summary_invoiced') }}</p>
        <p class="font-semibold mt-1">{{ formatMoney(invoiceSummary.invoiced_total, 'CZK') }}</p>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-3 text-sm">
        <p class="text-neutral-500 text-xs">{{ t('tri.invoices.summary_remaining') }}</p>
        <p class="font-semibold mt-1">{{ formatMoney(invoiceSummary.remaining_to_invoice, 'CZK') }}</p>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="invoicesLoading" class="p-4 text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="jobInvoices.length === 0" class="p-4 text-sm text-neutral-500">{{ t('tri.invoices.no_invoices') }}</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50">
            <tr>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b">{{ t('invoice.col_number') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b">{{ t('invoice.col_type') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b">{{ t('invoice.col_status') }}</th>
              <th class="text-right text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b">{{ t('invoice.col_amount') }}</th>
              <th class="text-right text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b">{{ t('invoice.amount_to_pay') }}</th>
              <th class="text-left text-xs uppercase tracking-wide text-neutral-500 px-3 py-2 border-b">{{ t('invoice.col_issued') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="inv in jobInvoices"
              :key="inv.id"
              class="border-b border-neutral-100 hover:bg-neutral-50 cursor-pointer"
              @click="openInvoice(inv)"
            >
              <td class="px-3 py-2 font-mono">{{ inv.varsymbol ?? '—' }}</td>
              <td class="px-3 py-2">{{ typeLabel(inv.invoice_type) }}</td>
              <td class="px-3 py-2">
                <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                  {{ statusLabel(inv.status) }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">{{ formatMoney(inv.total_with_vat, 'CZK') }}</td>
              <td class="px-3 py-2 text-right">{{ formatMoney(inv.amount_to_pay, 'CZK') }}</td>
              <td class="px-3 py-2">{{ formatDate(inv.issue_date) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: zálohová faktura -->
    <div v-if="advanceModalOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="advanceModalOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5 space-y-4">
        <h3 class="text-lg font-semibold">{{ t('tri.invoices.create_advance') }}</h3>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tri.invoices.advance_percent') }}</label>
            <input v-model.number="advancePercent" type="number" min="1" max="100" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tri.invoices.advance_amount') }}</label>
            <input v-model="advanceAmount" type="number" min="0" step="0.01" :placeholder="t('tri.invoices.advance_amount_optional')" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tri.invoices.advance_text') }}</label>
          <input v-model="advanceText" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md" @click="advanceModalOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" class="cursor-pointer px-3 h-9 text-sm bg-primary-600 text-white rounded-md disabled:opacity-50" :disabled="advanceBusy" @click="createAdvance">{{ t('common.create') }}</button>
        </div>
      </div>
    </div>

    <!-- Modal: připojit fakturu -->
    <div v-if="linkModalOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="linkModalOpen = false">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5 space-y-3 max-h-[80vh] overflow-y-auto">
        <h3 class="text-lg font-semibold">{{ t('tri.invoices.link_existing') }}</h3>
        <p v-if="linkBusy" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <p v-else-if="linkCandidates.length === 0" class="text-sm text-neutral-500">{{ t('tri.invoices.no_link_candidates') }}</p>
        <ul v-else class="divide-y divide-neutral-100">
          <li v-for="inv in linkCandidates" :key="inv.id">
            <button
              type="button"
              class="cursor-pointer w-full text-left px-2 py-2 hover:bg-neutral-50 flex justify-between gap-2"
              @click="linkInvoice(inv.id)"
            >
              <span class="font-mono">{{ inv.varsymbol ?? '—' }} — {{ typeLabel(inv.invoice_type) }}</span>
              <span class="text-neutral-600">{{ formatMoney(inv.total_with_vat, inv.currency) }}</span>
            </button>
          </li>
        </ul>
        <div class="flex justify-end">
          <button type="button" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md" @click="linkModalOpen = false">{{ t('common.cancel') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

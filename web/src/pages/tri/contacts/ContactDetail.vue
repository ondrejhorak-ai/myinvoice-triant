<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { type Client } from '@/api/clients'
import { triApi, type TriJob, type TriTag } from '@/api/tri'
import { type InvoiceListItem } from '@/api/invoices'
import { triInvoicesApi } from '@/api/triInvoices'
import { formatMoney, formatDate, statusLabel, typeLabel } from '@/composables/useFormat'



import { useAuthStore } from '@/stores/auth'
import ClientTagEditor from '@/components/tri/ClientTagEditor.vue'
import ClientIncompleteBanner from '@/components/clients/ClientIncompleteBanner.vue'

import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiTable from '@/components/ui/UiTable.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'

import OfficeBreadcrumb from '@/components/tri/OfficeBreadcrumb.vue'
import OfficeRoleTag from '@/components/tri/OfficeRoleTag.vue'
import Modal from '@/components/ui/Modal.vue'
import { usePagedRows } from '@/composables/tri/usePagedRows'
import { apiErrorMessage } from '@/api/errors'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()

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

const error = ref('')
const personName = ref('')
const personEmail = ref('')
const personPhone = ref('')
const personSaving = ref(false)
const rolesOpen = ref(false)
const tags = ref<TriTag[]>([])
const { items: jobs, load: loadJobs, page: jobsPage, pages: jobsPages, loadingMore: jobsMore, error: jobsError } = usePagedRows<TriJob>(page => triApi.jobs.list({client_id: Number(route.params.id),page,per_page:25}))
async function refreshRoles() { tags.value = await triApi.tags.forClient(Number(route.params.id)) }
function formatPaymentDue(c: Client): string {
  if (c.payment_due_default == null) return t('client.due_default')
  if (c.payment_due_unit === 'month') {
    return c.payment_due_default === 1
      ? t('client.payment_due_preset_month')
      : `${c.payment_due_default}× ${t('client.payment_due_preset_month').toLowerCase()}`
  }
  return t('client.due_days_n', { n: c.payment_due_default })
}

let loadSequence=0
async function load() {
  const sequence=++loadSequence
  const id = Number(route.params.id)
  error.value = ''
  loading.value = true
  invoicesLoading.value = true
  invoicesPage.value = 1
  try {
    const [c, grouped] = await Promise.all([
      api.get<Client>(`/tri/contacts/${id}`).then(r => r.data),
      triInvoicesApi.list({ 'filter[client_id]': id, page: 1 }),
    ])
    if(sequence!==loadSequence)return
    client.value = c
    await Promise.all([loadJobs(), refreshRoles()])
    invoices.value = grouped.data.flatMap(g => g.invoices)
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } catch (e) {
    if(sequence===loadSequence)error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if(sequence!==loadSequence)return
    loading.value = false
    invoicesLoading.value = false
  }
}

async function loadMoreInvoices() {
  if (!client.value) return
  invoicesLoadingMore.value = true
  const next = invoicesPage.value + 1
  try {
    const grouped = await triInvoicesApi.list({ 'filter[client_id]': client.value.id, page: next })
    invoicesPage.value = next
    invoices.value.push(...grouped.data.flatMap(g => g.invoices))
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } catch(e) { error.value = apiErrorMessage(e, t('common.error'))
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

watch(() => route.params.id, () => load())

async function addPerson() {
  if (!client.value || client.value.party_kind !== 'company') return
  const name = personName.value.trim()
  const email = personEmail.value.trim()
  if (!name || !email) {
    error.value = t('tri.jobs.person_required')
    return
  }
  personSaving.value = true
  error.value = ''
  try {
    await triApi.contacts.create({
      party_kind: 'person',
      company_name: name,
      main_email: email,
      phone: personPhone.value.trim() || null,
      company_client_id: client.value.id,
      is_customer: false,
      is_vendor: false,
    })
    personName.value = ''
    personEmail.value = ''
    personPhone.value = ''
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    personSaving.value = false
  }
}

function listedAmount(inv: InvoiceListItem): number {
  if (inv.invoice_type === 'tax_document') return inv.total_with_vat
  return inv.amount_to_pay ?? inv.total_with_vat
}
</script>

<template>
  <CardSkeleton v-if="loading" :blocks="3" />
  <div v-else-if="client" class="space-y-6">
    <div><OfficeBreadcrumb :items="[{label:t('tri.contacts.title'),to:'/tri/contacts'},{label:client.company_name}]" />
    <UiPageHeader :title="client.company_name"><template #below><div class="flex flex-wrap gap-2 mt-2"><UiBadge>{{ t(client.party_kind === 'person' ? 'tri.contacts.kind_person' : client.party_kind === 'private' ? 'tri.contacts.kind_private' : 'tri.contacts.kind_company') }}</UiBadge><UiBadge v-if="client.party_kind !== 'person' && !client.myucto_id">{{ t('tri.contacts.not_in_myucto') }}</UiBadge><OfficeRoleTag v-for="tag in tags" :key="tag.id" :name="tag.name" :slug="tag.slug" /><RouterLink v-if="client.company" :to="`/tri/contacts/${client.company.id}`" class="text-sm text-primary-700">{{ client.company.company_name }}</RouterLink><span class="text-neutral-600 text-sm" v-if="client.ic">{{ t('common.ic') }} {{ client.ic }}</span><span class="text-neutral-600 text-sm" v-if="client.dic">{{ t('common.dic') }} {{ client.dic }}</span><UiBadge v-if="client.archived_at">{{ t('common.archived') }}</UiBadge></div><p v-if="client.party_kind !== 'person' && !client.myucto_id" class="text-xs text-neutral-500 mt-2">{{ t('tri.contacts.kind_hint') }}</p></template><template #actions><UiButton v-if="auth.canWrite" :to="`/tri/contacts/${client.id}/edit`">{{ t('office.edit_contact') }}</UiButton><details v-if="auth.canWrite" class="relative"><summary class="cursor-pointer border border-neutral-200 bg-surface px-4 py-2" :aria-label="t('office.actions')">⋯</summary><div class="absolute right-0 top-full bg-surface border border-neutral-200 p-2 min-w-48 z-20 shadow-lg"><button class="block w-full text-left p-2" @click="rolesOpen=true">{{ t('office.edit_roles') }}</button><button v-if="!client.archived_at" class="block w-full text-left p-2" @click="archive">{{ t('common.archive') }}</button><button v-else class="block w-full text-left p-2" @click="unarchive">{{ t('common.unarchive') }}</button></div></details></template></UiPageHeader></div>
    <div v-if="error" class="office-error" role="alert">{{ error }}</div>
    <ClientIncompleteBanner :client="client" :edit-to="`/tri/contacts/${client.id}/edit`" />
    <div class="grid lg:grid-cols-[3fr_2fr] gap-6">
      <UiCard padding><h2 class="mb-5">{{ t('office.contact_details') }}</h2><div class="grid sm:grid-cols-2 gap-6"><div class="space-y-4"><div><p class="text-xs text-neutral-500 mb-1">{{ t('client.email') }}</p><a v-if="client.main_email" :href="`mailto:${client.main_email}`" class="text-primary-700 break-all">{{ client.main_email }}</a><span v-else>—</span></div><div><p class="text-xs text-neutral-500 mb-1">{{ t('client.telephone') }}</p><a v-if="client.phone" :href="`tel:${client.phone}`" class="text-primary-700">{{ client.phone }}</a><span v-else>—</span></div></div><div><p class="text-xs text-neutral-500 mb-1">{{ t('client.section_address') }}</p><p>{{ client.street || '—' }}<br />{{ client.zip }} {{ client.city }}<br />{{ client.country_iso2 }}</p></div></div>
        <div class="grid grid-cols-2 gap-6 p-4 bg-primary-50 mt-6"><div><p class="text-xs text-neutral-500 mb-1">{{ t('common.revenue') }}</p><strong>{{ client.tri_revenue == null ? '—' : formatMoney(client.tri_revenue,'CZK',0) }}</strong></div><div><p class="text-xs text-neutral-500 mb-1">{{ t('common.last_activity') }}</p>{{ client.tri_last_job_date ? formatDate(client.tri_last_job_date) : '—' }}</div></div>
      </UiCard>
      <UiCard padding><h2 class="mb-5">{{ t('office.billing_settings') }}</h2><dl class="space-y-4"><div class="flex justify-between gap-4"><dt>{{ t('client.language_label') }}</dt><dd>{{ client.language?.toUpperCase() || '—' }}</dd></div><div class="flex justify-between gap-4"><dt>{{ t('common.currency') }}</dt><dd>{{ client.currency_default || '—' }}</dd></div><div class="flex justify-between gap-4"><dt>{{ t('client.due_label') }}</dt><dd>{{ formatPaymentDue(client) }}</dd></div><div class="flex justify-between gap-4"><dt>{{ t('client.rc_label') }}</dt><dd>{{ client.reverse_charge ? t('client.yes_short') : t('client.no_short') }}</dd></div></dl><UiButton v-if="myuctoHref" :href="myuctoHref" variant="secondary" target="_blank" rel="noopener" class="mt-5">{{ t('office.open_myucto') }}</UiButton></UiCard>
    </div>
    <UiCard v-if="client.party_kind === 'company'">
      <h2 class="p-5">{{ t('tri.contacts.people') }}</h2>
      <EmptyState v-if="!client.people?.length" compact :title="t('tri.contacts.no_people')" />
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="person in client.people" :key="person.id" class="px-5 py-3">
          <RouterLink :to="`/tri/contacts/${person.id}`" class="font-medium text-primary-700">{{ person.company_name }}</RouterLink>
          <p class="text-sm text-neutral-600">{{ person.main_email || '—' }}<span v-if="person.phone"> · {{ person.phone }}</span></p>
        </li>
      </ul>
      <form v-if="auth.canWrite" class="grid gap-2 border-t border-neutral-200 p-5" @submit.prevent="addPerson">
        <UiInput v-model="personName" size="sm" :label="t('tri.jobs.person_name')" />
        <UiInput v-model="personEmail" size="sm" type="email" :label="t('tri.jobs.person_email')" />
        <UiInput v-model="personPhone" size="sm" :label="t('client.phone')" />
        <div class="flex justify-end">
          <UiButton type="submit" size="sm" variant="outline" :loading="personSaving">{{ t('tri.contacts.add_person') }}</UiButton>
        </div>
      </form>
    </UiCard>
    <UiCard><h2 class="p-5">{{ t('tri.jobs.title') }}</h2><p v-if="jobsError" class="office-error">{{ jobsError }}</p><EmptyState v-else-if="!jobs.length" compact :title="t('tri.jobs.no_jobs')" /><UiTable v-else><template #head><tr><th class="px-4 text-left">{{ t('tri.jobs.number') }}</th><th class="px-4 text-left">{{ t('tri.jobs.title_field') }}</th><th class="px-4 text-left">{{ t('office.author') }}</th><th class="px-4 text-left">{{ t('tri.jobs.status') }}</th></tr></template><tr v-for="job in jobs" :key="job.id"><td class="px-4"><RouterLink :to="`/tri/jobs/${job.id}`" class="text-primary-700">{{ job.number }}</RouterLink></td><td class="px-4">{{ job.title }}</td><td class="px-4">{{ job.owner_name }}</td><td class="px-4"><UiBadge :variant="job.status==='confirmed'?'success':'primary'">{{ t(`tri.jobs.status_${job.status}`) }}</UiBadge></td></tr></UiTable><div v-if="jobsPage<jobsPages" class="flex justify-center p-4"><UiButton variant="secondary" :loading="jobsMore" @click="loadJobs(false)">{{ t('office.more') }}</UiButton></div></UiCard>
    <UiCard><div class="p-5 flex justify-between items-center"><h2>{{ t('tri.invoices.title') }}</h2><UiButton v-if="auth.canWrite" :to="{path:'/tri/invoices/new',query:{client_id:client.id}}" variant="secondary">{{ t('tri.invoices.new') }}</UiButton></div><EmptyState v-if="!invoices.length" compact :title="t('common.no_data')" /><UiTable v-else><template #head><tr><th class="px-4 text-left">{{ t('invoice.varsymbol') }}</th><th class="px-4 text-left">{{ t('invoice.type') }}</th><th class="px-4 text-left">{{ t('invoice.issue_date') }}</th><th class="px-4 text-left">{{ t('invoice.due_date') }}</th><th class="px-4 text-left">{{ t('invoice.status_label') }}</th><th class="px-4 text-right">{{ t('invoice.amount_to_pay') }}</th></tr></template><tr v-for="inv in invoices" :key="inv.id"><td class="px-4"><RouterLink :to="`/tri/invoices/${inv.id}`" class="text-primary-700">{{ inv.varsymbol || `${t('status.draft')} #${inv.id}` }}</RouterLink></td><td class="px-4">{{ typeLabel(inv.invoice_type) }}</td><td class="px-4">{{ formatDate(inv.issue_date) }}</td><td class="px-4">{{ formatDate(inv.due_date) }}</td><td class="px-4"><UiBadge :variant="inv.status==='paid'?'success':inv.status==='draft'?'draft':'primary'">{{ statusLabel(inv.status) }}</UiBadge></td><td class="px-4 text-right font-semibold whitespace-nowrap">{{ formatMoney(listedAmount(inv), inv.currency) }}</td></tr></UiTable><div v-if="invoicesPage<invoicesPages" class="flex justify-center p-4"><UiButton variant="secondary" :loading="invoicesLoadingMore" @click="loadMoreInvoices">{{ t('office.more') }}</UiButton></div></UiCard>
    <Modal v-if="rolesOpen" :title="t('office.edit_roles')" width-class="max-w-lg" @close="rolesOpen=false; refreshRoles()"><ClientTagEditor :client-id="client.id" :save-label="t('office.save_roles')" @saved="refreshRoles();rolesOpen=false" /></Modal>
  </div>
  <div v-else-if="error" class="office-error" role="alert">{{ error }}<UiButton @click="load">{{ t('office.retry') }}</UiButton></div>
</template>

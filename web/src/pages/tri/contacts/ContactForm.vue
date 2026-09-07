<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientsApi, type ClientPayload, type Client } from '@/api/clients'
import { triApi } from '@/api/tri'
import { codebooksApi, type Country, type Currency } from '@/api/codebooks'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import ClientTagEditor from '@/components/tri/ClientTagEditor.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'

/**
 * V `embedded` módu komponenta nečte route pro edit, neredirektuje a vrací výsledek
 * přes `@created` event. Používá se v modal okně (JobForm…).
 */
const props = withDefaults(defineProps<{ embedded?: boolean }>(), {
  embedded: false,
})
const emit = defineEmits<{
  (e: 'created', client: Client): void
  (e: 'cancel'): void
}>()

const { t, locale } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const route = useRoute()
const router = useRouter()

const isEdit = computed(() =>
  !props.embedded && route.params.id !== undefined && route.params.id !== 'new'
)
const clientId = computed(() => (isEdit.value ? Number(route.params.id) : null))

type ClientDuePreset = 'inherit' | '7' | '14' | 'month' | 'custom'
const dueCustom = ref(false)
const clientDuePreset = computed<ClientDuePreset>({
  get() {
    if (dueCustom.value) return 'custom'
    const d = form.value.payment_due_default
    const u = form.value.payment_due_unit
    if (d == null && u == null) return 'inherit'
    if (u === 'month' && d === 1) return 'month'
    if ((u === 'days' || u == null) && d === 7) return '7'
    if ((u === 'days' || u == null) && d === 14) return '14'
    return 'custom'
  },
  set(v: ClientDuePreset) {
    dueCustom.value = (v === 'custom')
    if (v === 'inherit') {
      form.value.payment_due_default = null
      form.value.payment_due_unit = null
    } else if (v === '7') {
      form.value.payment_due_default = 7
      form.value.payment_due_unit = 'days'
    } else if (v === '14') {
      form.value.payment_due_default = 14
      form.value.payment_due_unit = 'days'
    } else if (v === 'month') {
      form.value.payment_due_default = 1
      form.value.payment_due_unit = 'month'
    } else {
      if (form.value.payment_due_default == null) form.value.payment_due_default = 30
      form.value.payment_due_unit = 'days'
    }
  },
})

const supplierDueLabel = computed(() => {
  const sup = supplierStore.currentSupplier
  if (!sup) return t('client.payment_due_inherit_fallback')
  const d = sup.default_payment_due_days
  const u = sup.default_payment_due_unit
  if (u === 'month' && d === 1) return t('client.payment_due_preset_month').toLowerCase()
  if (u === 'days') return `${d} ${t('client.payment_due_custom_days_suffix')}`
  return `${d}× ${t('client.payment_due_preset_month').toLowerCase()}`
})

const form = ref<ClientPayload>({
  company_name: '',
  ic: null,
  dic: null,
  street: '',
  city: '',
  zip: '',
  country_iso2: 'CZ',
  main_email: '',
  phone: null,
  language: 'cs',
  currency_default_id: 0,
  reverse_charge: false,
  is_vat_payer: true,
  is_customer: true,
  is_vendor: false,
  auto_send_reminders: true,
  payment_due_default: null,
  payment_due_unit: null,
  hourly_rate: 0,
  note: null,
  default_expense_category_id: null,
  default_revenue_category_id: null,
  invoice_number_format: null,
  proforma_number_format: null,
  credit_note_number_format: null,
  invoice_number_period: null,
})

const lockCustomer = ref(false)
const lockVendor = ref(false)

const countries = ref<Country[]>([])
const currencies = ref<Currency[]>([])
const revenueCategories = ref<RevenueCategory[]>([])
const submitting = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const aresLoading = ref(false)
const viesLoading = ref(false)
const viesResult = ref<import('@/api/clients').ViesLookupResult | null>(null)
const duplicateIc = ref<{ id: number; name: string } | null>(null)
const duplicateDic = ref<{ id: number; name: string } | null>(null)
const tagEditorRef = ref<InstanceType<typeof ClientTagEditor> | null>(null)

const vatInfoOpen = ref(false)
const vatInfoLoading = ref(false)
const vatInfo = ref<import('@/api/clients').BankLookupResult | null>(null)
const vatInfoError = ref('')

async function loadVatPayerDetails() {
  const dic = (form.value.dic || '').replace(/\D/g, '')
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

onMounted(async () => {
  const [c, cur, rc] = await Promise.all([
    codebooksApi.countries(),
    codebooksApi.currencies(),
    revenueCategoriesApi.list(false).catch(() => [] as RevenueCategory[]),
  ])
  countries.value = c
  currencies.value = cur
  revenueCategories.value = rc
  if (form.value.currency_default_id === 0) {
    const def = cur.find(x => x.is_default && x.code === 'CZK') || cur[0]
    if (def) form.value.currency_default_id = def.id
  }
  if (isEdit.value && clientId.value) {
    const loaded = await clientsApi.get(clientId.value)
    Object.assign(form.value, sanitize(loaded))
    lockCustomer.value = (loaded.invoices_count ?? 0) > 0
    lockVendor.value = (loaded.purchase_invoices_count ?? 0) > 0
  }
})

function sanitize(c: Client): Partial<ClientPayload> {
  return {
    company_name: c.company_name,
    first_name: c.first_name ?? null,
    last_name: c.last_name ?? null,
    ic: c.ic ?? null,
    dic: c.dic ?? null,
    street: c.street,
    city: c.city,
    zip: c.zip,
    country_iso2: c.country_iso2,
    main_email: c.main_email,
    phone: c.phone ?? null,
    language: c.language,
    currency_default_id: c.currency_default_id,
    reverse_charge: c.reverse_charge,
    is_vat_payer: c.is_vat_payer ?? true,
    is_customer: c.is_customer !== false,
    is_vendor: c.is_vendor === true,
    auto_send_reminders: c.auto_send_reminders ?? true,
    payment_due_default: c.payment_due_default ?? null,
    payment_due_unit: c.payment_due_unit ?? null,
    hourly_rate: c.hourly_rate ?? 0,
    note: c.note ?? null,
    default_expense_category_id: c.default_expense_category_id ?? null,
    default_revenue_category_id: c.default_revenue_category_id ?? null,
    invoice_number_format: c.invoice_number_format ?? null,
    proforma_number_format: c.proforma_number_format ?? null,
    credit_note_number_format: c.credit_note_number_format ?? null,
    invoice_number_period: c.invoice_number_period ?? null,
  }
}

async function loadFromAres() {
  if (!form.value.ic) return
  aresLoading.value = true
  error.value = ''
  try {
    const result = await triApi.contacts.lookupAres(form.value.ic)
    if (!result.found || !result.data) {
      error.value = t('supplier.ares_not_found')
      return
    }
    const d = result.data
    form.value.company_name = d.company_name
    form.value.dic = d.dic || null
    form.value.street = d.street
    form.value.city = d.city
    form.value.zip = d.zip
    form.value.country_iso2 = d.country_iso2 || 'CZ'
    form.value.is_vat_payer = d.is_vat_payer
    checkDuplicateIc()
    checkDuplicateDic()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('supplier.ares_failed')
  } finally {
    aresLoading.value = false
  }
}

async function checkDuplicateIc() {
  if (isEdit.value) return
  const ic = (form.value.ic || '').trim()
  if (!ic) { duplicateIc.value = null; return }
  try {
    const res = await clientsApi.list({ q: ic, per_page: 5 })
    const match = res.data.find(c => (c.ic || '').trim() === ic)
    duplicateIc.value = match ? { id: match.id, name: match.company_name } : null
  } catch { /* silent */ }
}

async function checkDuplicateDic() {
  if (isEdit.value) return
  const dic = (form.value.dic || '').trim()
  if (!dic) { duplicateDic.value = null; return }
  try {
    const res = await clientsApi.list({ q: dic, per_page: 5 })
    const match = res.data.find(c => (c.dic || '').trim() === dic)
    duplicateDic.value = match ? { id: match.id, name: match.company_name } : null
  } catch { /* silent */ }
}

async function checkVies() {
  if (!form.value.dic) return
  viesLoading.value = true
  viesResult.value = null
  try {
    const result = await clientsApi.lookupVies(form.value.dic)
    viesResult.value = result
    if (result.source !== 'error') form.value.is_vat_payer = result.valid
    if (result.valid) {
      if (result.name && !form.value.company_name) form.value.company_name = result.name
      if (result.country && !form.value.street) form.value.country_iso2 = result.country
      if (result.parsed && !form.value.street) {
        form.value.street = result.parsed.street
        form.value.city = result.parsed.city
        form.value.zip = result.parsed.zip
      }
    }
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('client.vies_lookup_failed')
  } finally {
    viesLoading.value = false
  }
}

async function submit() {
  submitting.value = true
  error.value = ''
  errors.value = {}
  try {
    if (isEdit.value && clientId.value) {
      const updated = await triApi.contacts.update(clientId.value, form.value as unknown as Record<string, unknown>)
      await tagEditorRef.value?.save()
      const revBackfilled = updated.revenue_category_backfilled ?? 0
      if (revBackfilled > 0) {
        toast.success(t('client.default_revenue_category_backfilled', { count: revBackfilled }))
      }
      router.push(`/tri/contacts/${clientId.value}`)
    } else {
      const created = await triApi.contacts.create(form.value as unknown as Record<string, unknown>)
      if (tagEditorRef.value) {
        await triApi.tags.setClient(created.id, tagEditorRef.value.selected)
      }
      if (props.embedded) { emit('created', created); return }
      router.push(`/tri/contacts/${created.id}`)
    }
  } catch (e: any) {
    const data = e?.response?.data?.error
    error.value = data?.message || t('errors.generic')
    if (data?.fields) errors.value = data.fields
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div :class="embedded ? '' : 'max-w-3xl'">
    <div v-if="!embedded">
      <UiPageHeader :title="isEdit ? t('tri.contacts.edit_title') : t('tri.contacts.new_title')">
        <template #actions>
          <UiButton to="/tri/contacts" variant="ghost" size="sm">{{ t('tri.contacts.back_to_list') }}</UiButton>
        </template>
      </UiPageHeader>
    </div>

    <form @submit.prevent="submit" autocomplete="off">
      <UiCard>
      <div class="p-5 space-y-4">
        <!-- Lookup helpers -->
        <div class="bg-primary-50 border border-primary-200 rounded-md p-3">
          <div class="text-xs font-semibold text-primary-800 mb-2">{{ t('client.lookup_in_registries') }}</div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('client.ic') }}</label>
              <div class="flex gap-2">
                <input autocomplete="off" v-model="form.ic" maxlength="8" placeholder="12345678"
                  @blur="checkDuplicateIc"
                  class="flex-1 h-9 px-3 border border-neutral-300 rounded-md font-mono text-sm shadow-xs outline-none focus-ring" />
                <UiButton type="button" variant="outline" size="sm" :loading="aresLoading" :disabled="!form.ic || aresLoading" @click="loadFromAres">
                  ARES
                </UiButton>
              </div>
              <p v-if="duplicateIc" class="text-xs text-warning-600 mt-1">
                ⚠ {{ t('client.duplicate_ic') }} <strong>{{ duplicateIc.name }}</strong>
                <RouterLink :to="`/tri/contacts/${duplicateIc.id}`" class="text-primary-700 hover:underline ml-1">{{ t('client.open_existing') }} →</RouterLink>
              </p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('client.dic') }}</label>
              <div class="flex gap-2">
                <input autocomplete="off" v-model="form.dic" placeholder="CZ12345678"
                  @blur="checkDuplicateDic"
                  class="flex-1 h-9 px-3 border border-neutral-300 rounded-md font-mono text-sm shadow-xs outline-none focus-ring" />
                <UiButton type="button" variant="outline" size="sm" :loading="viesLoading" :disabled="!form.dic || viesLoading" @click="checkVies">
                  VIES
                </UiButton>
              </div>
              <p v-if="duplicateDic" class="text-xs text-warning-600 mt-1">
                ⚠ {{ t('client.duplicate_dic') }} <strong>{{ duplicateDic.name }}</strong>
                <RouterLink :to="`/tri/contacts/${duplicateDic.id}`" class="text-primary-700 hover:underline ml-1">{{ t('client.open_existing') }} →</RouterLink>
              </p>
            </div>
          </div>
          <div v-if="viesResult" class="mt-2 text-xs">
            <span v-if="viesResult.valid" class="text-primary-700">✓ {{ t('client.dic_valid', { dic: t('client.dic'), name: viesResult.name }) }}</span>
            <span v-else class="text-danger-500">✗ {{ t('client.dic_invalid', { dic: t('client.dic') }) }}</span>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-4 pt-3 border-t border-neutral-100">
            <label class="inline-flex items-center gap-2 text-sm">
              <input v-model="form.is_vat_payer" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :class="!form.is_vat_payer ? 'text-warning-700 font-medium' : ''">{{ t('client.vat_payer_label') }}</span>
            </label>
            <UiButton v-if="form.dic" type="button" variant="outline" size="sm" :loading="vatInfoLoading" :disabled="vatInfoLoading" @click="loadVatPayerDetails">
              {{ vatInfoLoading ? t('common.loading') : t('client.vat_payer_details') }}
            </UiButton>
          </div>

          <div v-if="vatInfoOpen" class="mt-3 bg-neutral-50 border border-neutral-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
              <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('client.vat_payer_details') }}</h4>
              <button type="button" @click="vatInfoOpen = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-sm leading-none">✕</button>
            </div>
            <div v-if="vatInfoLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
            <div v-else-if="vatInfoError" class="text-sm text-danger-500">{{ vatInfoError }}</div>
            <template v-else-if="vatInfo">
              <div v-if="vatInfo.source === 'error'" class="text-sm text-warning-600">{{ t('client.vat_payer_unavailable') }}</div>
              <div v-else-if="!vatInfo.found" class="text-sm text-neutral-600">{{ t('client.vat_payer_not_registered') }}</div>
              <div v-else class="space-y-2 text-sm">
                <div class="flex items-center gap-2">
                  <span class="text-neutral-500">{{ t('client.vat_payer_reliability') }}:</span>
                  <span v-if="vatInfo.unreliable === true" class="px-2 py-0.5 rounded bg-danger-50 text-danger-600 font-medium">{{ t('client.vat_payer_unreliable') }}</span>
                  <span v-else-if="vatInfo.unreliable === false" class="px-2 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('client.vat_payer_reliable') }}</span>
                  <span v-else class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t('client.vat_payer_unknown') }}</span>
                </div>
                <div>
                  <div class="text-neutral-500 mb-1">{{ t('client.vat_payer_accounts') }}:</div>
                  <ul v-if="vatInfo.accounts.length" class="space-y-0.5">
                    <li v-for="(a, i) in vatInfo.accounts" :key="i" class="font-mono text-neutral-900">{{ a.display }}</li>
                  </ul>
                  <div v-else class="text-neutral-500">{{ t('client.vat_payer_no_accounts') }}</div>
                </div>
                <p class="text-xs text-neutral-400">{{ t('client.vat_payer_source') }}</p>
              </div>
            </template>
          </div>
        </div>

        <UiInput v-model="form.company_name" :label="t('client.company_name') + ' *'" required :error="errors.company_name?.[0]" />

        <ClientTagEditor
          ref="tagEditorRef"
          :client-id="clientId"
          :readonly="!auth.canWrite"
        />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <UiInput v-model="form.main_email" type="email" :label="t('client.main_email')" :hint="t('client.main_email_optional_hint')" :error="errors.main_email?.[0]" />
          <UiInput v-model="form.phone" :label="t('client.phone')" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <UiInput class="sm:col-span-2" v-model="form.street" :label="t('client.street')" />
          <UiInput v-model="form.zip" :label="t('client.zip')" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <UiInput v-model="form.city" :label="t('client.city')" />
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.country') }}</label>
            <select v-model="form.country_iso2"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring">
              <option v-for="c in countries" :key="c.iso2" :value="c.iso2">{{ locale === 'en' ? c.name_en : c.name_cs }}</option>
            </select>
          </div>
        </div>

        <!-- Role flagy -->
        <div class="pt-3 border-t border-neutral-100">
          <p class="text-xs text-neutral-500 mb-2">{{ t('client.roles_hint') }}</p>
          <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 text-sm">
              <input
                v-model="form.is_customer"
                type="checkbox"
                :disabled="lockCustomer && form.is_customer"
                class="rounded border-neutral-300 text-primary-600 disabled:opacity-50"
              />
              <span>{{ t('client.is_customer_label') }}</span>
              <span v-if="lockCustomer" class="text-xs text-neutral-400 italic">
                ({{ t('client.locked_has_invoices') }})
              </span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input
                v-model="form.is_vendor"
                type="checkbox"
                :disabled="lockVendor && form.is_vendor"
                class="rounded border-neutral-300 text-primary-600 disabled:opacity-50"
              />
              <span>{{ t('client.is_vendor_label') }}</span>
              <span v-if="lockVendor" class="text-xs text-neutral-400 italic">
                ({{ t('client.locked_has_purchases') }})
              </span>
            </label>
          </div>
          <p v-if="!form.is_customer && !form.is_vendor" class="text-xs text-danger-600 mt-1">
            {{ t('client.roles_required') }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.note') }}</label>
          <textarea autocomplete="off" v-model="form.note" rows="2"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md shadow-xs outline-none focus-ring"></textarea>
        </div>

        <!-- Ostatní informace (sbalené, výchozí stav) -->
        <details class="pt-3 border-t border-neutral-100">
          <summary class="cursor-pointer text-sm font-medium text-neutral-700">
            {{ t('tri.contacts.other_info_section') }}
          </summary>
          <div class="mt-3 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.language') }}</label>
                <select v-model="form.language"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring">
                  <option value="cs">Čeština</option>
                  <option value="en">English</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.payment_due_label') }}</label>
                <div class="flex gap-2 items-center">
                  <select v-model="clientDuePreset"
                    class="flex-1 min-w-0 h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface shadow-xs outline-none focus-ring">
                    <option value="inherit">{{ t('client.payment_due_inherit', { default: supplierDueLabel }) }}</option>
                    <option value="7">{{ t('client.payment_due_preset_7') }}</option>
                    <option value="14">{{ t('client.payment_due_preset_14') }}</option>
                    <option value="month">{{ t('client.payment_due_preset_month') }}</option>
                    <option value="custom">{{ t('client.payment_due_preset_custom') }}</option>
                  </select>
                  <div v-if="clientDuePreset === 'custom'" class="flex items-center gap-1.5 shrink-0">
                    <input autocomplete="off" v-model.number="form.payment_due_default" type="number" min="1" max="365"
                      class="w-20 h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono shadow-xs outline-none focus-ring" />
                    <span class="text-xs text-neutral-500">{{ t('client.payment_due_custom_days_suffix') }}</span>
                  </div>
                </div>
                <p v-if="clientDuePreset === 'month'" class="text-xs text-neutral-500 mt-1">{{ t('client.payment_due_month_hint') }}</p>
              </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.currency_default') }}</label>
                <select v-model.number="form.currency_default_id"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.hourly_rate') }}</label>
                <input autocomplete="off" v-model.number="form.hourly_rate" type="number" step="0.01" min="0" placeholder="0"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono shadow-xs outline-none focus-ring" />
                <p class="text-xs text-neutral-500 mt-1">{{ t('client.hourly_rate_hint') }}</p>
                <p v-if="errors.hourly_rate" class="text-xs text-danger-500 mt-1">{{ errors.hourly_rate[0] }}</p>
              </div>
            </div>

            <div class="space-y-2">
              <label class="flex items-center gap-2 text-sm">
                <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span>{{ t('client.reverse_charge') }}</span>
              </label>
              <div>
                <label class="flex items-center gap-2 text-sm">
                  <input v-model="form.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                  <span>{{ t('client.auto_send_reminders') }}</span>
                </label>
                <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('client.auto_send_reminders_hint') }}</p>
              </div>
            </div>

            <div v-if="form.is_customer">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('client.default_revenue_category') }}</label>
              <select v-model="form.default_revenue_category_id"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring">
                <option :value="null">— {{ t('client.default_revenue_category_none') }} —</option>
                <option v-for="c in revenueCategories" :key="c.id" :value="c.id">
                  {{ c.label }} ({{ c.code }})
                </option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('client.default_revenue_category_hint') }}</p>
            </div>

            <!-- Vlastní číselná řada (volitelná) -->
            <div class="pt-3 border-t border-neutral-100 space-y-3">
              <h3 class="text-sm font-medium text-neutral-700">{{ t('client.numbering_section') }}</h3>
              <p class="text-xs text-neutral-500">{{ t('client.numbering_hint') }}</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.invoice_number_format') }}</label>
                  <input v-model="form.invoice_number_format" type="text" maxlength="60" placeholder="{YY}{CCCC}"
                    class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm shadow-xs outline-none focus-ring" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.proforma_number_format') }}</label>
                  <input v-model="form.proforma_number_format" type="text" maxlength="60" placeholder="9{YY}{CCCC}"
                    class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm shadow-xs outline-none focus-ring" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.credit_note_number_format') }}</label>
                  <input v-model="form.credit_note_number_format" type="text" maxlength="60" placeholder=""
                    class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm shadow-xs outline-none focus-ring" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('client.invoice_number_period') }}</label>
                  <select v-model="form.invoice_number_period"
                    class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring">
                    <option :value="null">{{ t('client.numbering_period_inherit') }}</option>
                    <option value="year">{{ t('client.numbering_period_year') }}</option>
                    <option value="month">{{ t('client.numbering_period_month') }}</option>
                    <option value="none">{{ t('client.numbering_period_none') }}</option>
                  </select>
                </div>
              </div>
              <p class="text-xs text-neutral-500">{{ t('client.numbering_placeholders_hint') }}</p>
            </div>
          </div>
        </details>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>
      </div>

      <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 flex justify-end gap-3 rounded-b-lg">
        <UiButton v-if="embedded" type="button" variant="secondary" @click="emit('cancel')">{{ t('common.cancel') }}</UiButton>
        <UiButton v-else to="/tri/contacts" variant="secondary">{{ t('common.cancel') }}</UiButton>
        <UiButton type="submit" :loading="submitting" :disabled="submitting">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </UiButton>
      </div>
      </UiCard>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi } from '@/api/tri'
import { clientsApi, type Client } from '@/api/clients'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import TriContactFormModal from '@/components/tri/TriContactFormModal.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const isEdit = computed(() => route.name === 'tri-job-edit')
const jobId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const saving = ref(false)
const numberOk = ref(true)

type ContactOption = { value: number; label: string; secondary?: string; party_kind?: string }
type UserOption = { value: number; label: string }

const contactCache = ref(new Map<number, ContactOption>())
const contactOptions = ref<ContactOption[]>([])
const contactsLoading = ref(false)
const contactPickerValue = ref<number | null>(null)
const contactModalOpen = ref(false)
const editingContactId = ref<number | null>(null)

const userOptions = ref<UserOption[]>([])
const usersLoading = ref(false)
const assigneePickerValue = ref<number | null>(null)

type ContactAddress = {
  company_name: string
  street: string
  city: string
  zip: string
  country_iso2: string
}

const contactAddressCache = ref(new Map<number, ContactAddress>())
const siteAddressPickerValue = ref<number | null>(null)

const form = ref({
  number: '',
  title: '',
  notes: '',
  site_street: '',
  site_city: '',
  site_zip: '',
  site_country: 'CZ',
  contacts: [] as Array<{ client_id: number; role: 'customer' | 'architect' | 'contact' }>,
  assignee_user_ids: [] as number[],
})

function clientToOption(c: { id: number; company_name: string; ic?: string | null; party_kind?: string | null }): ContactOption {
  return { value: c.id, label: c.company_name, secondary: c.ic ?? undefined, party_kind: c.party_kind ?? undefined }
}

function cacheContact(c: { id: number; company_name: string; ic?: string | null }) {
  const opt = clientToOption(c)
  contactCache.value.set(c.id, opt)
}

function cacheContacts(list: Array<{ id: number; company_name: string; ic?: string | null }>) {
  for (const c of list) cacheContact(c)
}

const selectedContacts = computed(() =>
  form.value.contacts.map((c) => ({
    ...(contactCache.value.get(c.client_id) ?? { value: c.client_id, label: `#${c.client_id}` }),
    role: c.role,
  }))
)

const selectedAssignees = computed(() =>
  form.value.assignee_user_ids.map((id) => userOptions.value.find((u) => u.value === id) ?? { value: id, label: `#${id}` })
)

function hasMeaningfulAddress(addr: { street?: string | null; city?: string | null }) {
  return Boolean(addr.street?.trim() || addr.city?.trim())
}

function formatAddressSecondary(addr: { street: string; city: string; zip: string }) {
  return [addr.street, [addr.zip, addr.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
}

function cacheContactAddress(
  id: number,
  companyName: string,
  addr: { street?: string | null; city?: string | null; zip?: string | null; country_iso2?: string | null },
) {
  contactAddressCache.value.set(id, {
    company_name: companyName,
    street: addr.street?.trim() ?? '',
    city: addr.city?.trim() ?? '',
    zip: addr.zip?.trim() ?? '',
    country_iso2: addr.country_iso2?.trim() || 'CZ',
  })
}

const siteAddressOptions = computed((): ContactOption[] => {
  const opts: ContactOption[] = []
  for (const row of form.value.contacts) {
    const id = row.client_id
    const cached = contactAddressCache.value.get(id)
    if (!cached || !hasMeaningfulAddress(cached)) continue
    opts.push({
      value: id,
      label: cached.company_name,
      secondary: formatAddressSecondary(cached),
    })
  }
  return opts
})

function onSiteAddressPicked(id: number | null) {
  if (id == null) return
  const addr = contactAddressCache.value.get(id)
  if (!addr) return
  form.value.site_street = addr.street
  form.value.site_city = addr.city
  form.value.site_zip = addr.zip
  form.value.site_country = addr.country_iso2
  siteAddressPickerValue.value = null
}

async function ensureContactAddress(id: number) {
  if (contactAddressCache.value.has(id)) return
  try {
    const client = await clientsApi.get(id)
    cacheContactAddress(client.id, client.company_name, client)
  } catch { /* ignore */ }
}

async function ensureContactAddresses(ids: number[]) {
  await Promise.all(ids.map((id) => ensureContactAddress(id)))
}

watch(
  () => form.value.contacts.map((c) => c.client_id),
  (ids, prev) => {
    const newIds = ids.filter((id) => !prev?.includes(id))
    if (newIds.length) void ensureContactAddresses(newIds)
  },
)

async function onContactSearch(q: string) {
  contactsLoading.value = true
  try {
    const res = await clientsApi.list({ q: q || undefined, archived: false, per_page: 50, role: 'all' })
    cacheContacts(res.data)
    const selected = new Set(form.value.contacts.map((c) => c.client_id))
    contactOptions.value = res.data
      .filter((c) => !selected.has(c.id))
      .map(clientToOption)
  } catch { /* ignore */ } finally {
    contactsLoading.value = false
  }
}

function onContactPicked(id: number | null) {
  if (id == null) return
  if (!form.value.contacts.some((c) => c.client_id === id)) {
    form.value.contacts = [...form.value.contacts, { client_id: id, role: 'contact' }]
    void ensureContactAddress(id)
  }
  contactPickerValue.value = null
}

function removeContact(id: number) {
  form.value.contacts = form.value.contacts.filter((c) => c.client_id !== id)
}

async function loadUsers() {
  usersLoading.value = true
  try {
    const users = await triApi.jobs.listUsers()
    userOptions.value = users.map((u) => ({ value: u.id, label: u.name }))
  } catch { /* ignore */ } finally {
    usersLoading.value = false
  }
}

function onAssigneePicked(id: number | null) {
  if (id == null) return
  if (!form.value.assignee_user_ids.includes(id)) {
    form.value.assignee_user_ids = [...form.value.assignee_user_ids, id]
  }
  assigneePickerValue.value = null
}

function removeAssignee(id: number) {
  form.value.assignee_user_ids = form.value.assignee_user_ids.filter((x) => x !== id)
}

function openNewContact() {
  editingContactId.value = null
  contactModalOpen.value = true
}

function openEditContact(id: number) {
  editingContactId.value = id
  contactModalOpen.value = true
}

function closeContactModal() {
  contactModalOpen.value = false
  editingContactId.value = null
}

function onContactCreated(client: Client) {
  cacheContact(client)
  cacheContactAddress(client.id, client.company_name, client)
  if (!form.value.contacts.some((c) => c.client_id === client.id)) {
    form.value.contacts = [...form.value.contacts, { client_id: client.id, role: 'contact' }]
  }
  closeContactModal()
}

async function suggestNumber() {
  const r = await triApi.jobs.suggestNumber()
  form.value.number = r.suggested
  await checkNumber()
}

async function checkNumber() {
  if (!form.value.number) return
  const r = await triApi.jobs.checkNumber(form.value.number, jobId.value ?? undefined)
  numberOk.value = r.available
}

async function loadJob() {
  if (!jobId.value) return
  const job = await triApi.jobs.get(jobId.value)
  for (const c of job.contacts ?? []) {
    cacheContact({ id: c.client_id, company_name: c.company_name, ic: c.ic })
    cacheContactAddress(c.client_id, c.company_name, c)
  }
  form.value = {
    number: job.number,
    title: job.title,
    notes: job.notes ?? '',
    site_street: job.site_street ?? '',
    site_city: job.site_city ?? '',
    site_zip: job.site_zip ?? '',
    site_country: job.site_country,
    contacts: (job.contacts ?? []).map((c) => ({
      client_id: c.client_id,
      role: c.role ?? 'contact',
    })),
    assignee_user_ids: (job.assignees ?? []).map((a) => a.user_id),
  }
}

async function save() {
  if (!form.value.title.trim()) {
    toast.error(t('tri.jobs.title_field'))
    return
  }
  if (!numberOk.value) {
    toast.error(t('tri.jobs.number_taken'))
    return
  }
  saving.value = true
  try {
    const payload = { ...form.value }
    if (isEdit.value && jobId.value) {
      await triApi.jobs.update(jobId.value, payload)
      router.push({ name: 'tri-job-detail', params: { id: jobId.value } })
    } else {
      const job = await triApi.jobs.create({
        ...payload,
        owner_user_id: auth.user?.id,
      })
      if (job.initial_variant_id) {
        router.push({
          name: 'tri-variant-edit',
          params: { jobId: job.id, variantId: job.initial_variant_id },
        })
      } else {
        router.push({ name: 'tri-job-detail', params: { id: job.id } })
      }
    }
    toast.success(t('common.saved'))
  } catch {
    toast.error(t('common.error'))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadUsers()
  if (isEdit.value) {
    await loadJob()
  } else {
    await suggestNumber()
    if (auth.user?.id) {
      form.value.assignee_user_ids = [auth.user.id]
    }
  }
})
</script>

<template>
  <div>
    <UiPageHeader
      :title="isEdit ? t('tri.jobs.edit') : t('tri.jobs.new')"
      :subtitle="isEdit ? undefined : t('tri.jobs.new_subtitle')"
    />

    <form @submit.prevent="save" class="space-y-6">
      <UiCard>
      <div class="space-y-4 p-5">
      <h2>{{ t('office.basic_info') }}</h2>
      <div class="grid items-start gap-4 lg:grid-cols-2">
      <div class="min-w-0 space-y-4">
        <div class="flex items-end gap-2">
          <UiInput
            v-model="form.number"
            class="min-w-0 flex-1"
            size="sm"
            :label="t('tri.jobs.number')"
            required
            :error="numberOk ? undefined : t('tri.jobs.number_taken')"
            @blur="checkNumber"
          />
          <UiButton type="button" variant="outline" size="sm" class="shrink-0" @click="suggestNumber">
            {{ t('tri.jobs.suggest_number') }}
          </UiButton>
        </div>
        <UiInput
          v-model="form.title"
          size="sm"
          :label="t('tri.jobs.title_field')"
          required
        />
      </div>
      <div class="min-w-0">
        <label class="mb-1 block text-sm font-medium text-neutral-700">{{ t('tri.jobs.owners') }}</label>
        <SearchableSelect
          :model-value="assigneePickerValue"
          :loading="usersLoading"
          :options="userOptions.filter((u) => !form.assignee_user_ids.includes(u.value))"
          :placeholder="t('tri.jobs.add_assignee_placeholder')"
          :clearable="true"
          @update:model-value="onAssigneePicked"
        />
        <div v-if="selectedAssignees.length" class="mt-2 flex flex-wrap gap-2">
          <span
            v-for="u in selectedAssignees"
            :key="u.value"
            class="inline-flex h-10 max-w-full items-center gap-2 border border-neutral-200 px-3 text-sm"
          >
            <span class="truncate">{{ u.label }}</span>
            <button type="button" class="cursor-pointer text-neutral-500" :title="t('common.remove')" @click="removeAssignee(u.value)">×</button>
          </span>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('tri.jobs.owners_hint') }}</p>
      </div>
      </div>

      </div>
      </UiCard>

      <div class="grid items-start gap-6 lg:grid-cols-2">
      <UiCard>
      <div class="space-y-4 p-5">
      <div class="flex items-center justify-between gap-3">
        <h2>{{ t('tri.jobs.contacts_section') }}</h2>
        <UiButton type="button" variant="outline" size="sm" @click="openNewContact">
          {{ t('tri.contacts.new_title') }}
        </UiButton>
      </div>
      <div class="space-y-3">
        <div v-for="c in selectedContacts" :key="c.value" class="flex items-center gap-2 border border-neutral-200 p-3">
          <p class="min-w-0 flex-1 truncate text-sm font-medium text-neutral-900">{{ c.label }}</p>
          <UiButton type="button" variant="outline" size="sm" @click="openEditContact(c.value)">{{ t('common.edit') }}</UiButton>
          <UiButton type="button" variant="outline" size="sm" @click="removeContact(c.value)">{{ t('common.remove') }}</UiButton>
        </div>
        <SearchableSelect
          :model-value="contactPickerValue"
          remote
          :loading="contactsLoading"
          :options="contactOptions"
          :placeholder="t('tri.jobs.add_contact_placeholder')"
          :clearable="true"
          @search="onContactSearch"
          @update:model-value="onContactPicked"
        />
        <p class="text-xs text-neutral-500">{{ t('tri.jobs.contact_hint') }}</p>
      </div>
      </div>
      </UiCard>

      <UiCard>
      <div class="space-y-4 p-5">
      <h2>{{ t('tri.quote.header_site_address') }}</h2>
      <div>
        <div class="mb-2">
          <SearchableSelect
            :model-value="siteAddressPickerValue"
            :options="siteAddressOptions"
            :placeholder="t('tri.jobs.pick_site_address')"
            :empty-label="t('tri.jobs.pick_site_address_hint')"
            :no-results-label="t('tri.jobs.no_contact_addresses')"
            :clearable="true"
            @update:model-value="onSiteAddressPicked"
          />
        </div>
        <UiInput
          v-model="form.site_street"
          size="sm"
          class="mb-2"
          :placeholder="t('client.street')"
        />
        <div class="grid grid-cols-2 gap-2">
          <UiInput
            v-model="form.site_city"
            size="sm"
            :placeholder="t('client.city')"
          />
          <UiInput
            v-model="form.site_zip"
            size="sm"
            :placeholder="t('client.zip')"
          />
        </div>
        <p class="text-xs text-neutral-500">{{ t('tri.jobs.address_hint') }}</p>
      </div>
      </div>
      </UiCard>
      </div>

      <UiCard>
      <div class="space-y-4 p-5">
        <h2>{{ t('tri.jobs.notes') }}</h2>
        <textarea
          v-model="form.notes"
          class="w-full border border-neutral-200 bg-surface px-3 py-2 text-sm outline-none focus-ring"
          rows="4"
        />
      </div>
      </UiCard>

      <div class="flex justify-end gap-3">
        <UiButton
          :to="isEdit && jobId ? { name: 'tri-job-detail', params: { id: jobId } } : '/tri/jobs'"
          variant="secondary"
        >
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton type="submit" :loading="saving" :disabled="saving">
          {{ t('common.save') }}
        </UiButton>
      </div>
    </form>

    <TriContactFormModal
      v-if="contactModalOpen"
      :client-id="editingContactId"
      @created="onContactCreated"
      @close="closeContactModal"
    />
  </div>
</template>

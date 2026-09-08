<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriQuoteImage } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import UiButton from '@/components/ui/UiButton.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'
import PriceListItemsTable, { type PriceListRow } from './PriceListItemsTable.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const isNew = computed(() => route.name === 'tri-price-list-new')
const listId = computed(() => (isNew.value ? null : Number(route.params.id)))

const loading = ref(false)
const saving = ref(false)
const deleting = ref(false)
let uidSeq = 0

const name = ref('')
const note = ref('')
const rows = ref<PriceListRow[]>([])

function newUid(): string {
  uidSeq += 1
  return `r${Date.now()}_${uidSeq}`
}

function blankRow(): PriceListRow {
  return {
    _uid: newUid(),
    designation: '',
    title: '',
    description: '',
    default_quantity: 1,
    unit: 'ks',
    base_unit_price: 0,
    vat_rate: 21,
    image_id: null,
    image: null,
    price_updated_at: null,
  }
}

function addRow() {
  rows.value.push(blankRow())
}

function itemsPayload() {
  return rows.value.map((row, i) => ({
    id: row.id,
    image_id: row.image_id ?? null,
    designation: row.designation,
    title: row.title,
    description: row.description || null,
    default_quantity: row.default_quantity,
    unit: row.unit || 'ks',
    base_unit_price: row.base_unit_price,
    vat_rate: row.vat_rate,
    sort_order: i,
  }))
}

async function load() {
  const id = listId.value
  if (!id) {
    name.value = ''
    note.value = ''
    rows.value = [blankRow()]
    return
  }
  loading.value = true
  try {
    const list = await triApi.priceLists.get(id)
    name.value = list.name
    note.value = list.note ?? ''
    rows.value = (list.items ?? []).map((item) => ({
      ...item,
      description: item.description ?? '',
      _uid: newUid(),
    }))
    if (!rows.value.length) rows.value = [blankRow()]
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    router.push({ name: 'tri-price-lists' })
  } finally {
    loading.value = false
  }
}

async function persistHeaderIfNeeded(): Promise<number> {
  const trimmed = name.value.trim()
  if (!trimmed) {
    throw new Error('NAME_REQUIRED')
  }
  if (listId.value) return listId.value
  const created = await triApi.priceLists.create({
    name: trimmed,
    note: note.value.trim() || null,
    items: itemsPayload(),
  })
  await router.replace({ name: 'tri-price-list-edit', params: { id: created.id } })
  name.value = created.name
  note.value = created.note ?? ''
  rows.value = (created.items ?? []).map((item) => ({
    ...item,
    description: item.description ?? '',
    _uid: newUid(),
  }))
  if (!rows.value.length) rows.value = [blankRow()]
  return created.id
}

async function uploadImage(file: File): Promise<TriQuoteImage> {
  try {
    const id = await persistHeaderIfNeeded()
    return triApi.priceLists.uploadImage(id, file)
  } catch (e) {
    if (e instanceof Error && e.message === 'NAME_REQUIRED') {
      toast.error(t('tri.price_lists.name_required'))
    }
    throw e
  }
}

async function save() {
  if (!name.value.trim()) {
    toast.error(t('tri.price_lists.name_required'))
    return
  }
  saving.value = true
  try {
    const payload = {
      name: name.value.trim(),
      note: note.value.trim() || null,
      items: itemsPayload(),
    }
    if (listId.value) {
      const saved = await triApi.priceLists.update(listId.value, payload)
      rows.value = (saved.items ?? []).map((item) => ({
        ...item,
        description: item.description ?? '',
        _uid: newUid(),
      }))
      if (!rows.value.length) rows.value = [blankRow()]
    } else {
      const created = await triApi.priceLists.create(payload)
      await router.replace({ name: 'tri-price-list-edit', params: { id: created.id } })
    }
    toast.success(t('tri.price_lists.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.save_failed')))
  } finally {
    saving.value = false
  }
}

async function remove() {
  const id = listId.value
  if (!id) {
    router.push({ name: 'tri-price-lists' })
    return
  }
  if (!window.confirm(t('tri.price_lists.delete_confirm', { name: name.value || '#' + id }))) return
  deleting.value = true
  try {
    await triApi.priceLists.delete(id)
    toast.success(t('tri.price_lists.deleted'))
    router.push({ name: 'tri-price-lists' })
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.delete_failed')))
  } finally {
    deleting.value = false
  }
}

const hasItems = computed(() =>
  rows.value.some((row) => !!(row.title || row.designation) || (row.base_unit_price ?? 0) > 0)
)

function openPdf() {
  const id = listId.value
  if (!id) return
  if (!hasItems.value) {
    toast.error(t('tri.price_lists.pdf_empty'))
    return
  }
  window.open(triApi.priceLists.pdfUrl(id, false), '_blank')
}

onMounted(() => load())
watch(listId, (id, prev) => {
  if (id && prev && id !== prev) load()
})
</script>

<template>
  <div>
    <router-link
      :to="{ name: 'tri-price-lists' }"
      class="inline-flex text-sm text-neutral-500 hover:text-neutral-800 mb-3"
    >
      ← {{ t('tri.price_lists.back_to_list') }}
    </router-link>

    <UiPageHeader :title="isNew ? t('tri.price_lists.new') : t('tri.price_lists.edit')">
      <template #actions>
        <UiButton v-if="listId" variant="outline" size="sm" @click="openPdf">
          {{ t('invoice.download_pdf') }}
        </UiButton>
        <UiButton v-if="auth.canWrite && listId" variant="danger" size="sm" :loading="deleting" @click="remove">
          {{ t('common.delete') }}
        </UiButton>
        <UiButton v-if="auth.canWrite" size="sm" :loading="saving" @click="save">
          {{ t('common.save') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <CardSkeleton v-if="loading" :blocks="2" />

    <div v-else class="space-y-5">
      <UiCard padding>
        <div class="grid gap-4 sm:grid-cols-2">
          <UiInput v-model="name" :label="t('tri.price_lists.name')" :disabled="!auth.canWrite" />
          <UiInput v-model="note" :label="t('tri.price_lists.note')" :disabled="!auth.canWrite" />
        </div>
      </UiCard>

      <UiCard padding>
        <h2 class="text-sm font-semibold text-neutral-800 mb-3">{{ t('tri.price_lists.items') }}</h2>
        <PriceListItemsTable
          v-model:rows="rows"
          :can-write="auth.canWrite"
          :upload="uploadImage"
          @add="addRow"
        />
      </UiCard>
    </div>
  </div>
</template>

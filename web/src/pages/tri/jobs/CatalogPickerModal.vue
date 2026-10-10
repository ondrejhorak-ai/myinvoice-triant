<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { pricingApi, type PriceList } from '@/api/triPricing'
import type { TriPriceListItem } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiSelect from '@/components/ui/UiSelect.vue'
const props = defineProps<{
  releaseId?: number | null
  effectiveDate?: string | null
  currency?: string
}>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{
  insert: [items: TriPriceListItem[], releaseId: number, effectiveDate: string]
}>()
const { t } = useI18n()
const lists = ref<PriceList[]>([]),
  chosen = ref<PriceList | null>(null),
  selected = ref(new Set<number>()),
  q = ref(''),
  date = ref(''),
  loading = ref(false),
  error = ref(''),
  listId = ref('')
const filtered = computed(() => {
  const seen = new Set<number>()
  return (chosen.value?.items ?? []).filter((i) => {
    const id = Number(i.product_id)
    if (seen.has(id)) return false
    seen.add(id)
    return `${i.code} ${i.title}`.toLowerCase().includes(q.value.toLowerCase())
  })
})
function listLabel(l: PriceList): string {
  const same = lists.value.filter((item) => item.name === l.name).length
  return same > 1 ? `${l.name} · #${l.id}` : `${l.name} · ${l.currency}`
}
async function choose() {
  if (!listId.value) return
  loading.value = true
  error.value = ''
  try {
    const l = await pricingApi.get(Number(listId.value))
    const r = l.releases.find(
      (r) =>
        r.status === 'published' &&
        r.valid_from! <= date.value &&
        (!r.valid_until || r.valid_until >= date.value),
    )
    if (!r) {
      chosen.value = null
      error.value = t('office.no_effective_release')
      return
    }
    chosen.value = await pricingApi.get(l.id, Number(r.id))
    selected.value = new Set()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
watch(open, async (yes) => {
  if (!yes) return
  selected.value = new Set()
  q.value = ''
  chosen.value = null
  error.value = ''
  date.value = props.effectiveDate || new Date().toLocaleDateString('en-CA')
  loading.value = true
  try {
    if (props.releaseId) {
      chosen.value = await pricingApi.release(props.releaseId)
    } else {
      lists.value = (await pricingApi.lists()).filter(
        (l) => l.currency === (props.currency || 'CZK'),
      )
      listId.value = ''
    }
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
})
function toggle(id: number) {
  const s = new Set(selected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selected.value = s
}
function insert() {
  const l = chosen.value
  if (!l?.release) return
  const items: TriPriceListItem[] = l.items
    .filter((i) => selected.value.has(Number(i.product_id)))
    .map((i) => ({
      id: Number(i.product_id),
      source_product_id: Number(i.product_id),
      source_release_id: Number(l.release!.id),
      designation: i.code,
      title: i.title,
      description: i.description,
      unit: i.unit,
      default_quantity: Number(i.default_quantity),
      base_unit_price: Number(i.unit_price),
      vat_rate: Number(i.vat_rate),
      image_id: i.image_id,
      image: i.image,
    }))
  emit('insert', items, Number(l.release.id), date.value)
  open.value = false
}
</script>
<template>
  <Modal
    v-if="open"
    :title="t('tri.quote.insert_from_catalog')"
    @close="open = false"
    ><div class="office-page space-y-4">
      <div v-if="!releaseId" class="grid grid-cols-2 gap-4">
        <UiSelect
          v-model="listId"
          :label="t('tri.price_lists.title')"
          @change="choose"
        >
          <option value="">{{ t('common.select') }}</option>
          <option v-for="l in lists" :key="l.id" :value="l.id">
            {{ listLabel(l) }}
          </option>
        </UiSelect><UiInput
          v-model="date"
          type="date"
          :label="t('office.effective_date')"
          @change="choose"
        />
      </div>
      <p v-if="chosen" class="bg-primary-50 p-3 text-sm">
        {{ chosen.name }} · {{ t('office.release') }}
        {{ chosen.release?.sequence }} · {{ date }}
      </p>
      <p v-if="releaseId" class="text-xs text-neutral-500">
        {{ t('office.snapshot_hint') }}
      </p>
      <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
      <UiInput v-model="q" type="search" :label="t('common.search')" />
      <p v-if="loading">{{ t('common.loading') }}</p>
      <div v-else class="max-h-[45vh] overflow-y-auto">
        <label
          v-for="i in filtered"
          :key="i.product_id"
          class="flex items-center gap-4 p-3 border-b border-neutral-200 cursor-pointer"
          ><input
            type="checkbox"
            :checked="selected.has(Number(i.product_id))"
            @change="toggle(Number(i.product_id))"
          /><span class="font-mono text-xs">{{ i.code }}</span
          ><span class="flex-1">{{ i.title }}</span
          ><span
            >{{ formatMoney(Number(i.unit_price), chosen?.currency) }} /
            {{ i.unit }}</span
          ></label
        >
        <p v-if="chosen && !filtered.length">
          {{ t('tri.quote.catalog_empty') }}
        </p>
      </div>
      <div class="flex justify-between items-center">
        <span>{{ t('tri.quote.catalog_selected', { n: selected.size }) }}</span
        ><UiButton :disabled="!selected.size" @click="insert">{{
          t('tri.quote.catalog_insert')
        }}</UiButton>
      </div>
    </div></Modal
  >
</template>

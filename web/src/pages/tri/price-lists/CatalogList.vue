<script setup lang="ts">
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

import { ref, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  pricingApi,
  type CatalogProduct,
  type ProductPrice,
} from '@/api/triPricing'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/api/errors'
import { usePagedRows } from '@/composables/tri/usePagedRows'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiTable from '@/components/ui/UiTable.vue'
import QuoteLineImageControl from '@/pages/tri/jobs/QuoteLineImageControl.vue'
import Modal from '@/components/ui/Modal.vue'
const { t } = useI18n(),
  auth = useAuthStore()
const q = ref(''),
  category = ref('')
const filtersOpen = ref(false), draftCategory = ref('')
function openFilters() { draftCategory.value = category.value; filtersOpen.value = true }
function applyFilters() { category.value = draftCategory.value.trim(); filtersOpen.value = false }
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'category', active: () => !!category.value, label: () => `${t('office.category')}: ${category.value}`, clear: () => { category.value = '' } },
])
const {
  items: rows,
  total,
  loading,
  error,
  load,
  page,
  pages,
} = usePagedRows<CatalogProduct>(async (page) => {
  const r = await pricingApi.catalog({
    page,
    q: q.value,
    category: category.value,
  })
  return {
    data: r.data,
    meta: { total: r.total, pages: Math.ceil(r.total / 50) },
  }
})
const hasMore = computed(() => page.value < pages.value)
const historyProduct = ref<CatalogProduct | null>(null),
  priceHistory = ref<ProductPrice[]>([]),
  historyError = ref('')
async function history(p: CatalogProduct) {
  historyProduct.value = p
  priceHistory.value = []
  historyError.value = ''
  try {
    priceHistory.value = await pricingApi.productPrices(p.id)
  } catch (e) {
    historyError.value = apiErrorMessage(e, t('common.error'))
  }
}
const editing = ref<(Partial<CatalogProduct> & { tagsText: string }) | null>(
    null,
  ),
  saving = ref(false),
  saveError = ref('')
function edit(p?: CatalogProduct) {
  saveError.value = ''
  editing.value = p
    ? { ...p, tagsText: JSON.parse(p.tags_json || '[]').join(', ') }
    : {
        code: '',
        title: '',
        unit: 'ks',
        vat_rate: 21,
        default_quantity: 1,
        category: '',
        description: '',
        parameters: '',
        tagsText: '',
      }
}
async function save() {
  if (!editing.value) return
  saving.value = true
  saveError.value = ''
  try {
    await pricingApi.product({
      ...editing.value,
      tags: editing.value.tagsText
        .split(',')
        .map((x) => x.trim())
        .filter(Boolean),
    })
    editing.value = null
    await load(true)
  } catch (e) {
    saveError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    saving.value = false
  }
}
let timer: ReturnType<typeof setTimeout>
watch(category, () => load(true))
watch(q, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(true), 250)
})
onBeforeUnmount(() => clearTimeout(timer))
onMounted(() => load(true))
</script>
<template>
  <div class="space-y-6">
    <UiPageHeader
      :title="t('tri.price_lists.title')"
      :subtitle="t('office.pricing_subtitle')"
      ><template #actions
        ><UiButton v-if="auth.canWrite" @click="edit()"
          >+ {{ t('office.new_product') }}</UiButton
        ></template
      ></UiPageHeader
    >
    <nav class="office-tabs">
      <RouterLink to="/tri/price-lists">{{
        t('tri.price_lists.title')
      }}</RouterLink
      ><RouterLink class="active" to="/tri/catalog">{{
        t('office.catalog')
      }}</RouterLink>
    </nav>
    <div class="office-filter-toolbar flex gap-3 flex-wrap items-end">
      <UiInput
        v-model="q"
        type="search"
        :label="t('common.search')"
        :placeholder="t('office.catalog_search')"
        class="w-full sm:w-80"
      /><OfficeFilterButton :count="conditions.length" :expanded="filtersOpen" @click="openFilters" />
    </div>
    <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />
    <p v-if="error" role="alert">
      {{ error }}
      <UiButton @click="load(true)">{{ t('office.retry') }}</UiButton>
    </p>
    <div class="border border-neutral-200 overflow-x-auto">
      <UiTable
        ><template #head
          ><tr>
            <th>{{ t('office.code') }}</th>
            <th>{{ t('common.name') }}</th>
            <th>{{ t('office.category') }}</th>
            <th>{{ t('office.tags') }}</th>
            <th>{{ t('office.unit') }}</th>
            <th>{{ t('office.actions') }}</th>
          </tr></template
        >
        <tr v-for="p in rows" :key="p.id">
          <td class="font-mono">{{ p.code }}</td>
          <td>
            <button
              type="button"
              class="text-primary-600 font-semibold"
              @click="history(p)"
            >
              {{ p.title }}
            </button>
            <p class="text-xs text-neutral-500 mt-1">{{ p.description }}</p>
          </td>
          <td>{{ p.category || '—' }}</td>
          <td>
            <span
              v-for="tag in JSON.parse(p.tags_json || '[]')"
              :key="tag"
              class="inline-block bg-neutral-100 px-2 py-1 mr-1 text-xs"
              >{{ tag }}</span
            >
          </td>
          <td>{{ p.unit }}</td>
          <td>
            <UiButton v-if="auth.canWrite" variant="ghost" @click="edit(p)">{{
              t('common.edit')
            }}</UiButton>
          </td>
        </tr>
        <tr v-if="!rows.length && !error">
          <td colspan="6">
            {{ t(loading ? 'common.loading' : 'tri.price_lists.no_data') }}
          </td>
        </tr></UiTable
      >
    </div>
    <div class="text-center space-y-3">
      <p class="text-xs text-neutral-500">
        {{ t('office.loaded', { n: rows.length, total }) }}
      </p>
      <UiButton
        v-if="hasMore"
        variant="secondary"
        :loading="loading"
        @click="load(false)"
        >{{ t('office.more') }}</UiButton
      >
    </div>
    <Modal v-if="filtersOpen" :title="t('office.filters')" @close="filtersOpen=false">
      <UiInput v-model="draftCategory" :label="t('office.category')" />
      <template #footer><UiButton variant="secondary" @click="clearFilters(); filtersOpen=false">{{ t('office.clear_filters') }}</UiButton><UiButton @click="applyFilters">{{ t('office.apply_filters') }}</UiButton></template>
    </Modal>
    <Modal
      v-if="historyProduct"
      :title="historyProduct.title"
      @close="historyProduct = null"
      ><div class="space-y-4">
        <p>
          {{ historyProduct.code }} · {{ historyProduct.unit }} ·
          {{ historyProduct.category }}
        </p>
        <p>{{ historyProduct.description }}</p>
        <p class="whitespace-pre-line">{{ historyProduct.parameters }}</p>
        <img
          v-if="historyProduct.image"
          :src="historyProduct.image.url"
          alt=""
          class="max-h-48 object-contain"
        />
        <p v-if="historyError" role="alert">{{ historyError }}</p>
        <UiTable
          ><template #head
            ><tr>
              <th>{{ t('tri.price_lists.title') }}</th>
              <th>{{ t('office.release') }}</th>
              <th>{{ t('office.price_net') }}</th>
              <th>{{ t('office.valid_from') }}</th>
              <th>{{ t('office.price_changed') }}</th>
              <th>{{ t('office.author') }}</th>
            </tr></template
          >
          <tr v-for="r in priceHistory" :key="r.release_id">
            <td>
              <RouterLink
                :to="`/tri/price-lists/${r.price_list_id}?release=${r.release_id}`"
                >{{ r.name }}</RouterLink
              >
            </td>
            <td>
              {{ r.sequence }} ·
              {{
                t(r.status === 'draft' ? 'office.draft' : 'office.published')
              }}
            </td>
            <td>
              {{
                r.unit_price === null
                  ? '—'
                  : formatMoney(Number(r.unit_price), r.currency)
              }}
            </td>
            <td>{{ formatDate(r.valid_from) }}</td>
            <td>{{ formatDate(r.price_changed_at) }}</td>
            <td>{{ r.author || '—' }}</td>
          </tr></UiTable
        >
      </div></Modal
    >
    <Modal
      v-if="editing"
      :title="t(editing.id ? 'office.edit_product' : 'office.new_product')"
      @close="editing = null"
      ><form class="office-page grid grid-cols-2 gap-4" @submit.prevent="save">
        <UiInput
          v-model="editing.code"
          :label="t('office.code')"
          required
          maxlength="160"
        /><UiInput
          v-model="editing.title"
          :label="t('common.name')"
          required
          maxlength="500"
        /><UiInput
          v-model="editing.category"
          :label="t('office.category')"
        /><UiInput
          v-model="editing.tagsText"
          :label="t('office.tags')"
        /><UiInput
          v-model="editing.unit"
          :label="t('office.unit')"
          required
        /><UiInput
          v-model="editing.vat_rate"
          type="number"
          min="0"
          max="100"
          :label="t('invoice.totals.vat')"
        /><UiInput
          v-model="editing.default_quantity"
          type="number"
          min="0.001"
          step="0.001"
          :label="t('office.default_quantity')"
        />
        <label class="col-span-2"
          >{{ t('office.description')
          }}<textarea
            v-model="editing.description"
            class="w-full border border-neutral-200 p-3 mt-2"
            rows="3"
          /></label
        ><label class="col-span-2"
          >{{ t('office.parameters')
          }}<textarea
            v-model="editing.parameters"
            class="w-full border border-neutral-200 p-3 mt-2"
            rows="3"
          />
        </label>
        <div class="col-span-2">
          <label>{{ t('tri.quote.image_add') }}</label
          ><QuoteLineImageControl
            :image="editing.image || null"
            :upload="pricingApi.uploadImage"
            @uploaded="editing.image = $event; editing.image_id = $event.id"
            @removed="editing.image = null; editing.image_id = null"
          />
        </div>
        <p v-if="saveError" role="alert" class="col-span-2 text-danger-600">
          {{ saveError }}
        </p>
        <div class="col-span-2 flex justify-end gap-3">
          <UiButton variant="secondary" @click="editing = null">{{
            t('common.cancel')
          }}</UiButton
          ><UiButton type="submit" :loading="saving">{{
            t('common.save')
          }}</UiButton>
        </div>
      </form></Modal
    >
  </div>
</template>

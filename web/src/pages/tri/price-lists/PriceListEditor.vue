<script setup lang="ts">
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi } from '@/api/tri'
import {
  pricingApi,
  type PriceList,
  type CatalogProduct,
  type ReleaseItem,
} from '@/api/triPricing'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiTable from '@/components/ui/UiTable.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import Modal from '@/components/ui/Modal.vue'
import OfficeBreadcrumb from '@/components/tri/OfficeBreadcrumb.vue'
const { t } = useI18n(),
  route = useRoute(),
  router = useRouter(),
  auth = useAuthStore()
const list = ref<PriceList | null>(null),
  busy = ref(false),
  error = ref(''),
  baseline = ref(''),
  q = ref('')
const creating = computed(() => !route.params.id),
  form = ref({ name: '', currency: 'CZK', note: '' })
const writable = computed(
  () => auth.canWrite && list.value?.release?.status === 'draft',
)
const snapshot = () =>
  JSON.stringify({ release: list.value?.release, items: list.value?.items })
const dirty = computed(() => !!list.value && snapshot() !== baseline.value)
const filterOpen = ref(false),
  filterCategory = ref(''),
  filterTag = ref(''),
  missingPrice = ref(false)
const draftFilters = ref({ category: '', tag: '', missing: false })
function openFilters() { draftFilters.value = { category: filterCategory.value, tag: filterTag.value, missing: missingPrice.value }; filterOpen.value = true }
function applyFilters() { filterCategory.value = draftFilters.value.category; filterTag.value = draftFilters.value.tag.trim(); missingPrice.value = draftFilters.value.missing; filterOpen.value = false }
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'category', active: () => !!filterCategory.value, label: () => `${t('office.category')}: ${filterCategory.value}`, clear: () => { filterCategory.value = '' } },
  { id: 'tag', active: () => !!filterTag.value, label: () => `${t('office.tags')}: ${filterTag.value}`, clear: () => { filterTag.value = '' } },
  { id: 'missing', active: () => missingPrice.value, label: () => t('office.missing_price'), clear: () => { missingPrice.value = false } },
])
const filtered = computed(
  () =>
    list.value?.items.filter(
      (i) =>
        `${i.code} ${i.title} ${i.category}`
          .toLowerCase()
          .includes(q.value.toLowerCase()) &&
        (!filterCategory.value || i.category === filterCategory.value) &&
        (!filterTag.value ||
          JSON.parse(i.tags_json || '[]').includes(filterTag.value)) &&
        (!missingPrice.value || i.unit_price === null || i.unit_price === ''),
    ) ?? [],
)
const selected = ref(new Set<number>()),
  history = ref(false),
  publishOpen = ref(false),
  bulkOpen = ref(false),
  bulkPercent = ref(0)
const picker = ref(false),
  products = ref<CatalogProduct[]>([]),
  catalogQuery = ref(''),
  catalogTotal = ref(0),
  catalogPage = ref(1),
  catalogLoading = ref(false)
const leaveOpen = ref(false)
let resolveLeave: ((yes: boolean) => void) | undefined
const preview = ref<
    | {
        code: string
        unit_price: string
        unit: string
        currency: string
        error: string
      }[]
    | null
  >(null),
  fileInput = ref<HTMLInputElement | null>(null)
function setList(data: PriceList) {
  list.value = data
  baseline.value = snapshot()
  selected.value = new Set()
}
async function run(fn: () => Promise<void>) {
  busy.value = true
  error.value = ''
  try {
    await fn()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    busy.value = false
  }
}
async function load(release?: number) {
  if (creating.value) return
  await run(async () =>
    setList(
      await pricingApi.get(
        Number(route.params.id),
        release || Number(route.query.release) || undefined,
      ),
    ),
  )
}
function downloadPdf() {
  if (!list.value?.release) return
  const url = triApi.priceLists.pdfUrl(list.value.id)
  window.open(
    url + (url.includes('?') ? '&' : '?') + 'release=' + list.value.release.id,
    '_blank',
    'noopener',
  )
}
async function create() {
  await run(async () => {
    const l = await pricingApi.create(form.value)
    setList(await pricingApi.draft(l.id))
    await router.replace(`/tri/price-lists/${l.id}`)
  })
}
async function draft() {
  await run(async () => setList(await pricingApi.draft(list.value!.id)))
}
async function save() {
  let ok = false
  await run(async () => {
    setList(await pricingApi.save(list.value!))
    ok = true
  })
  return ok
}
async function publish() {
  if (dirty.value && !(await save())) return
  await run(async () => {
    setList(await pricingApi.publish(list.value!))
    publishOpen.value = false
  })
}
async function openPicker() {
  picker.value = true
  catalogQuery.value = ''
  await findProducts(true)
}
async function findProducts(reset = true) {
  catalogLoading.value = true
  try {
    const page = reset ? 1 : catalogPage.value + 1
    const r = await pricingApi.catalog({ q: catalogQuery.value, page })
    products.value = reset ? r.data : [...products.value, ...r.data]
    catalogTotal.value = r.total
    catalogPage.value = page
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    catalogLoading.value = false
  }
}
let catalogTimer: ReturnType<typeof setTimeout>
watch(catalogQuery, () => {
  clearTimeout(catalogTimer)
  catalogTimer = setTimeout(() => findProducts(), 250)
})
function add(p: CatalogProduct) {
  if (
    !list.value ||
    list.value.items.some((i) => Number(i.product_id) === Number(p.id))
  )
    return
  list.value.items.push({
    ...p,
    product_id: Number(p.id),
    unit_price: null,
    price_changed_at: null,
  })
}
function toggle(id: number) {
  const s = new Set(selected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selected.value = s
}
const bulkDiff = computed(
  () =>
    list.value?.items
      .filter(
        (i) =>
          selected.value.has(i.product_id) &&
          i.unit_price !== null &&
          i.unit_price !== '',
      )
      .map((i) => ({
        code: i.code,
        old: Number(i.unit_price),
        next: Number(i.unit_price) * (1 + Number(bulkPercent.value) / 100),
      })) ?? [],
)
function bulk() {
  if (
    !Number.isFinite(Number(bulkPercent.value)) ||
    Number(bulkPercent.value) < -100
  )
    return
  for (const i of list.value!.items) {
    if (
      selected.value.has(i.product_id) &&
      i.unit_price !== null &&
      i.unit_price !== ''
    )
      i.unit_price = (
        Number(i.unit_price) *
        (1 + Number(bulkPercent.value) / 100)
      ).toFixed(2)
  }
  bulkOpen.value = false
}
async function importFile(event: Event) {
  const f = (event.target as HTMLInputElement).files?.[0]
  if (!f) return
  await run(async () => {
    preview.value = (
      await pricingApi.importPreview(list.value!.id, f)
    ).rows.map((row) => {
      const item = list.value!.items.find((i) => i.code === row.code)
      return {
        ...row,
        error:
          row.error ||
          (!item
            ? t('office.unknown_code')
            : item.unit !== row.unit
              ? t('office.unit_mismatch')
              : ''),
      }
    })
  })
  ;(event.target as HTMLInputElement).value = ''
}
function applyImport() {
  if (!preview.value || preview.value.some((r) => r.error)) return
  for (const row of preview.value) {
    const item = list.value!.items.find((i) => i.code === row.code)
    if (item) item.unit_price = row.unit_price
  }
  preview.value = null
}
function askLeave() {
  if (!dirty.value) return true
  leaveOpen.value = true
  return new Promise<boolean>((resolve) => {
    resolveLeave = resolve
  })
}
async function decideLeave(mode: 'save' | 'discard' | 'stay') {
  if (mode === 'save' && !(await save())) return
  leaveOpen.value = false
  resolveLeave?.(mode !== 'stay')
  resolveLeave = undefined
}
onBeforeRouteLeave(askLeave)
function beforeUnload(e: BeforeUnloadEvent) {
  if (dirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}
onMounted(() => {
  load()
  window.addEventListener('beforeunload', beforeUnload)
})
onBeforeUnmount(() => {
  clearTimeout(catalogTimer)
  window.removeEventListener('beforeunload', beforeUnload)
})
watch(
  () => [route.params.id, route.query.release],
  () => load(),
)
function remove(i: ReleaseItem) {
  if (window.confirm(t('office.remove_price_confirm')))
    list.value!.items = list.value!.items.filter(
      (x) => x.product_id !== i.product_id,
    )
}
</script>
<template>
  <div class="space-y-6">
    <OfficeBreadcrumb
      :items="[
        { label: t('tri.price_lists.title'), to: '/tri/price-lists' },
        { label: list?.name || t('tri.price_lists.new') },
      ]"
    />
    <UiPageHeader
      :title="list?.name || t('tri.price_lists.new')"
      :subtitle="list?.note || t('office.pricing_subtitle')"
      ><template #actions
        ><template v-if="list"
          ><UiButton
            v-if="list.release"
            variant="secondary"
            :disabled="dirty"
            @click="downloadPdf"
            >PDF</UiButton
          ><UiButton variant="secondary" @click="history = true">{{
            t('office.history')
          }}</UiButton
          ><UiButton
            v-if="auth.canWrite && !writable"
            :loading="busy"
            @click="draft"
            >{{ t('office.new_draft') }}</UiButton
          ><UiButton
            v-if="writable"
            :loading="busy"
            @click="publishOpen = true"
            >{{ t('office.publish') }}</UiButton
          ></template
        ></template
      ></UiPageHeader
    >
    <p
      v-if="error"
      role="alert"
      class="border border-danger-200 bg-danger-50 p-4 text-danger-700"
    >
      {{ error }}
    </p>
    <form
      v-if="creating"
      class="border border-neutral-200 p-5 max-w-2xl space-y-5"
      @submit.prevent="create"
    >
      <UiInput
        v-model="form.name"
        :label="t('tri.price_lists.name')"
        required
        maxlength="190"
      /><UiInput
        v-model="form.currency"
        :label="t('common.currency')"
        required
        pattern="[A-Za-z]{3}"
        maxlength="3"
      /><UiInput
        v-model="form.note"
        :label="t('tri.price_lists.note')"
      /><UiButton type="submit" :loading="busy">{{
        t('common.save')
      }}</UiButton>
    </form>
    <template v-else-if="list">
      <div
        class="border border-neutral-200 p-5 flex gap-8 flex-wrap items-start"
      >
        <div>
          <p class="text-xs text-neutral-500 mb-2">{{ t('common.status') }}</p>
          <UiBadge
            >{{
              t(
                list.release?.status === 'draft'
                  ? 'office.draft'
                  : list.release
                    ? 'office.published'
                    : 'office.no_release',
              )
            }}<span v-if="list.release">
              · {{ list.release.sequence }}</span
            ></UiBadge
          >
        </div>
        <div>
          <p class="text-xs text-neutral-500 mb-2">
            {{ t('common.currency') }}
          </p>
          {{ list.currency }}
        </div>
        <div>
          <p class="text-xs text-neutral-500 mb-2">
            {{ t('office.valid_from') }}
          </p>
          {{ formatDate(list.release?.valid_from) || '—' }}
        </div>
        <div>
          <p class="text-xs text-neutral-500 mb-2">
            {{ t('office.valid_until') }}
          </p>
          {{ formatDate(list.release?.valid_until) || '—' }}
        </div>
        <div>
          <p class="text-xs text-neutral-500 mb-2">
            {{ t('tri.price_lists.item_count') }}
          </p>
          {{ list.items.length }}
        </div>
      </div>
      <p
        v-if="!list.release && list.legacy_item_count"
        class="bg-primary-50 p-4"
      >
        {{ t('office.adopt_legacy', { n: list.legacy_item_count }) }}
      </p>
      <p v-if="writable" class="bg-primary-50 p-4 text-sm">
        {{ t('office.draft_hint') }}
      </p>
      <div class="office-filter-toolbar flex justify-between items-end gap-3 flex-wrap">
        <UiInput
          v-model="q"
          type="search"
          :label="t('common.search')"
          :placeholder="t('office.catalog_search')"
          class="w-full sm:w-80"
        /><OfficeFilterButton :count="conditions.length" :expanded="filterOpen" @click="openFilters" />
        <div v-if="writable" class="flex gap-2">
          <UiButton
            variant="secondary"
            :disabled="!selected.size"
            @click="bulkOpen = true"
            >{{ t('office.bulk_change') }} ({{ selected.size }})</UiButton
          ><UiButton variant="secondary" @click="fileInput?.click()">{{
            t('office.import_xlsx')
          }}</UiButton
          ><input
            ref="fileInput"
            type="file"
            accept=".xlsx,.xls"
            class="hidden"
            @change="importFile"
          /><UiButton @click="openPicker"
            >+ {{ t('office.add_catalog') }}</UiButton
          >
        </div>
      </div>
      <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />
      <div class="border border-neutral-200 overflow-x-auto">
        <UiTable
          ><template #head
            ><tr>
              <th v-if="writable">
                <input
                  type="checkbox"
                  :aria-label="t('office.select_all')"
                  :checked="
                    filtered.length > 0 &&
                    filtered.every((i) => selected.has(i.product_id))
                  "
                  @change="
                    selected = ($event.target as HTMLInputElement).checked
                      ? new Set(filtered.map((i) => i.product_id))
                      : new Set()
                  "
                />
              </th>
              <th>{{ t('office.code') }}</th>
              <th>{{ t('common.name') }}</th>
              <th>{{ t('office.category') }}</th>
              <th>{{ t('office.unit') }}</th>
              <th class="text-right">{{ t('office.price_net') }}</th>
              <th>{{ t('office.price_changed') }}</th>
              <th v-if="writable">{{ t('office.actions') }}</th>
            </tr></template
          >
          <tr v-for="i in filtered" :key="i.product_id">
            <td v-if="writable">
              <input
                type="checkbox"
                :checked="selected.has(i.product_id)"
                :aria-label="i.code"
                @change="toggle(i.product_id)"
              />
            </td>
            <td class="font-mono">{{ i.code }}</td>
            <td class="min-w-48">{{ i.title }}</td>
            <td>{{ i.category || '—' }}</td>
            <td>{{ i.unit }}</td>
            <td class="text-right">
              <input
                v-if="writable"
                v-model="i.unit_price"
                type="number"
                min="0"
                max="9999999999.99"
                step="0.01"
                :aria-label="`${t('office.price_net')} ${i.code}`"
                class="border border-neutral-200 w-32 p-2 text-right"
              /><span v-else>{{
                i.unit_price === null
                  ? '—'
                  : formatMoney(Number(i.unit_price), list.currency)
              }}</span>
            </td>
            <td class="whitespace-nowrap">
              {{ formatDate(i.price_changed_at) || '—' }}
            </td>
            <td v-if="writable">
              <UiButton
                variant="ghost"
                :aria-label="`${t('common.delete')} ${i.code}`"
                @click="remove(i)"
                >×</UiButton
              >
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="8">{{ t('tri.price_lists.no_data') }}</td>
          </tr></UiTable
        >
      </div>
      <div
        v-if="writable"
        class="office-save-bar flex justify-between items-center p-4"
      >
        <span class="text-sm text-neutral-500">{{
          t(dirty ? 'office.unsaved' : 'office.saved')
        }}</span
        ><UiButton :loading="busy" :disabled="!dirty" @click="save">{{
          t('office.save_draft')
        }}</UiButton>
      </div>
    </template>
    <Modal
      v-if="filterOpen"
      :title="t('office.filters')"
      @close="filterOpen = false"
      ><div class="office-page space-y-4">
        <label class="grid gap-1"
          >{{ t('office.category')
          }}<select v-model="draftFilters.category" class="border px-3">
            <option value="">{{ t('common.all') }}</option>
            <option
              v-for="c in [
                ...new Set(list?.items.map((i) => i.category).filter(Boolean)),
              ]"
              :key="c"
            >
              {{ c }}
            </option>
          </select></label
        ><UiInput v-model="draftFilters.tag" :label="t('office.tags')" /><label
          class="flex gap-2"
          ><input v-model="draftFilters.missing" type="checkbox" />{{
            t('office.missing_price')
          }}</label
        ><UiButton variant="secondary" @click="clearFilters(); filterOpen=false">{{ t('office.clear_filters') }}</UiButton><UiButton @click="applyFilters">{{
          t('office.apply_filters')
        }}</UiButton>
      </div></Modal
    >
    <Modal
      v-if="history && list"
      :title="t('office.history')"
      @close="history = false"
      ><div class="space-y-3">
        <button
          v-for="r in list.releases"
          :key="r.id"
          class="w-full text-left border border-neutral-200 p-4 hover:bg-neutral-50"
          @click="
            async () => {
              if (await askLeave()) {
                await load(Number(r.id))
                history = false
              }
            }
          "
        >
          <strong
            >{{
              t(r.status === 'draft' ? 'office.draft' : 'office.published')
            }}
            {{ r.sequence }}</strong
          ><span class="ml-5"
            >{{ r.valid_from || '—' }} – {{ r.valid_until || '∞' }}</span
          >
        </button>
        <p v-if="!list.releases.length">{{ t('office.no_release') }}</p>
      </div></Modal
    >
    <Modal
      v-if="publishOpen && list?.release"
      :title="t('office.publish')"
      @close="publishOpen = false"
      ><div class="office-page space-y-5">
        <p>{{ t('office.publish_hint') }}</p>
        <div class="grid grid-cols-2 gap-4">
          <UiInput
            v-model="list.release.valid_from"
            type="date"
            :label="t('office.valid_from')"
            required
          /><UiInput
            v-model="list.release.valid_until"
            type="date"
            :label="t('office.valid_until')"
          />
        </div>
        <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
        <div class="flex justify-end gap-3">
          <UiButton variant="secondary" @click="publishOpen = false">{{
            t('common.cancel')
          }}</UiButton
          ><UiButton
            :loading="busy"
            :disabled="!list.release.valid_from"
            @click="publish"
            >{{ t('office.publish') }}</UiButton
          >
        </div>
      </div></Modal
    >
    <Modal
      v-if="picker"
      :title="t('office.add_catalog')"
      @close="picker = false"
      ><div class="space-y-4">
        <UiInput
          v-model="catalogQuery"
          :label="t('common.search')"
          type="search"
        />
        <div
          v-for="p in products"
          :key="p.id"
          class="border-b border-neutral-200 p-3 flex gap-3 items-center"
        >
          <span class="font-mono text-xs w-28">{{ p.code }}</span
          ><span class="flex-1"
            >{{ p.title
            }}<span class="text-xs text-neutral-500 ml-3">{{
              p.unit
            }}</span></span
          ><UiButton
            variant="secondary"
            :disabled="
              list?.items.some((i) => Number(i.product_id) === Number(p.id))
            "
            @click="add(p)"
            >{{ t('common.add') }}</UiButton
          >
        </div>
        <UiButton
          v-if="products.length < catalogTotal"
          variant="secondary"
          :loading="catalogLoading"
          @click="findProducts(false)"
          >{{ t('office.more') }}</UiButton
        ><UiButton @click="picker = false">{{ t('common.done') }}</UiButton>
      </div></Modal
    >
    <Modal
      v-if="bulkOpen"
      :title="t('office.bulk_change')"
      @close="bulkOpen = false"
      ><div class="space-y-4">
        <p>{{ t('office.bulk_hint', { n: selected.size }) }}</p>
        <UiInput
          v-model="bulkPercent"
          type="number"
          min="-100"
          step="0.01"
          :label="t('office.percentage_change')"
        />
        <table class="w-full text-sm">
          <thead>
            <tr>
              <th>{{ t('office.code') }}</th>
              <th>{{ t('office.old_price') }}</th>
              <th>{{ t('office.new_price') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in bulkDiff" :key="i.code">
              <td>{{ i.code }}</td>
              <td>{{ formatMoney(i.old, list?.currency) }}</td>
              <td>{{ formatMoney(i.next, list?.currency) }}</td>
            </tr>
          </tbody>
        </table>
        <UiButton
          :disabled="
            !Number.isFinite(Number(bulkPercent)) || Number(bulkPercent) < -100
          "
          @click="bulk"
          >{{ t('office.apply_preview') }}</UiButton
        >
      </div></Modal
    >
    <Modal
      v-if="preview"
      :title="t('office.import_preview')"
      @close="preview = null"
      ><div class="space-y-4">
        <UiTable
          ><template #head
            ><tr>
              <th>{{ t('office.code') }}</th>
              <th>{{ t('office.price_net') }}</th>
              <th>{{ t('common.status') }}</th>
            </tr></template
          >
          <tr v-for="(row, n) in preview" :key="n">
            <td>{{ row.code }}</td>
            <td>{{ row.unit_price }}</td>
            <td class="text-danger-600">{{ row.error || '✓' }}</td>
          </tr></UiTable
        ><UiButton
          :disabled="!preview.length || preview.some((r) => r.error)"
          @click="applyImport"
          >{{ t('office.apply_preview') }}</UiButton
        >
      </div></Modal
    >
    <Modal
      v-if="leaveOpen"
      :title="t('office.unsaved')"
      @close="decideLeave('stay')"
      ><p>{{ t('office.leave_hint') }}</p>
      <template #footer
        ><UiButton variant="secondary" @click="decideLeave('stay')">{{
          t('office.stay')
        }}</UiButton
        ><UiButton variant="secondary" @click="decideLeave('discard')">{{
          t('office.discard')
        }}</UiButton
        ><UiButton :loading="busy" @click="decideLeave('save')">{{
          t('common.save')
        }}</UiButton></template
      ></Modal
    >
  </div>
</template>

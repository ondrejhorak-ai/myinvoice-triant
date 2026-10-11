<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TriQuoteLineItem } from '@/api/tri'
import OfficeMenu from '@/components/tri/OfficeMenu.vue'
import Modal from '@/components/ui/Modal.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSelect from '@/components/ui/UiSelect.vue'
import QuoteLineImageControl from './QuoteLineImageControl.vue'
const props = defineProps<{
  row: TriQuoteLineItem & { _uid: string }
  total: number
  discount: number
  canWrite: boolean
  variantId: number
}>()
const emit = defineEmits<{
  remove: []
  move: [direction: number]
  total: [value: string]
  traveler: []
}>()
const { t } = useI18n()
const discountOpen = ref(false)
const imageOpen = ref(false)
const discountValue = ref(0)
const discountType = ref<'percent' | 'absolute'>('percent')
function openDiscount() {
  discountValue.value = props.row.line_discount_value || 0
  discountType.value = props.row.line_discount_type || 'percent'
  discountOpen.value = true
}
function saveDiscount() {
  if (
    !Number.isFinite(discountValue.value) ||
    discountValue.value < 0 ||
    (discountType.value === 'percent' && discountValue.value > 100)
  )
    return
  props.row.line_discount_type = discountType.value
  props.row.line_discount_value = discountValue.value
  discountOpen.value = false
}
const titleEl = ref<HTMLTextAreaElement | null>(null)
const beforeEdit = new WeakMap<EventTarget, string>()
function autosizeTitle(event?: Event) {
  const el = event?.target instanceof HTMLTextAreaElement ? event.target : titleEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight}px`
}
function commitQuantity() {
  const n = Math.round(Number(props.row.quantity))
  props.row.quantity = Number.isFinite(n) && n >= 1 ? n : 1
}
function focus(e: FocusEvent) {
  const el = e.target
  if (!(el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)) return
  beforeEdit.set(el, el.value)
}
function finishEdit(e: KeyboardEvent) {
  if (e.target instanceof HTMLTextAreaElement) return
  if (e.target instanceof HTMLInputElement) {
    e.preventDefault()
    e.target.blur()
  }
}
function escape(e: KeyboardEvent) {
  const el = e.target
  if (!(el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement)) return
  if (beforeEdit.has(el)) {
    el.value = beforeEdit.get(el)!
    el.dispatchEvent(new Event('input', { bubbles: true }))
  }
  el.blur()
}
onMounted(() => autosizeTitle())
</script>
<template>
  <div
    class="offer-row bg-surface"
    @focusin="focus"
    @keydown.esc="escape"
    @keydown.enter="finishEdit"
  >
    <button
      class="drag-handle cursor-grab h-11 text-neutral-500"
      type="button"
      :disabled="!canWrite"
      :aria-label="t('office.move_hint')"
      :title="t('office.move_hint')"
      @keydown.alt.up.prevent="emit('move', -1)"
      @keydown.alt.down.prevent="emit('move', 1)"
    >
      ⠿
    </button>
    <input
      v-model="row.designation"
      :disabled="!canWrite"
      :aria-label="t('tri.quote.designation')"
      class="border px-2 min-w-0 w-full"
    />
    <div class="min-w-0">
      <textarea
        ref="titleEl"
        v-model="row.title"
        rows="1"
        :disabled="!canWrite"
        :aria-label="t('tri.quote.title')"
        class="offer-title border px-2 min-w-0 w-full resize-none"
        @input="autosizeTitle"
      />
      <p v-if="discount > 0" class="text-[11px] mt-1 text-warning-600 break-words">
        {{ t('tri.quote.discount') }} {{ row.line_discount_value }}
        {{ row.line_discount_type === 'percent' ? '%' : '' }} · −{{
          discount.toFixed(2)
        }}
      </p>
      <a v-if="row.image" :href="row.image.url" target="_blank" rel="noopener"
        ><img
          :src="row.image.url"
          :alt="row.title"
          class="mt-2 w-32 h-20 object-contain border border-neutral-200"
      /></a>
    </div>
    <input
      v-model.number="row.quantity"
      :disabled="!canWrite"
      type="number"
      step="1"
      min="1"
      :aria-label="t('tri.quote.quantity')"
      :aria-invalid="!(row.quantity >= 1)"
      class="border px-2 text-right min-w-0 w-full"
      @change="commitQuantity"
    />
    <input
      v-model="row.unit"
      :disabled="!canWrite"
      :aria-label="t('office.unit')"
      class="border px-2 min-w-0 w-full"
    />
    <input
      v-model.number="row.base_unit_price"
      :disabled="!canWrite"
      type="number"
      step="0.01"
      min="0"
      :aria-label="t('tri.quote.unit_price')"
      class="offer-price border px-2 text-right min-w-0 w-full"
    />
    <UiSelect
      v-model.number="row.vat_rate"
      size="inline"
      class="w-full self-center"
      :disabled="!canWrite"
      :aria-label="t('tri.quote.vat_rate')"
    >
      <option :value="21">21</option>
      <option :value="12">12</option>
      <option :value="0">0</option>
    </UiSelect>
    <label class="h-11 flex items-center justify-center"
      ><input
        v-model="row.is_manufactured"
        :disabled="!canWrite"
        type="checkbox"
        :aria-label="t('tri.quote.is_manufactured')"
        class="w-4 h-4 accent-primary-600"
    /></label>
    <input
      :value="total.toFixed(2)"
      :disabled="!canWrite"
      type="number"
      step="0.01"
      min="0"
      :aria-label="t('tri.quote.line_total')"
      @change="emit('total', ($event.target as HTMLInputElement).value)"
      class="border px-2 text-right min-w-0 w-full"
    />
    <OfficeMenu v-if="canWrite"
      ><button type="button" @click="openDiscount">
        {{ t('office.line_discount') }}</button
      ><button
        type="button"
        :disabled="!row.id || !row.is_manufactured"
        @click="emit('traveler')"
      >
        {{ t('office.create_traveler') }}</button
      ><button type="button" @click="imageOpen = true">
        {{ t('tri.quote.image_add') }}</button
      ><button type="button" class="text-danger-600" @click="emit('remove')">
        {{ t('common.delete') }}
      </button></OfficeMenu
    ><span v-else />
    <Modal
      v-if="discountOpen"
      :title="t('office.line_discount')"
      width-class="max-w-sm"
      @close="discountOpen = false"
      ><div class="flex gap-3">
        <input
          v-model.number="discountValue"
          type="number"
          min="0"
          :max="discountType === 'percent' ? 100 : undefined"
          step="1"
          class="border p-3 w-full"
          :aria-label="t('tri.quote.discount')"
        /><UiSelect v-model="discountType" size="inline" class="w-36 shrink-0" :aria-label="t('tri.quote.discount')">
          <option value="percent">%</option>
          <option value="absolute">
            {{ t('tri.quote.adjustment_amount') }}
          </option>
        </UiSelect>
      </div>
      <template #footer
        ><UiButton @click="saveDiscount">{{
          t('common.save')
        }}</UiButton></template
      ></Modal
    >
    <Modal
      v-if="imageOpen"
      :title="t('tri.quote.image_add')"
      width-class="max-w-md"
      @close="imageOpen = false"
      ><QuoteLineImageControl
        :variant-id="variantId"
        :image="row.image || null"
        @uploaded="row.image = $event; row.image_id = $event.id; imageOpen = false"
        @removed="row.image = null; row.image_id = null; imageOpen = false"
    /></Modal>
  </div>
</template>

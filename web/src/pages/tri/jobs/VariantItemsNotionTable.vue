<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import type { TriQuoteImage, TriQuoteLineItem } from '@/api/tri'
import type { TriAdjustmentType } from '@/composables/useTriQuotePricing'
import QuoteAdjustmentPanel from './QuoteAdjustmentPanel.vue'
import QuoteLineImageControl from './QuoteLineImageControl.vue'
import UiButton from '@/components/ui/UiButton.vue'

type Row = TriQuoteLineItem & { _uid: string }
interface ItemBlock {
  kind: 'item'
  row: Row
}
interface SectionBlock {
  kind: 'section'
  tempId: string
  title: string
  discount_type?: TriAdjustmentType | null
  discount_value?: number | null
  commission_type?: TriAdjustmentType | null
  commission_value?: number | null
  items: Row[]
}
type Block = ItemBlock | SectionBlock

type LineResult = {
  lineTotal: number
  offer: number
  afterLineDiscount: number
  markupAmount?: number
} | null

const props = defineProps<{
  lineOffer: (row: Row) => number
  lineAfter: (row: Row) => number
  lineOwnDiscount: (row: Row) => number
  lineResult: (row: Row) => LineResult
  sectionTotal: (section: SectionBlock) => number
  sectionOfferTotal: (section: SectionBlock) => number
  sectionDiscountAmount: (section: SectionBlock) => number
  isLineCommissionOverridden: (section?: SectionBlock) => boolean
  hasQuoteCommission: boolean
  formatLineAmount: (value: number) => string
  variantId: number
  canWrite: boolean
}>()

function setRowImage(row: Row, image: TriQuoteImage) {
  row.image_id = image.id
  row.image = image
}

function removeRowImage(row: Row) {
  row.image_id = null
  row.image = null
}

const blocks = defineModel<Block[]>('blocks', { required: true })

const emit = defineEmits<{
  addLine: []
  addLineToSection: [section: SectionBlock]
  addSection: []
  deleteStandalone: [bi: number]
  deleteSectionItem: [section: SectionBlock, ii: number]
  deleteSection: [bi: number]
}>()

const { t } = useI18n()

const STORAGE_WIDTHS = 'tri.variantEditor.columnWidths'
const COL_KEYS = ['handle', 'designation', 'title', 'quantity', 'unitPrice', 'vat', 'total', 'actions'] as const
type ColKey = (typeof COL_KEYS)[number]

const DEFAULT_WIDTHS: Record<ColKey, number> = {
  handle: 28,
  designation: 96,
  title: 220,
  quantity: 64,
  unitPrice: 88,
  vat: 60,
  total: 100,
  actions: 52,
}

const MIN_WIDTHS: Partial<Record<ColKey, number>> = {
  designation: 64,
  title: 120,
  quantity: 48,
  unitPrice: 64,
  vat: 48,
  total: 72,
}

const FIXED_COLS: ColKey[] = ['handle', 'actions']

function loadWidths(): Record<ColKey, number> {
  try {
    const raw = localStorage.getItem(STORAGE_WIDTHS)
    if (!raw) return { ...DEFAULT_WIDTHS }
    const parsed = JSON.parse(raw) as Partial<Record<ColKey, number>>
    return { ...DEFAULT_WIDTHS, ...parsed }
  } catch {
    return { ...DEFAULT_WIDTHS }
  }
}

const colWidths = ref<Record<ColKey, number>>(loadWidths())

watch(
  colWidths,
  (v) => localStorage.setItem(STORAGE_WIDTHS, JSON.stringify(v)),
  { deep: true },
)

const gridTemplateColumns = computed(() =>
  COL_KEYS.map((k) =>
    k === 'title' ? `minmax(${colWidths.value.title}px, 1fr)` : `${colWidths.value[k]}px`,
  ).join(' '),
)

const expandedPanels = ref<Set<string>>(new Set())

function togglePanel(id: string) {
  const next = new Set(expandedPanels.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedPanels.value = next
}

function isPanelOpen(id: string): boolean {
  return expandedPanels.value.has(id)
}

const noSpinClass =
  '[appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none'

function autosize(el: HTMLTextAreaElement) {
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight}px`
}

const autosizeState = new WeakMap<HTMLTextAreaElement, { onInput: () => void; ro: ResizeObserver }>()
const vAutosize = {
  mounted(el: HTMLTextAreaElement) {
    const onInput = () => autosize(el)
    const ro = new ResizeObserver(() => autosize(el))
    el.addEventListener('input', onInput)
    ro.observe(el)
    autosizeState.set(el, { onInput, ro })
    autosize(el)
  },
  updated(el: HTMLTextAreaElement) {
    autosize(el)
  },
  unmounted(el: HTMLTextAreaElement) {
    const state = autosizeState.get(el)
    if (!state) return
    el.removeEventListener('input', state.onInput)
    state.ro.disconnect()
    autosizeState.delete(el)
  },
}

// --- Column resize ---

let resizing: { key: ColKey; startX: number; startW: number } | null = null

function onResizeStart(key: ColKey, e: PointerEvent) {
  if (FIXED_COLS.includes(key)) return
  e.preventDefault()
  e.stopPropagation()
  resizing = { key, startX: e.clientX, startW: colWidths.value[key] }
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
  window.addEventListener('pointermove', onResizeMove)
  window.addEventListener('pointerup', onResizeEnd)
}

function onResizeMove(e: PointerEvent) {
  if (!resizing) return
  const min = MIN_WIDTHS[resizing.key] ?? 40
  const next = Math.max(min, resizing.startW + (e.clientX - resizing.startX))
  colWidths.value = { ...colWidths.value, [resizing.key]: next }
}

function onResizeEnd() {
  resizing = null
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  window.removeEventListener('pointermove', onResizeMove)
  window.removeEventListener('pointerup', onResizeEnd)
}

function resetColumn(key: ColKey) {
  if (FIXED_COLS.includes(key)) return
  colWidths.value = { ...colWidths.value, [key]: DEFAULT_WIDTHS[key] }
}

onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onResizeMove)
  window.removeEventListener('pointerup', onResizeEnd)
})

// --- Drag helpers for cross-section moves ---
// Shared group moves Rows into `blocks` and ItemBlocks into `section.items`.
// Normalize BEFORE Vue re-renders (watch flush:'pre') to avoid white-screen crashes.

function wrapAsItemBlock(row: Row): ItemBlock {
  row.quote_section_id = null
  row.section_temp_id = null
  return { kind: 'item', row }
}

function isRawRow(b: unknown): b is Row {
  return !!b && typeof b === 'object' && !('kind' in b) && '_uid' in b
}

function isItemBlock(b: unknown): b is ItemBlock {
  return !!b && typeof b === 'object' && (b as ItemBlock).kind === 'item' && !!(b as ItemBlock).row
}

function isSectionBlock(b: unknown): b is SectionBlock {
  return !!b && typeof b === 'object' && (b as SectionBlock).kind === 'section' && Array.isArray((b as SectionBlock).items)
}

/** Resolve display row whether the slot element is ItemBlock or a raw Row mid-drag. */
function itemRowOf(b: unknown): Row | null {
  if (isItemBlock(b)) return b.row
  if (isRawRow(b)) return b
  return null
}

function needsNormalize(list: unknown[]): boolean {
  for (const b of list) {
    if (isRawRow(b)) return true
    if (!isItemBlock(b) && !isSectionBlock(b)) return true
    if (isSectionBlock(b)) {
      for (const it of b.items) {
        if (it && typeof it === 'object' && 'kind' in it) return true
      }
    }
  }
  return false
}

/**
 * After a nested/top-level drag that may have left raw Rows in `blocks`
 * or ItemBlocks inside section.items, normalize the structure.
 */
function normalizeAfterDrag() {
  const next: Block[] = []
  for (const b of blocks.value as unknown[]) {
    if (isRawRow(b)) {
      next.push(wrapAsItemBlock(b))
      continue
    }
    if (isItemBlock(b)) {
      b.row.quote_section_id = null
      b.row.section_temp_id = null
      next.push(b)
      continue
    }
    if (isSectionBlock(b)) {
      const fixedItems: Row[] = []
      for (const it of b.items as unknown[]) {
        if (isItemBlock(it)) {
          it.row.quote_section_id = null
          it.row.section_temp_id = b.tempId
          fixedItems.push(it.row)
        } else if (isSectionBlock(it)) {
          next.push(it)
        } else if (isRawRow(it) || (it && typeof it === 'object' && '_uid' in it)) {
          const row = it as Row
          row.quote_section_id = null
          row.section_temp_id = b.tempId
          fixedItems.push(row)
        }
      }
      b.items = fixedItems
      next.push(b)
    }
  }
  blocks.value = next
}

watch(
  blocks,
  (list) => {
    if (needsNormalize(list as unknown[])) normalizeAfterDrag()
  },
  { deep: true, flush: 'pre' },
)

function onTopLevelChange() {
  normalizeAfterDrag()
}

function onSectionItemsChange() {
  normalizeAfterDrag()
}

function isSectionDragEl(dragEl: HTMLElement): boolean {
  return dragEl?.getAttribute?.('data-block-kind') === 'section'
}

const topLevelGroup = {
  name: 'quote-lines',
  put: true,
  pull: true,
}

const sectionItemsGroup = {
  name: 'quote-lines',
  pull: true,
  put: (_to: unknown, _from: unknown, dragEl: HTMLElement) => !isSectionDragEl(dragEl),
}

function blockKey(block: unknown): string {
  if (isSectionBlock(block)) return block.tempId
  if (isItemBlock(block)) return block.row._uid
  if (isRawRow(block)) return block._uid
  return `tmp_${Math.random().toString(36).slice(2)}`
}

function sectionItemKey(el: unknown): string {
  if (isItemBlock(el)) return el.row._uid
  if (isRawRow(el)) return el._uid
  if (el && typeof el === 'object' && '_uid' in el) return String((el as Row)._uid)
  return `tmp_${Math.random().toString(36).slice(2)}`
}

function lineHasMarkup(row: Row): boolean {
  const r = props.lineResult(row)
  return !!r && (r.markupAmount ?? 0) > 0
}

function lineHasDiscount(row: Row): boolean {
  return props.lineOwnDiscount(row) > 0
}

function sectionTotalLabel(section: SectionBlock): string {
  const name = (section.title || '').trim() || t('tri.quote.sections')
  return t('tri.quote.section_subtotal_named', { name })
}

onMounted(() => {
  // ensure widths object is complete
  colWidths.value = { ...DEFAULT_WIDTHS, ...colWidths.value }
})
</script>

<template>
  <div class="space-y-3">
    <div class="bg-surface border border-neutral-200 rounded-lg overflow-hidden shadow-xs">
      <div
        class="grid bg-neutral-50 border-b border-neutral-200 text-[12px] font-semibold uppercase tracking-wide text-neutral-500 select-none"
        :style="{ gridTemplateColumns }"
      >
        <div class="border-r border-neutral-200" />
        <div
          v-for="key in (['designation', 'title', 'quantity', 'unitPrice', 'vat', 'total'] as ColKey[])"
          :key="key"
          class="relative px-2 py-2.5 border-r border-neutral-200 flex items-center"
          :class="key === 'unitPrice' || key === 'vat' || key === 'total' ? 'justify-end' : ''"
        >
          <span class="truncate">
            <template v-if="key === 'designation'">{{ t('tri.quote.designation') }}</template>
            <template v-else-if="key === 'title'">{{ t('tri.jobs.title_field') }}</template>
            <template v-else-if="key === 'quantity'">{{ t('tri.quote.quantity') }}</template>
            <template v-else-if="key === 'unitPrice'">{{ t('tri.quote.unit_price') }}</template>
            <template v-else-if="key === 'vat'">{{ t('tri.quote.vat_rate') }}</template>
            <template v-else>{{ t('tri.quote.line_total') }}</template>
          </span>
          <span
            class="absolute right-0 top-0 bottom-0 w-1.5 cursor-col-resize hover:bg-primary-400/40 active:bg-primary-500/50 z-10"
            :title="t('tri.quote.reset_column_width')"
            @pointerdown="onResizeStart(key, $event)"
            @dblclick.stop="resetColumn(key)"
          />
        </div>
        <div />
      </div>

      <div v-if="!blocks.length" class="px-4 py-8 text-center text-sm text-neutral-400">
        {{ t('tri.quote.add_line') }}
      </div>

      <draggable
        v-else
        v-model="blocks"
        :item-key="blockKey"
        handle=".drag-handle"
        :group="topLevelGroup"
        :animation="150"
        ghost-class="notion-ghost"
        chosen-class="notion-chosen"
        drag-class="notion-drag"
        class="divide-y divide-neutral-200"
        @change="onTopLevelChange"
      >
        <template #item="{ element: block, index: bi }">
          <div
            :data-block-kind="isSectionBlock(block) ? 'section' : 'item'"
            :data-key="blockKey(block)"
            :class="isSectionBlock(block) ? 'bg-neutral-50/80 border-y border-neutral-200 first:border-t-0' : 'group/row bg-surface'"
          >
            <template v-if="!isSectionBlock(block)">
              <template v-for="row in (itemRowOf(block) ? [itemRowOf(block)!] : [])" :key="row._uid">
                <div class="grid items-stretch" :style="{ gridTemplateColumns }">
                  <div
                    class="drag-handle cursor-grab active:cursor-grabbing flex items-center justify-center border-r border-neutral-200 text-neutral-300 group-hover/row:text-neutral-500"
                    :title="t('tri.quote.drag_handle')"
                  >
                    <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                      <circle cx="5" cy="4" r="1.2" /><circle cx="11" cy="4" r="1.2" />
                      <circle cx="5" cy="8" r="1.2" /><circle cx="11" cy="8" r="1.2" />
                      <circle cx="5" cy="12" r="1.2" /><circle cx="11" cy="12" r="1.2" />
                    </svg>
                  </div>
                  <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                    <input v-model="row.designation" class="w-full h-full min-h-9 px-2 text-sm bg-transparent outline-none focus-ring" />
                  </div>
                  <div class="flex min-w-0 border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                    <QuoteLineImageControl
                      :variant-id="variantId"
                      :image="row.image ?? null"
                      :disabled="!canWrite"
                      @uploaded="setRowImage(row, $event)"
                      @removed="removeRowImage(row)"
                    />
                    <textarea
                      v-model="row.title"
                      v-autosize
                      rows="1"
                      class="min-w-0 flex-1 min-h-9 py-2 px-2 text-sm bg-transparent outline-none focus-ring resize-none overflow-hidden whitespace-pre-wrap leading-snug"
                    />
                  </div>
                  <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                    <input
                      v-model.number="row.quantity"
                      type="number"
                      step="0.001"
                      :class="[noSpinClass, 'w-full h-full min-h-9 px-2 text-sm font-mono tabular-nums bg-transparent outline-none focus-ring']"
                    />
                  </div>
                  <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                    <input
                      v-model.number="row.base_unit_price"
                      type="number"
                      step="1"
                      :class="[noSpinClass, 'w-full h-full min-h-9 px-2 text-sm font-mono tabular-nums text-right bg-transparent outline-none focus-ring']"
                    />
                  </div>
                  <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                    <select
                      v-model.number="row.vat_rate"
                      class="w-full h-full min-h-9 px-1 text-sm font-mono tabular-nums text-right bg-transparent outline-none focus-ring"
                    >
                      <option :value="21">21</option>
                      <option :value="12">12</option>
                      <option :value="0">0</option>
                    </select>
                  </div>
                  <div class="border-r border-neutral-200 px-2 py-1.5 flex flex-col items-end justify-center font-mono text-sm tabular-nums leading-tight">
                    <span>{{ formatLineAmount(lineOffer(row)) }}</span>
                    <template v-if="!isPanelOpen(row._uid) && lineHasDiscount(row)">
                      <span class="text-[11px] text-danger-600">−{{ formatLineAmount(lineOwnDiscount(row)) }}</span>
                      <span class="text-[11px] text-neutral-700">{{ formatLineAmount(lineAfter(row)) }}</span>
                    </template>
                    <span
                      v-if="!isPanelOpen(row._uid) && lineHasMarkup(row)"
                      class="inline-flex items-center px-1 mt-0.5 rounded text-[10px] font-medium bg-primary-50 text-primary-700"
                    >+{{ formatLineAmount(lineResult(row)?.markupAmount ?? 0) }}</span>
                  </div>
                  <div class="flex items-center justify-center gap-0.5 opacity-0 group-hover/row:opacity-100 focus-within:opacity-100 transition-opacity">
                    <button
                      type="button"
                      class="cursor-pointer text-neutral-400 hover:text-primary-600 text-sm leading-none w-5 h-5 font-semibold"
                      :class="isPanelOpen(row._uid) && 'text-primary-600'"
                      :title="t('tri.quote.adjustments_toggle')"
                      @click="togglePanel(row._uid)"
                    >%</button>
                    <button
                      type="button"
                      class="cursor-pointer text-danger-500 hover:text-danger-600 text-base leading-none w-5 h-5"
                      :title="t('common.delete')"
                      @click="emit('deleteStandalone', bi)"
                    >×</button>
                  </div>
                </div>
                <QuoteAdjustmentPanel
                  v-if="isPanelOpen(row._uid)"
                  v-model:discount-type="row.line_discount_type"
                  v-model:discount-value="row.line_discount_value"
                  v-model:commission-type="row.markup_type"
                  v-model:commission-value="row.markup_value"
                  :commission-overridden="isLineCommissionOverridden()"
                  :offer-amount="lineOffer(row)"
                  :discount-amount="lineOwnDiscount(row)"
                  :commission-amount="lineResult(row)?.markupAmount"
                  :final-amount="lineAfter(row)"
                />
              </template>
            </template>

            <template v-else>
              <div class="group/sec flex items-center gap-2 px-2 py-2 bg-primary-50/60 border-b border-neutral-200 border-l-[3px] border-l-primary-400">
                <div
                  class="drag-handle cursor-grab active:cursor-grabbing flex items-center justify-center w-5 shrink-0 text-neutral-300 group-hover/sec:text-neutral-500"
                  :title="t('tri.quote.drag_handle')"
                >
                  <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <circle cx="5" cy="4" r="1.2" /><circle cx="11" cy="4" r="1.2" />
                    <circle cx="5" cy="8" r="1.2" /><circle cx="11" cy="8" r="1.2" />
                    <circle cx="5" cy="12" r="1.2" /><circle cx="11" cy="12" r="1.2" />
                  </svg>
                </div>
                <input
                  v-model="block.title"
                  class="flex-1 h-9 px-2 rounded-md text-[15px] font-semibold text-neutral-800 bg-transparent border border-transparent hover:border-neutral-200 shadow-xs outline-none focus-ring"
                />
                <span class="text-xs text-neutral-400 tabular-nums shrink-0">{{ block.items.length }}</span>
              </div>

              <draggable
                v-model="block.items"
                :item-key="sectionItemKey"
                handle=".drag-handle"
                :group="sectionItemsGroup"
                :animation="150"
                ghost-class="notion-ghost"
                chosen-class="notion-chosen"
                drag-class="notion-drag"
                class="min-h-[8px] divide-y divide-neutral-200"
                @change="onSectionItemsChange"
              >
                <template #item="{ element: rawRow, index: ii }">
                  <div
                    data-block-kind="item"
                    :data-key="sectionItemKey(rawRow)"
                    class="group/row bg-primary-50/15"
                  >
                    <template v-for="row in (itemRowOf(rawRow) ? [itemRowOf(rawRow)!] : [])" :key="row._uid">
                      <div class="grid items-stretch" :style="{ gridTemplateColumns }">
                        <div
                          class="drag-handle cursor-grab active:cursor-grabbing flex items-center justify-center border-r border-neutral-200 border-l-[3px] border-l-primary-400 text-neutral-300 group-hover/row:text-neutral-500"
                          :title="t('tri.quote.drag_handle')"
                        >
                          <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <circle cx="5" cy="4" r="1.2" /><circle cx="11" cy="4" r="1.2" />
                            <circle cx="5" cy="8" r="1.2" /><circle cx="11" cy="8" r="1.2" />
                            <circle cx="5" cy="12" r="1.2" /><circle cx="11" cy="12" r="1.2" />
                          </svg>
                        </div>
                        <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                          <input v-model="row.designation" class="w-full h-full min-h-9 px-2 text-sm bg-transparent outline-none focus-ring" />
                        </div>
                        <div class="flex min-w-0 border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                          <QuoteLineImageControl
                            :variant-id="variantId"
                            :image="row.image ?? null"
                            :disabled="!canWrite"
                            @uploaded="setRowImage(row, $event)"
                            @removed="removeRowImage(row)"
                          />
                          <textarea
                            v-model="row.title"
                            v-autosize
                            rows="1"
                            class="min-w-0 flex-1 min-h-9 py-2 px-2 text-sm bg-transparent outline-none focus-ring resize-none overflow-hidden whitespace-pre-wrap leading-snug"
                          />
                        </div>
                        <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                          <input
                            v-model.number="row.quantity"
                            type="number"
                            step="0.001"
                            :class="[noSpinClass, 'w-full h-full min-h-9 px-2 text-sm font-mono tabular-nums bg-transparent outline-none focus-ring']"
                          />
                        </div>
                        <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                          <input
                            v-model.number="row.base_unit_price"
                            type="number"
                            step="1"
                            :class="[noSpinClass, 'w-full h-full min-h-9 px-2 text-sm font-mono tabular-nums text-right bg-transparent outline-none focus-ring']"
                          />
                        </div>
                        <div class="border-r border-neutral-200 focus-within:ring-2 focus-within:ring-inset focus-within:ring-primary-500/30">
                          <select v-model.number="row.vat_rate" class="w-full h-full min-h-9 px-1 text-sm font-mono tabular-nums text-right bg-transparent outline-none focus-ring">
                            <option :value="21">21</option>
                            <option :value="12">12</option>
                            <option :value="0">0</option>
                          </select>
                        </div>
                        <div class="border-r border-neutral-200 px-2 py-1.5 flex flex-col items-end justify-center font-mono text-sm tabular-nums leading-tight">
                          <span>{{ formatLineAmount(lineOffer(row)) }}</span>
                          <template v-if="!isPanelOpen(row._uid) && lineHasDiscount(row)">
                            <span class="text-[11px] text-danger-600">−{{ formatLineAmount(lineOwnDiscount(row)) }}</span>
                            <span class="text-[11px] text-neutral-700">{{ formatLineAmount(lineAfter(row)) }}</span>
                          </template>
                          <span
                            v-if="!isPanelOpen(row._uid) && lineHasMarkup(row)"
                            class="inline-flex items-center px-1 mt-0.5 rounded text-[10px] font-medium bg-primary-50 text-primary-700"
                          >+{{ formatLineAmount(lineResult(row)?.markupAmount ?? 0) }}</span>
                        </div>
                        <div class="flex items-center justify-center gap-0.5 opacity-0 group-hover/row:opacity-100 focus-within:opacity-100 transition-opacity">
                          <button
                            type="button"
                            class="cursor-pointer text-neutral-400 hover:text-primary-600 text-sm leading-none w-5 h-5 font-semibold"
                            :class="isPanelOpen(row._uid) && 'text-primary-600'"
                            :title="t('tri.quote.adjustments_toggle')"
                            @click="togglePanel(row._uid)"
                          >%</button>
                          <button
                            type="button"
                            class="cursor-pointer text-danger-500 hover:text-danger-600 text-base leading-none w-5 h-5"
                            :title="t('common.delete')"
                            @click="emit('deleteSectionItem', block, ii)"
                          >×</button>
                        </div>
                      </div>
                      <QuoteAdjustmentPanel
                        v-if="isPanelOpen(row._uid)"
                        v-model:discount-type="row.line_discount_type"
                        v-model:discount-value="row.line_discount_value"
                        v-model:commission-type="row.markup_type"
                        v-model:commission-value="row.markup_value"
                        :commission-overridden="isLineCommissionOverridden(block)"
                        :offer-amount="lineOffer(row)"
                        :discount-amount="lineOwnDiscount(row)"
                        :commission-amount="lineResult(row)?.markupAmount"
                        :final-amount="lineAfter(row)"
                      />
                    </template>
                  </div>
                </template>
              </draggable>

              <div class="border-t border-neutral-200 border-l-[3px] border-l-primary-400 bg-neutral-50">
                <div class="flex items-center gap-2 px-2 py-2">
                  <button
                    type="button"
                    class="cursor-pointer text-xs text-primary-700 hover:text-primary-800 shrink-0"
                    @click="emit('addLineToSection', block)"
                  >
                    + {{ t('tri.quote.add_line_to_section') }}
                  </button>
                  <div class="ml-auto flex items-center gap-2 min-w-0">
                    <div class="flex flex-col items-end text-sm leading-tight min-w-0">
                      <div class="flex items-center gap-1.5 flex-wrap justify-end">
                        <span class="text-neutral-600 text-xs truncate">{{ sectionTotalLabel(block) }}</span>
                        <span class="font-semibold font-mono tabular-nums text-neutral-900">{{ formatLineAmount(sectionOfferTotal(block)) }}</span>
                      </div>
                      <template v-if="!isPanelOpen(block.tempId) && sectionDiscountAmount(block) > 0">
                        <span class="text-[11px] text-danger-600 font-mono tabular-nums">−{{ formatLineAmount(sectionDiscountAmount(block)) }}</span>
                        <span class="text-[11px] text-neutral-700 font-mono tabular-nums">{{ formatLineAmount(sectionTotal(block)) }}</span>
                      </template>
                    </div>
                    <button
                      type="button"
                      class="cursor-pointer text-neutral-400 hover:text-primary-600 text-sm leading-none w-5 h-5 font-semibold shrink-0"
                      :class="isPanelOpen(block.tempId) && 'text-primary-600'"
                      :title="t('tri.quote.adjustments_toggle')"
                      @click="togglePanel(block.tempId)"
                    >%</button>
                    <button
                      type="button"
                      class="cursor-pointer text-danger-500 hover:text-danger-600 text-base leading-none w-5 h-5 shrink-0"
                      :title="t('tri.quote.delete_section')"
                      @click="emit('deleteSection', bi)"
                    >×</button>
                  </div>
                </div>
                <div v-if="isPanelOpen(block.tempId)" class="border-t border-neutral-200">
                  <QuoteAdjustmentPanel
                    v-model:discount-type="block.discount_type"
                    v-model:discount-value="block.discount_value"
                    v-model:commission-type="block.commission_type"
                    v-model:commission-value="block.commission_value"
                    :commission-overridden="hasQuoteCommission"
                    :offer-amount="sectionOfferTotal(block)"
                    :discount-amount="sectionDiscountAmount(block)"
                    :final-amount="sectionTotal(block)"
                  />
                </div>
              </div>
            </template>
          </div>
        </template>
      </draggable>
    </div>

    <div class="flex gap-2">
      <UiButton type="button" variant="outline" size="sm" @click="emit('addSection')">
        {{ t('tri.quote.add_section') }}
      </UiButton>
      <UiButton type="button" variant="outline" size="sm" @click="emit('addLine')">
        {{ t('tri.quote.add_line') }}
      </UiButton>
    </div>
  </div>
</template>

<style scoped>
.notion-ghost {
  opacity: 0.45;
  background: rgb(239 246 255);
}
.notion-chosen {
  background: rgb(249 250 251);
}
.notion-drag {
  opacity: 1 !important;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}
</style>

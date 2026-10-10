<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import type { TriQuoteLineItem } from '@/api/tri'
import type { TriAdjustmentType } from '@/composables/useTriQuotePricing'
import QuoteAdjustmentPanel from './QuoteAdjustmentPanel.vue'

import OfficeMenu from '@/components/tri/OfficeMenu.vue'
import Modal from '@/components/ui/Modal.vue'
import OfferItemRow from './OfferItemRow.vue'
import OfferTableHeader from './OfferTableHeader.vue'
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





const blocks = defineModel<Block[]>('blocks', { required: true })

const emit = defineEmits<{
  setTotal: [row: Row, value: string]
  traveler: [row: Row]
  addLine: []
  addLineToSection: [section: SectionBlock]
  insertFromCatalog: []
  insertFromCatalogSection: [section: SectionBlock]
  addSection: []
  deleteStandalone: [bi: number]
  deleteSectionItem: [section: SectionBlock, ii: number]
  deleteSection: [bi: number]
}>()

const { t } = useI18n()

const collapsed = ref(new Set<string>())
const discountSection = ref<SectionBlock | null>(null)
function collapse(id:string) { const next=new Set(collapsed.value); next.has(id)?next.delete(id):next.add(id);collapsed.value=next }
function move(bi:number, delta:number, section?:SectionBlock) {
  if(section) { const target=bi+delta; if(target>=0 && target<section.items.length) { const [row]=section.items.splice(bi,1);section.items.splice(target,0,row) } else { const [row]=section.items.splice(bi,1); const si=blocks.value.indexOf(section);blocks.value.splice(delta<0?si:si+1,0,{kind:'item',row}) } }
  else { const target=bi+delta;if(target<0||target>=blocks.value.length)return;const block=blocks.value[bi];const neighbor=blocks.value[target];if(block.kind==='item'&&neighbor.kind==='section') {blocks.value.splice(bi,1);neighbor.items.splice(delta<0?neighbor.items.length:0,0,block.row);collapsed.value.delete(neighbor.tempId)} else { blocks.value.splice(bi,1);blocks.value.splice(target,0,block) } }
  normalizeAfterDrag()
}
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







</script>

<template>
<div>
  <div class="flex justify-between items-center mb-5"><h2>{{ t('office.offer_items') }}</h2><span class="text-xs text-neutral-500">{{ t('office.prices_net') }}</span></div>
  <div class="overflow-x-auto pb-3">
  <draggable v-model="blocks" :item-key="blockKey" handle=".drag-handle" :group="topLevelGroup" :animation="150" :disabled="!canWrite" ghost-class="notion-ghost" class="offer-blocks min-w-[1000px]" @change="onTopLevelChange">
    <template #item="{element:block,index:bi}">
    <div :data-block-kind="isSectionBlock(block)?'section':'item'" class="border border-neutral-200 bg-surface">
      <template v-if="isSectionBlock(block)">
        <div class="flex items-center gap-3 bg-primary-50 px-3 py-2">
          <button type="button" class="drag-handle w-8 h-9 cursor-grab" :disabled="!canWrite" :aria-label="t('office.move_hint')" @keydown.alt.up.prevent="move(bi,-1)" @keydown.alt.down.prevent="move(bi,1)">⠿</button>
          <button type="button" class="w-8 h-9" :aria-expanded="!collapsed.has(block.tempId)" :aria-label="t('office.collapse_section')" @click="collapse(block.tempId)">{{ collapsed.has(block.tempId)?'›':'⌄' }}</button>
          <input v-model="block.title" :disabled="!canWrite" class="bg-transparent font-semibold min-w-0 flex-1" :aria-label="t('tri.quote.sections')" />
          <span v-if="block.discount_value" class="office-role">{{ t('tri.quote.discount') }} {{ block.discount_value }} {{ block.discount_type==='percent'?'%':'' }}</span>
          <span class="text-xs text-neutral-500">{{ block.items.length }}</span><strong class="text-primary-700">{{ formatLineAmount(sectionTotal(block)) }}</strong>
          <OfficeMenu v-if="canWrite"><button type="button" @click="discountSection=block">{{ t('office.section_discount') }}</button><button type="button" class="text-danger-600" @click="emit('deleteSection',bi)">{{ t('tri.quote.delete_section') }}</button></OfficeMenu>
        </div>
        <div v-show="!collapsed.has(block.tempId)">
          <OfferTableHeader />
          <draggable v-model="block.items" :item-key="sectionItemKey" handle=".drag-handle" :group="sectionItemsGroup" :animation="150" :disabled="!canWrite" ghost-class="notion-ghost" class="min-h-8" @change="onSectionItemsChange">
            <template #item="{element:row,index:ii}"><OfferItemRow :row="itemRowOf(row) || row" :total="lineAfter(itemRowOf(row)||row)" :discount="lineOwnDiscount(itemRowOf(row)||row)" :variant-id="variantId" :can-write="canWrite" @remove="emit('deleteSectionItem',block,ii)" @move="move(ii,$event,block)" @total="emit('setTotal',itemRowOf(row)||row,$event)" @traveler="emit('traveler',itemRowOf(row)||row)" /></template>
          </draggable>
          <div v-if="canWrite" class="flex gap-3 p-4"><UiButton variant="secondary" size="sm" @click="emit('addLineToSection',block)">{{ t('office.item_in_section') }}</UiButton><UiButton variant="secondary" size="sm" @click="emit('insertFromCatalogSection',block)">{{ t('tri.quote.insert_from_catalog') }}</UiButton></div>
        </div>
      </template>
      <template v-else-if="itemRowOf(block)"><OfferTableHeader v-if="bi===0 || isSectionBlock(blocks[bi-1])" /><OfferItemRow :row="itemRowOf(block)!" :total="lineAfter(itemRowOf(block)!)" :discount="lineOwnDiscount(itemRowOf(block)!)" :variant-id="variantId" :can-write="canWrite" @remove="emit('deleteStandalone',bi)" @move="move(bi,$event)" @total="emit('setTotal',itemRowOf(block)!,$event)" @traveler="emit('traveler',itemRowOf(block)!)" /></template>
    </div>
    </template>
  </draggable>
  </div>
  <div v-if="canWrite" class="flex gap-3 flex-wrap mt-5"><UiButton variant="secondary" @click="emit('addLine')">{{ t('tri.quote.add_line') }}</UiButton><UiButton variant="secondary" @click="emit('insertFromCatalog')">{{ t('tri.quote.insert_from_catalog') }}</UiButton><UiButton variant="secondary" @click="emit('addSection')">{{ t('tri.quote.add_section') }}</UiButton></div>
  <Modal v-if="discountSection" :title="t('office.section_discount')" width-class="max-w-md" @close="discountSection=null"><QuoteAdjustmentPanel v-model:discount-type="discountSection.discount_type" v-model:discount-value="discountSection.discount_value" /><template #footer><UiButton @click="discountSection=null">{{ t('common.close') }}</UiButton></template></Modal>
</div>
</template>

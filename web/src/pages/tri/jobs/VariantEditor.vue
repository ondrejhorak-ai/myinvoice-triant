<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, RouterLink, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriQuoteVariant, type TriQuoteLineItem, type TriJob, type TriVariantStatus } from '@/api/tri'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import {
  calculateVariantPricing,
  type TriAdjustmentType,
  type TriPricingLine,
  type TriPricingSection,
} from '@/composables/useTriQuotePricing'
import JobHeaderInfo from './JobHeaderInfo.vue'
import QuoteAdjustmentPanel from './QuoteAdjustmentPanel.vue'

const { t, locale } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

const variantStatusOptions: TriVariantStatus[] = ['draft', 'sent', 'approved']
const statusUpdating = ref(false)

const jobId = computed(() => Number(route.params.jobId))
const variantId = computed(() => Number(route.params.variantId))

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

const variant = ref<TriQuoteVariant | null>(null)
const job = ref<TriJob | null>(null)
const loading = ref(true)
const saving = ref(false)

const blocks = ref<Block[]>([])
const quoteDiscountType = ref<TriAdjustmentType | ''>('percent')
const quoteDiscountValue = ref<number | null>(null)
const quoteCommissionType = ref<TriAdjustmentType | ''>('percent')
const quoteCommissionValue = ref<number | null>(null)
const noteAboveItems = ref('')
const noteBelowItems = ref('')
const expandedPanels = ref<Set<string>>(new Set())

const inputGhostClass =
  'h-8 px-2 rounded-md text-sm bg-transparent border border-transparent hover:border-neutral-200 focus:bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none'
const titleAreaClass =
  'min-h-8 py-1.5 px-2 rounded-md text-sm bg-transparent border border-transparent hover:border-neutral-200 focus:bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none resize-none overflow-hidden whitespace-pre-wrap leading-snug'
const noSpinClass =
  '[appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none'
const gridCols =
  'grid grid-cols-[20px_88px_minmax(160px,1fr)_56px_76px_52px_88px_44px] gap-1.5 items-start'
const rowHoverActionClass = 'opacity-0 group-hover:opacity-100 transition-opacity'
const arrowCellClass = 'w-5 shrink-0 text-center text-[10px] text-neutral-400 leading-none'
const arrowBtnClass = 'block w-full h-3.5 hover:text-neutral-700 disabled:opacity-30 cursor-pointer'
const delBtnClass =
  'cursor-pointer text-danger-500 hover:text-danger-600 text-base leading-none w-4 shrink-0'
const adjBtnClass =
  'cursor-pointer text-neutral-400 hover:text-primary-600 text-sm leading-none w-4 shrink-0 font-semibold'
const adjBtnActiveClass = 'text-primary-600'
const inputRightClass = 'text-right'
const lineTotalClass =
  'min-h-8 py-0.5 flex flex-col items-end justify-center text-right font-mono text-sm tabular-nums leading-tight'
const lineTotalSubClass = 'text-[11px] leading-tight'

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

let uidSeq = 0
function newUid(): string {
  uidSeq += 1
  return `r${Date.now()}_${uidSeq}`
}

function formatLineAmount(value: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value)
}

function wrapRow(l: TriQuoteLineItem): Row {
  const row: Row = { ...l, _uid: newUid() }
  row.markup_type ??= 'percent'
  row.line_discount_type ??= 'percent'
  return row
}

function newRow(): Row {
  return wrapRow({
    designation: '',
    title: '',
    quantity: 1,
    unit: 'ks',
    base_unit_price: 0,
    vat_rate: 21,
    section_temp_id: null,
    markup_value: null,
    line_discount_value: null,
  })
}

function togglePanel(id: string) {
  const next = new Set(expandedPanels.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedPanels.value = next
}

function isPanelOpen(id: string): boolean {
  return expandedPanels.value.has(id)
}

function buildPricingInput(): { sections: TriPricingSection[]; lines: TriPricingLine[] } {
  const sections: TriPricingSection[] = []
  const lines: TriPricingLine[] = []
  for (const b of blocks.value) {
    if (b.kind === 'section') {
      sections.push({
        tempId: b.tempId,
        discount_type: b.discount_type ?? null,
        discount_value: b.discount_value ?? null,
        commission_type: b.commission_type ?? null,
        commission_value: b.commission_value ?? null,
      })
      for (const row of b.items) {
        lines.push(rowToPricingLine(row, b.tempId))
      }
    } else {
      lines.push(rowToPricingLine(b.row, null))
    }
  }
  return { sections, lines }
}

function rowToPricingLine(row: Row, sectionTempId: string | null): TriPricingLine {
  return {
    quantity: Number(row.quantity) || 0,
    base_unit_price: Number(row.base_unit_price) || 0,
    vat_rate: Number(row.vat_rate) || 21,
    markup_type: row.markup_type ?? null,
    markup_value: row.markup_value ?? null,
    line_discount_type: row.line_discount_type ?? null,
    line_discount_value: row.line_discount_value ?? null,
    quote_section_id: null,
    section_temp_id: sectionTempId,
  }
}

const pricingResult = computed(() => {
  const { sections, lines } = buildPricingInput()
  return calculateVariantPricing(sections, lines, {
    quote_discount_type: quoteDiscountType.value || null,
    quote_discount_value: quoteDiscountValue.value,
    quote_commission_type: quoteCommissionType.value || null,
    quote_commission_value: quoteCommissionValue.value,
  })
})

const lineIndexByUid = computed(() => {
  const map = new Map<string, number>()
  let idx = 0
  for (const b of blocks.value) {
    if (b.kind === 'item') map.set(b.row._uid, idx++)
    else for (const row of b.items) map.set(row._uid, idx++)
  }
  return map
})

const hasQuoteCommission = computed(
  () => !!quoteCommissionType.value && (quoteCommissionValue.value ?? 0) > 0,
)

function lineResult(row: Row) {
  const idx = lineIndexByUid.value.get(row._uid)
  if (idx == null) return null
  return pricingResult.value.lines[idx] ?? null
}

function linePreview(row: Row): number {
  return lineResult(row)?.lineTotal ?? 0
}

function lineOffer(row: Row): number {
  return lineResult(row)?.offer ?? 0
}

function lineAfter(row: Row): number {
  const r = lineResult(row)
  if (!r) return 0
  return r.afterLineDiscount
}

function lineOwnDiscount(row: Row): number {
  const r = lineResult(row)
  if (!r) return 0
  return Math.max(0, r.offer - r.afterLineDiscount)
}

function sectionTotal(section: SectionBlock): number {
  return section.items.reduce((s, r) => s + linePreview(r), 0)
}

function sectionOfferTotal(section: SectionBlock): number {
  return section.items.reduce((s, r) => s + (lineResult(r)?.offer ?? 0), 0)
}

function sectionDiscountAmount(section: SectionBlock): number {
  return Math.max(0, sectionOfferTotal(section) - sectionTotal(section))
}

function isLineCommissionOverridden(section?: SectionBlock): boolean {
  if (hasQuoteCommission.value) return true
  if (!section) return false
  return !!(section.commission_type && (section.commission_value ?? 0) > 0)
}

const previewTotals = computed(() => pricingResult.value)

function gapBeforeBlock(bi: number): boolean {
  if (bi === 0) return blocks.value[0]?.kind === 'section'
  return blocks.value[bi]?.kind === 'section' || blocks.value[bi - 1]?.kind === 'section'
}

const lastBlockIsSection = computed(
  () => blocks.value[blocks.value.length - 1]?.kind === 'section',
)

// --- Reordering (continuous walk with arrows) ---

function moveStandaloneItem(bi: number, dir: -1 | 1) {
  const target = bi + dir
  if (target < 0 || target >= blocks.value.length) return
  const self = blocks.value[bi]
  const neighbor = blocks.value[target]
  if (self.kind !== 'item') return
  if (neighbor.kind === 'section') {
    blocks.value.splice(bi, 1)
    if (dir === 1) neighbor.items.unshift(self.row)
    else neighbor.items.push(self.row)
  } else {
    blocks.value[bi] = neighbor
    blocks.value[target] = self
  }
}

function moveSectionItem(section: SectionBlock, ii: number, dir: -1 | 1) {
  const items = section.items
  const target = ii + dir
  if (target >= 0 && target < items.length) {
    const tmp = items[ii]
    items[ii] = items[target]
    items[target] = tmp
    return
  }
  const si = blocks.value.indexOf(section)
  if (si === -1) return
  const [row] = items.splice(ii, 1)
  row.quote_section_id = null
  row.section_temp_id = null
  if (dir === 1) blocks.value.splice(si + 1, 0, { kind: 'item', row })
  else blocks.value.splice(si, 0, { kind: 'item', row })
}

function moveSection(bi: number, dir: -1 | 1) {
  const target = bi + dir
  if (target < 0 || target >= blocks.value.length) return
  const tmp = blocks.value[bi]
  blocks.value[bi] = blocks.value[target]
  blocks.value[target] = tmp
}

// --- Add / delete ---

function addLine() {
  blocks.value.push({ kind: 'item', row: newRow() })
}

function addLineToSection(section: SectionBlock) {
  section.items.push(newRow())
}

function addSection() {
  blocks.value.push({
    kind: 'section',
    tempId: `s${Date.now()}_${(uidSeq += 1)}`,
    title: t('tri.quote.sections'),
    discount_type: 'percent',
    discount_value: null,
    commission_type: 'percent',
    commission_value: null,
    items: [],
  })
}

function deleteStandalone(bi: number) {
  if (!confirm(t('tri.quote.delete_line_confirm'))) return
  blocks.value.splice(bi, 1)
}

function deleteSectionItem(section: SectionBlock, ii: number) {
  if (!confirm(t('tri.quote.delete_line_confirm'))) return
  section.items.splice(ii, 1)
}

function deleteSection(bi: number) {
  const b = blocks.value[bi]
  if (b.kind !== 'section') return
  if (!confirm(t('tri.quote.delete_section_confirm'))) return
  const fallout: ItemBlock[] = b.items.map((row) => ({ kind: 'item', row }))
  blocks.value.splice(bi, 1, ...fallout)
}

// --- Load / save ---

function buildBlocks(v: TriQuoteVariant) {
  const sections = [...v.sections].sort((a, b) => a.sort_order - b.sort_order)
  const lines = [...v.line_items].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
  const sectionBlocks = new Map<number, SectionBlock>()
  const topLevel: { sort: number; block: Block }[] = []
  for (const s of sections) {
    const sb: SectionBlock = {
      kind: 'section',
      tempId: String(s.id),
      title: s.title,
      discount_type: s.discount_type ?? 'percent',
      discount_value: s.discount_value ?? null,
      commission_type: s.commission_type ?? 'percent',
      commission_value: s.commission_value ?? null,
      items: [],
    }
    sectionBlocks.set(s.id, sb)
    topLevel.push({ sort: s.sort_order, block: sb })
  }
  for (const l of lines) {
    const sid = l.quote_section_id
    if (sid != null && sectionBlocks.has(sid)) {
      sectionBlocks.get(sid)!.items.push(wrapRow(l))
    } else {
      topLevel.push({ sort: l.sort_order ?? 0, block: { kind: 'item', row: wrapRow(l) } })
    }
  }
  topLevel.sort((a, b) => a.sort - b.sort)
  blocks.value = topLevel.map((t) => t.block)
}

async function load() {
  loading.value = true
  try {
    const [v, j] = await Promise.all([
      triApi.variants.get(variantId.value),
      triApi.jobs.get(jobId.value),
    ])
    variant.value = v
    job.value = j
    quoteDiscountType.value = (v.quote_discount_type as TriAdjustmentType) ?? 'percent'
    quoteDiscountValue.value = v.quote_discount_value
    quoteCommissionType.value = (v.quote_commission_type as TriAdjustmentType) ?? 'percent'
    quoteCommissionValue.value = v.quote_commission_value
    noteAboveItems.value = v.note_above_items ?? ''
    noteBelowItems.value = v.note_below_items ?? ''
    buildBlocks(v)
    syncBaseline()
  } finally {
    loading.value = false
  }
}

function stripRow(r: Row): TriQuoteLineItem {
  const { _uid, ...rest } = r
  void _uid
  return rest
}

function serializeState(): string {
  const serializedBlocks = blocks.value.map((b) => {
    if (b.kind === 'item') {
      return { kind: 'item' as const, row: stripRow(b.row) }
    }
    return {
      kind: 'section' as const,
      tempId: b.tempId,
      title: b.title,
      discount_type: b.discount_type ?? null,
      discount_value: b.discount_value ?? null,
      commission_type: b.commission_type ?? null,
      commission_value: b.commission_value ?? null,
      items: b.items.map(stripRow),
    }
  })
  return JSON.stringify({
    blocks: serializedBlocks,
    quoteDiscountType: quoteDiscountType.value,
    quoteDiscountValue: quoteDiscountValue.value,
    quoteCommissionType: quoteCommissionType.value,
    quoteCommissionValue: quoteCommissionValue.value,
    noteAboveItems: noteAboveItems.value,
    noteBelowItems: noteBelowItems.value,
    jobDate: variant.value?.job_date ?? null,
  })
}

const baseline = ref('')
function syncBaseline() {
  baseline.value = serializeState()
}

const isDirty = computed(() => !loading.value && serializeState() !== baseline.value)

async function save() {
  if (!variant.value) return
  saving.value = true
  try {
    const sectionsPayload: Record<string, unknown>[] = []
    const linesPayload: Record<string, unknown>[] = []
    let pos = 0
    for (const b of blocks.value) {
      if (b.kind === 'item') {
        linesPayload.push({ ...stripRow(b.row), quote_section_id: null, section_temp_id: null, sort_order: pos++ })
      } else {
        sectionsPayload.push({
          temp_id: b.tempId,
          title: b.title,
          sort_order: pos++,
          discount_type: b.discount_type || null,
          discount_value: b.discount_value,
          commission_type: b.commission_type || null,
          commission_value: b.commission_value,
        })
        for (const r of b.items) {
          linesPayload.push({ ...stripRow(r), quote_section_id: null, section_temp_id: b.tempId, sort_order: pos++ })
        }
      }
    }
    const payload = {
      lock_version: variant.value.lock_version,
      job_date: variant.value.job_date,
      internal_notes: variant.value.internal_notes,
      quote_discount_type: quoteDiscountType.value || null,
      quote_discount_value: quoteDiscountValue.value,
      quote_commission_type: quoteCommissionType.value || null,
      quote_commission_value: quoteCommissionValue.value,
      note_above_items: noteAboveItems.value || null,
      note_below_items: noteBelowItems.value || null,
      sections: sectionsPayload,
      line_items: linesPayload,
    }
    variant.value = await triApi.variants.save(variantId.value, payload)
    buildBlocks(variant.value)
    syncBaseline()
    toast.success(t('tri.quote.saved'))
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: { code?: string } } } }
    if (err.response?.data?.error?.code === 'conflict') {
      toast.error(t('tri.quote.lock_conflict'))
      await load()
    } else {
      toast.error(t('common.error'))
    }
  } finally {
    saving.value = false
  }
}

async function changeVariantStatus(status: TriVariantStatus) {
  if (!variant.value || variant.value.status === status || statusUpdating.value) return
  statusUpdating.value = true
  try {
    const updated = await triApi.variants.updateStatus(variantId.value, status)
    variant.value.status = updated.status
    toast.success(t('common.saved'))
  } catch {
    toast.error(t('common.error'))
  } finally {
    statusUpdating.value = false
  }
}

function variantStatusPillClass(status: TriVariantStatus, active: boolean): string {
  const base = 'h-8 px-3 text-sm rounded-md cursor-pointer transition-colors disabled:opacity-50 disabled:cursor-default'
  if (!active) {
    return `${base} text-neutral-500 hover:text-neutral-900`
  }
  const map: Record<TriVariantStatus, string> = {
    draft: 'text-neutral-800',
    sent: 'text-teal-700',
    approved: 'text-success-700',
  }
  return `${base} bg-surface shadow-sm font-semibold ${map[status]}`
}

onBeforeRouteLeave(() => {
  if (isDirty.value && !confirm(t('tri.quote.unsaved_leave_confirm'))) return false
  return true
})

function onBeforeUnload(e: BeforeUnloadEvent) {
  if (isDirty.value) e.preventDefault()
}

onMounted(() => {
  load()
  window.addEventListener('beforeunload', onBeforeUnload)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', onBeforeUnload)
})
</script>

<template>
  <div v-if="loading" class="text-neutral-500">{{ t('common.loading') }}</div>
  <div v-else-if="variant" class="max-w-5xl space-y-4">
    <RouterLink
      :to="{ name: 'tri-job-detail', params: { id: jobId } }"
      class="inline-flex items-center text-sm text-neutral-600 hover:text-neutral-900"
    >
      ← {{ t('tri.jobs.detail') }}
    </RouterLink>

    <!-- Záhlaví varianty -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Horní pruh: zakázka + výrazná varianta + stav + datum vytvoření -->
      <div class="flex flex-col gap-4 p-5 border-b border-neutral-200 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.quote.header_job') }}</p>
          <p class="mt-1 text-3xl font-bold font-mono tracking-tight text-neutral-900">{{ variant.job_number }}</p>
          <h1 class="mt-1 text-lg font-medium text-neutral-600 break-words">{{ variant.job_title }}</h1>
        </div>

        <div class="flex flex-wrap items-start gap-5 shrink-0">
          <!-- Výrazné zobrazení varianty -->
          <div>
            <span class="block text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.variant_label') }}</span>
            <div class="flex h-10 min-w-10 items-center justify-center rounded-lg bg-primary-600 px-3 shadow-sm">
              <span class="text-xl font-bold leading-none text-white">{{ variant.variant_code }}</span>
            </div>
          </div>

          <!-- Stav varianty -->
          <div>
            <span class="block text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.status_label') }}</span>
            <div
              v-if="auth.canWrite"
              class="inline-flex h-10 items-center gap-0.5 rounded-lg bg-neutral-100 p-1"
            >
              <button
                v-for="s in variantStatusOptions"
                :key="s"
                type="button"
                :disabled="statusUpdating"
                :class="variantStatusPillClass(s, variant.status === s)"
                @click="changeVariantStatus(s)"
              >
                {{ t(`tri.quote.status_${s}`) }}
              </button>
            </div>
            <p v-else class="h-10 flex items-center text-sm font-medium text-neutral-800">{{ t(`tri.quote.status_${variant.status}`) }}</p>
          </div>

          <!-- Datum vytvoření (předvyplněné, ručně upravitelné) -->
          <div>
            <label class="block text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.created_date') }}</label>
            <input
              v-model="variant.job_date"
              type="date"
              class="h-10 px-3 border border-neutral-300 rounded-lg text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
            />
          </div>
        </div>
      </div>

      <JobHeaderInfo
        v-if="job"
        :contacts="job.contacts ?? []"
        :site-street="job.site_street"
        :site-city="job.site_city"
        :site-zip="job.site_zip"
      />
    </div>

    <!-- Poznámka nad položkami -->
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tri.quote.note_above') }}</label>
      <textarea v-model="noteAboveItems" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
    </div>

    <!-- Items / sections table -->
    <div
      v-if="blocks.length"
      class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden"
    >
      <!-- Column header -->
      <div
        :class="[gridCols, 'bg-neutral-50 border-b border-neutral-200 px-3 py-2 text-xs uppercase tracking-wide text-neutral-500']"
      >
        <span></span>
        <span class="px-2">{{ t('tri.quote.designation') }}</span>
        <span class="px-2">{{ t('tri.jobs.title_field') }}</span>
        <span class="px-2">{{ t('tri.quote.quantity') }}</span>
        <span class="px-2 text-right">{{ t('tri.quote.unit_price') }}</span>
        <span class="px-2 text-right">{{ t('tri.quote.vat_rate') }}</span>
        <span class="text-right">{{ t('tri.quote.line_total') }}</span>
        <span></span>
      </div>

      <template v-for="(block, bi) in blocks" :key="block.kind === 'section' ? block.tempId : block.row._uid">
        <div v-if="gapBeforeBlock(bi)" class="bg-neutral-50 h-6" aria-hidden="true"></div>

        <!-- Standalone item -->
        <div v-if="block.kind === 'item'" class="border-b border-neutral-100">
          <div :class="[gridCols, 'group hover:bg-neutral-50 px-3 py-2']">
            <div :class="[arrowCellClass, rowHoverActionClass]">
              <button type="button" :class="arrowBtnClass" :disabled="bi === 0" :title="t('tri.quote.move_up')" @click="moveStandaloneItem(bi, -1)">▲</button>
              <button type="button" :class="arrowBtnClass" :disabled="bi === blocks.length - 1" :title="t('tri.quote.move_down')" @click="moveStandaloneItem(bi, 1)">▼</button>
            </div>
            <input v-model="block.row.designation" :class="[inputGhostClass, 'w-full']" />
            <textarea v-model="block.row.title" v-autosize rows="1" :class="[titleAreaClass, 'w-full']"></textarea>
            <input v-model.number="block.row.quantity" type="number" step="0.001" :class="[inputGhostClass, noSpinClass, 'w-full']" />
            <input v-model.number="block.row.base_unit_price" type="number" step="0.01" :class="[inputGhostClass, noSpinClass, inputRightClass, 'w-full']" />
            <select v-model.number="block.row.vat_rate" :class="[inputGhostClass, inputRightClass, 'w-full']">
              <option :value="21">21</option>
              <option :value="12">12</option>
              <option :value="0">0</option>
            </select>
            <div :class="lineTotalClass">
              <span>{{ formatLineAmount(lineOffer(block.row)) }}</span>
              <template v-if="!isPanelOpen(block.row._uid) && lineOwnDiscount(block.row) > 0">
                <span :class="[lineTotalSubClass, 'text-danger-600']">−{{ formatLineAmount(lineOwnDiscount(block.row)) }}</span>
                <span :class="[lineTotalSubClass, 'text-neutral-700']">{{ formatLineAmount(lineAfter(block.row)) }}</span>
              </template>
            </div>
            <div :class="['flex items-center justify-center gap-0.5', rowHoverActionClass]">
              <button
                type="button"
                :class="[adjBtnClass, isPanelOpen(block.row._uid) && adjBtnActiveClass]"
                :title="t('tri.quote.adjustments_toggle')"
                @click="togglePanel(block.row._uid)"
              >%</button>
              <button type="button" :class="delBtnClass" :title="t('common.delete')" @click="deleteStandalone(bi)">×</button>
            </div>
          </div>
          <div v-if="isPanelOpen(block.row._uid)" :class="gridCols">
            <QuoteAdjustmentPanel
              v-model:discount-type="block.row.line_discount_type"
              v-model:discount-value="block.row.line_discount_value"
              v-model:commission-type="block.row.markup_type"
              v-model:commission-value="block.row.markup_value"
              :commission-overridden="isLineCommissionOverridden()"
              :offer-amount="lineOffer(block.row)"
              :discount-amount="lineOwnDiscount(block.row)"
              :commission-amount="lineResult(block.row)?.markupAmount"
              :final-amount="lineAfter(block.row)"
            />
          </div>
        </div>

        <!-- Section -->
        <template v-else>
          <!-- Section header strip -->
          <div class="group bg-primary-50/60 border-b border-neutral-200 border-l-[3px] border-l-primary-400">
            <div class="flex items-center gap-1.5 px-3 py-2">
              <div :class="[arrowCellClass, rowHoverActionClass]">
                <button type="button" :class="arrowBtnClass" :disabled="bi === 0" :title="t('tri.quote.move_up')" @click="moveSection(bi, -1)">▲</button>
                <button type="button" :class="arrowBtnClass" :disabled="bi === blocks.length - 1" :title="t('tri.quote.move_down')" @click="moveSection(bi, 1)">▼</button>
              </div>
              <input
                v-model="block.title"
                :class="[inputGhostClass, 'flex-1 h-10 !text-[17px] font-semibold text-neutral-700']"
              />
              <button
                type="button"
                :class="[adjBtnClass, rowHoverActionClass, isPanelOpen(block.tempId) && adjBtnActiveClass]"
                :title="t('tri.quote.adjustments_toggle')"
                @click="togglePanel(block.tempId)"
              >%</button>
              <button type="button" :class="[delBtnClass, rowHoverActionClass]" :title="t('tri.quote.delete_section')" @click="deleteSection(bi)">×</button>
            </div>
            <div v-if="isPanelOpen(block.tempId)" class="px-3 pb-2">
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

          <!-- Section item rows -->
          <div
            v-for="(row, ii) in block.items"
            :key="row._uid"
            class="border-b border-neutral-100 border-l-[3px] border-l-primary-200 bg-primary-50/20"
          >
            <div :class="[gridCols, 'group hover:bg-primary-50/40 px-3 py-2']">
              <div :class="[arrowCellClass, rowHoverActionClass]">
                <button type="button" :class="arrowBtnClass" :title="t('tri.quote.move_up')" @click="moveSectionItem(block, ii, -1)">▲</button>
                <button type="button" :class="arrowBtnClass" :title="t('tri.quote.move_down')" @click="moveSectionItem(block, ii, 1)">▼</button>
              </div>
              <input v-model="row.designation" :class="[inputGhostClass, 'w-full']" />
              <textarea v-model="row.title" v-autosize rows="1" :class="[titleAreaClass, 'w-full']"></textarea>
              <input v-model.number="row.quantity" type="number" step="0.001" :class="[inputGhostClass, noSpinClass, 'w-full']" />
              <input v-model.number="row.base_unit_price" type="number" step="0.01" :class="[inputGhostClass, noSpinClass, inputRightClass, 'w-full']" />
              <select v-model.number="row.vat_rate" :class="[inputGhostClass, inputRightClass, 'w-full']">
                <option :value="21">21</option>
                <option :value="12">12</option>
                <option :value="0">0</option>
              </select>
            <div :class="lineTotalClass">
              <span>{{ formatLineAmount(lineOffer(row)) }}</span>
              <template v-if="!isPanelOpen(row._uid) && lineOwnDiscount(row) > 0">
                <span :class="[lineTotalSubClass, 'text-danger-600']">−{{ formatLineAmount(lineOwnDiscount(row)) }}</span>
                <span :class="[lineTotalSubClass, 'text-neutral-700']">{{ formatLineAmount(lineAfter(row)) }}</span>
              </template>
            </div>
              <div :class="['flex items-center justify-center gap-0.5', rowHoverActionClass]">
                <button
                  type="button"
                  :class="[adjBtnClass, isPanelOpen(row._uid) && adjBtnActiveClass]"
                  :title="t('tri.quote.adjustments_toggle')"
                  @click="togglePanel(row._uid)"
                >%</button>
                <button type="button" :class="delBtnClass" :title="t('common.delete')" @click="deleteSectionItem(block, ii)">×</button>
              </div>
            </div>
            <div v-if="isPanelOpen(row._uid)" :class="gridCols">
              <QuoteAdjustmentPanel
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
            </div>
          </div>

          <!-- Section footer: add line + subtotal (stejný grid jako řádky → částka zarovnaná doprava) -->
          <div :class="[gridCols, 'bg-neutral-50/60 border-b border-neutral-200 border-l-[3px] border-l-primary-400 px-3 py-2']">
            <span></span>
            <button type="button" class="col-span-5 cursor-pointer text-xs text-primary-600 hover:text-primary-700 text-left" @click="addLineToSection(block)">
              {{ t('tri.quote.add_line') }}
            </button>
            <div class="flex flex-col items-end text-sm leading-tight">
              <div class="flex items-center justify-end gap-1">
                <span class="text-neutral-600">{{ t('tri.quote.section_total') }}:</span>
                <span class="font-semibold font-mono tabular-nums text-neutral-900">{{ formatLineAmount(sectionOfferTotal(block)) }}</span>
              </div>
              <template v-if="!isPanelOpen(block.tempId) && sectionDiscountAmount(block) > 0">
                <span :class="[lineTotalSubClass, 'text-danger-600 font-mono']">−{{ formatLineAmount(sectionDiscountAmount(block)) }}</span>
                <span :class="[lineTotalSubClass, 'text-neutral-700 font-mono']">{{ formatLineAmount(sectionTotal(block)) }}</span>
              </template>
            </div>
            <span></span>
          </div>
        </template>
      </template>

      <div v-if="lastBlockIsSection" class="bg-neutral-50 h-6" aria-hidden="true"></div>
    </div>

    <div class="flex gap-2">
      <button
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 h-7 px-2.5 text-xs border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md"
        @click="addSection"
      >
        {{ t('tri.quote.add_section') }}
      </button>
      <button
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 h-7 px-2.5 text-xs border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md"
        @click="addLine()"
      >
        {{ t('tri.quote.add_line') }}
      </button>
    </div>

    <!-- Poznámka pod položkami + souhrn cen -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
      <div class="sm:col-span-2 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('tri.quote.note_below') }}</label>
        <textarea v-model="noteBelowItems" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
      </div>

      <!-- Totals -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-3">
        <div class="border-b border-neutral-100 pb-3">
          <button
            type="button"
            class="text-xs text-primary-600 hover:text-primary-700 cursor-pointer"
            @click="togglePanel('variant-adjustments')"
          >
            {{ isPanelOpen('variant-adjustments') ? '▼' : '▶' }}
            {{ t('tri.quote.variant_adjustments') }}
          </button>
          <div v-if="isPanelOpen('variant-adjustments')" class="mt-2">
            <QuoteAdjustmentPanel
              v-model:discount-type="quoteDiscountType"
              v-model:discount-value="quoteDiscountValue"
              v-model:commission-type="quoteCommissionType"
              v-model:commission-value="quoteCommissionValue"
            />
          </div>
        </div>
        <div class="space-y-1.5 text-sm">
        <div class="flex justify-between">
          <span class="text-neutral-500">{{ t('tri.quote.total_base') }}</span>
          <span class="font-mono">{{ formatMoney(previewTotals.subtotal, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.discountTotal > 0" class="flex justify-between text-danger-600">
          <span>{{ t('tri.quote.total_discount') }}</span>
          <span class="font-mono">−{{ formatMoney(previewTotals.discountTotal, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.commissionTotal > 0" class="flex justify-between text-primary-700">
          <span>{{ t('tri.quote.total_commission') }}</span>
          <span class="font-mono">+{{ formatMoney(previewTotals.commissionTotal, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.base21 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_21') }} <span class="text-neutral-400">({{ formatMoney(previewTotals.base21, 'CZK') }})</span></span>
          <span class="font-mono">{{ formatMoney(previewTotals.vat21, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.base12 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_12') }} <span class="text-neutral-400">({{ formatMoney(previewTotals.base12, 'CZK') }})</span></span>
          <span class="font-mono">{{ formatMoney(previewTotals.vat12, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.base0 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_0') }} <span class="text-neutral-400">({{ formatMoney(previewTotals.base0, 'CZK') }})</span></span>
          <span class="font-mono">—</span>
        </div>
        <div class="flex justify-between pt-2 mt-1 border-t border-neutral-200 font-semibold text-base">
          <span>{{ t('tri.quote.total_with_vat') }}</span>
          <span class="font-mono">{{ formatMoney(previewTotals.total, 'CZK') }}</span>
        </div>
        </div>
      </div>
    </div>

    <!-- Action bar -->
    <div class="bg-surface border border-neutral-200 rounded-lg p-4 flex justify-between items-center shadow-sm">
      <RouterLink
        :to="{ name: 'tri-job-detail', params: { id: jobId } }"
        class="px-4 py-2 text-sm text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100 rounded-lg transition-colors"
      >
        {{ t('common.back') }}
      </RouterLink>
      <button
        type="button"
        class="cursor-pointer inline-flex items-center gap-1.5 h-10 px-5 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md"
        :disabled="saving"
        @click="save"
      >
        {{ saving ? t('common.saving') : t('common.save') }}
      </button>
    </div>
  </div>
</template>

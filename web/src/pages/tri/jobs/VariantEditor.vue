<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, RouterLink, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriQuoteVariant, type TriQuoteLineItem, type TriQuoteImage, type TriJob, type TriVariantStatus } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
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
import VariantItemsNotionTable from './VariantItemsNotionTable.vue'
import QuoteLineImageControl from './QuoteLineImageControl.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'

const { t, locale } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

type TableUi = 'classic' | 'notion'
const TABLE_UI_KEY = 'tri.variantEditor.tableUi'
const tableUi = ref<TableUi>(
  (localStorage.getItem(TABLE_UI_KEY) as TableUi) === 'classic' ? 'classic' : 'notion',
)
function setTableUi(ui: TableUi) {
  tableUi.value = ui
  localStorage.setItem(TABLE_UI_KEY, ui)
}

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
  'h-8 px-2 rounded-md text-sm bg-transparent border border-transparent hover:border-neutral-200 focus:bg-surface shadow-xs outline-none focus-ring'
const titleAreaClass =
  'min-h-8 py-1.5 px-2 rounded-md text-sm bg-transparent border border-transparent hover:border-neutral-200 focus:bg-surface shadow-xs outline-none focus-ring resize-none overflow-hidden whitespace-pre-wrap leading-snug'
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
  }).format(Math.round(value))
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

function resolveLineRow(entry: unknown): Row | null {
  if (!entry || typeof entry !== 'object') return null
  const e = entry as Record<string, unknown>
  if (e.kind === 'item' && e.row && typeof e.row === 'object') return e.row as Row
  if (typeof e._uid === 'string') return entry as Row
  return null
}

/** DnD can briefly insert a raw Row into `blocks` — wrap before pricing/save/render.
 *  Writes to reactive state ONLY when a structural fix is needed, otherwise the
 *  deep watcher below would re-trigger itself forever. */
let normalizing = false
function normalizeBlocksInPlace() {
  if (normalizing) return
  normalizing = true
  try {
    let changed = false
    const next: Block[] = []
    for (const b of blocks.value as unknown[]) {
      if (!b || typeof b !== 'object') {
        changed = true
        continue
      }
      const rec = b as Record<string, unknown>
      if (!('kind' in rec) && typeof rec._uid === 'string') {
        const row = b as Row
        row.quote_section_id = null
        row.section_temp_id = null
        next.push({ kind: 'item', row })
        changed = true
        continue
      }
      if (rec.kind === 'item') {
        const item = b as ItemBlock
        if (!item.row) {
          changed = true
          continue
        }
        next.push(item)
        continue
      }
      if (rec.kind === 'section') {
        const section = b as SectionBlock
        const fixed: Row[] = []
        let sectionChanged = false
        for (const it of section.items as unknown[]) {
          const row = resolveLineRow(it)
          if (!row) {
            sectionChanged = true
            continue
          }
          if (it !== row) sectionChanged = true
          fixed.push(row)
        }
        if (sectionChanged) {
          for (const row of fixed) {
            row.quote_section_id = null
            row.section_temp_id = section.tempId
          }
          section.items = fixed
          changed = true
        }
        next.push(section)
        continue
      }
      changed = true
    }
    if (changed) blocks.value = next
  } finally {
    normalizing = false
  }
}

watch(blocks, () => normalizeBlocksInPlace(), { deep: true, flush: 'pre' })

function buildPricingInput(): { sections: TriPricingSection[]; lines: TriPricingLine[] } {
  const sections: TriPricingSection[] = []
  const lines: TriPricingLine[] = []
  for (const b of blocks.value as unknown[]) {
    if (!b || typeof b !== 'object') continue
    const rec = b as Record<string, unknown>
    if (rec.kind === 'section') {
      const section = b as SectionBlock
      sections.push({
        tempId: section.tempId,
        discount_type: section.discount_type ?? null,
        discount_value: section.discount_value ?? null,
        commission_type: section.commission_type ?? null,
        commission_value: section.commission_value ?? null,
      })
      for (const entry of section.items as unknown[]) {
        const row = resolveLineRow(entry)
        if (row) lines.push(rowToPricingLine(row, section.tempId))
      }
    } else {
      const row = resolveLineRow(b)
      if (row) lines.push(rowToPricingLine(row, null))
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
  for (const b of blocks.value as unknown[]) {
    if (!b || typeof b !== 'object') continue
    const rec = b as Record<string, unknown>
    if (rec.kind === 'section') {
      for (const entry of (b as SectionBlock).items as unknown[]) {
        const row = resolveLineRow(entry)
        if (row) map.set(row._uid, idx++)
      }
    } else {
      const row = resolveLineRow(b)
      if (row) map.set(row._uid, idx++)
    }
  }
  return map
})

function sectionItemsAsRows(section: SectionBlock): Row[] {
  const out: Row[] = []
  for (const entry of section.items as unknown[]) {
    const row = resolveLineRow(entry)
    if (row) out.push(row)
  }
  return out
}

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
  return sectionItemsAsRows(section).reduce((s, r) => s + linePreview(r), 0)
}

function sectionOfferTotal(section: SectionBlock): number {
  return sectionItemsAsRows(section).reduce((s, r) => s + (lineResult(r)?.offer ?? 0), 0)
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
  const { _uid, image, ...rest } = r
  void _uid
  void image
  return rest
}

function setRowImage(row: Row, image: TriQuoteImage) {
  row.image_id = image.id
  row.image = image
}

function removeRowImage(row: Row) {
  row.image_id = null
  row.image = null
}

function serializeState(): string {
  type SerializedBlock =
    | { kind: 'item'; row: ReturnType<typeof stripRow> }
    | {
        kind: 'section'
        tempId: string
        title: string
        discount_type: TriAdjustmentType | null
        discount_value: number | null
        commission_type: TriAdjustmentType | null
        commission_value: number | null
        items: ReturnType<typeof stripRow>[]
      }
  const serializedBlocks = (blocks.value as unknown[]).flatMap((b): SerializedBlock[] => {
    const row = resolveLineRow(b)
    if (row) return [{ kind: 'item' as const, row: stripRow(row) }]
    if (!b || typeof b !== 'object' || (b as Block).kind !== 'section') return []
    const section = b as SectionBlock
    return [
      {
        kind: 'section' as const,
        tempId: section.tempId,
        title: section.title,
        discount_type: section.discount_type ?? null,
        discount_value: section.discount_value ?? null,
        commission_type: section.commission_type ?? null,
        commission_value: section.commission_value ?? null,
        items: (section.items as unknown[]).flatMap((it) => {
          const r = resolveLineRow(it)
          return r ? [stripRow(r)] : []
        }),
      },
    ]
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

const hasLineItems = computed(() =>
  blocks.value.some(
    (b) => b.kind === 'item' || (b.kind === 'section' && b.items.length > 0),
  ),
)

async function downloadPdf() {
  if (!variant.value) return
  if (isDirty.value) {
    await save()
    if (isDirty.value) return
  }
  window.open(triApi.variants.pdfUrl(variantId.value, false), '_blank')
}

async function save() {
  if (!variant.value) return
  normalizeBlocksInPlace()
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
      toast.error(apiErrorMessage(e, t('common.error')))
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
  return `${base} bg-surface shadow-xs font-semibold ${map[status]}`
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
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
  <div v-else-if="variant" class="max-w-5xl space-y-6">
    <RouterLink
      :to="{ name: 'tri-job-detail', params: { id: jobId } }"
      class="text-sm text-neutral-500 hover:text-neutral-900"
    >
      ← {{ t('tri.jobs.detail') }}
    </RouterLink>

    <!-- Záhlaví varianty -->
    <UiCard>
      <div class="flex flex-col gap-4 p-5 border-b border-neutral-200 md:flex-row md:items-start md:justify-between">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.quote.header_job') }}</p>
          <p class="mt-1 text-3xl font-bold font-mono tracking-tight text-neutral-900">{{ variant.job_number }}</p>
          <h1 class="mt-1 text-lg font-medium text-neutral-600 break-words">{{ variant.job_title }}</h1>
        </div>

        <div class="flex flex-wrap items-start gap-5 shrink-0">
          <div>
            <span class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.variant_label') }}</span>
            <div class="flex h-10 min-w-10 items-center justify-center rounded-md bg-primary-600 px-3 shadow-xs">
              <span class="text-xl font-bold leading-none text-white">{{ variant.variant_code }}</span>
            </div>
          </div>

          <div>
            <span class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.status_label') }}</span>
            <div
              v-if="auth.canWrite"
              class="inline-flex h-10 items-center gap-0.5 rounded-md bg-neutral-100 p-1"
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

          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ t('tri.quote.created_date') }}</label>
            <input
              v-model="variant.job_date"
              type="date"
              class="h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface shadow-xs outline-none focus-ring"
            />
          </div>

          <div v-if="hasLineItems">
            <span class="block text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1.5 invisible select-none" aria-hidden="true">&nbsp;</span>
            <UiButton type="button" variant="outline" size="sm" :disabled="saving" :loading="saving" @click="downloadPdf">
              {{ t('invoice.download_pdf') }}
            </UiButton>
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
    </UiCard>

    <!-- Poznámka nad položkami -->
    <UiCard padding>
      <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('tri.quote.note_above') }}</label>
      <textarea v-model="noteAboveItems" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring"></textarea>
    </UiCard>

    <!-- Table UI toggle -->
    <div class="flex items-center justify-end">
      <div class="inline-flex h-8 items-center rounded-md border border-neutral-200 bg-surface p-0.5 text-xs">
        <button
          type="button"
          :class="[
            'cursor-pointer h-7 px-2.5 rounded transition-colors',
            tableUi === 'classic' ? 'bg-neutral-100 text-neutral-900 font-medium' : 'text-neutral-500 hover:text-neutral-800',
          ]"
          @click="setTableUi('classic')"
        >
          {{ t('tri.quote.table_ui_classic') }}
        </button>
        <button
          type="button"
          :class="[
            'cursor-pointer h-7 px-2.5 rounded transition-colors',
            tableUi === 'notion' ? 'bg-neutral-100 text-neutral-900 font-medium' : 'text-neutral-500 hover:text-neutral-800',
          ]"
          @click="setTableUi('notion')"
        >
          {{ t('tri.quote.table_ui_notion') }}
        </button>
      </div>
    </div>

    <!-- Notion-like items table -->
    <VariantItemsNotionTable
      v-if="tableUi === 'notion'"
      v-model:blocks="blocks"
      :line-offer="lineOffer"
      :line-after="lineAfter"
      :line-own-discount="lineOwnDiscount"
      :line-result="lineResult"
      :section-total="sectionTotal"
      :section-offer-total="sectionOfferTotal"
      :section-discount-amount="sectionDiscountAmount"
      :is-line-commission-overridden="isLineCommissionOverridden"
      :has-quote-commission="hasQuoteCommission"
      :format-line-amount="formatLineAmount"
      :variant-id="variantId"
      :can-write="auth.canWrite"
      @add-line="addLine"
      @add-line-to-section="addLineToSection"
      @add-section="addSection"
      @delete-standalone="deleteStandalone"
      @delete-section-item="(section, ii) => deleteSectionItem(section, ii)"
      @delete-section="deleteSection"
    />

    <!-- Classic items / sections table -->
    <template v-else>
      <div
        v-if="blocks.length"
        class="bg-surface border border-neutral-200 rounded-lg shadow-xs overflow-hidden"
      >
        <!-- Column header -->
        <div
          :class="[gridCols, 'bg-neutral-50 border-b border-neutral-200 px-3 py-2.5 text-[12px] uppercase tracking-wide text-neutral-500']"
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
              <div class="flex min-w-0 items-start">
                <QuoteLineImageControl
                  :variant-id="variantId"
                  :image="block.row.image ?? null"
                  :disabled="!auth.canWrite"
                  @uploaded="setRowImage(block.row, $event)"
                  @removed="removeRowImage(block.row)"
                />
                <textarea v-model="block.row.title" v-autosize rows="1" :class="[titleAreaClass, 'min-w-0 flex-1']"></textarea>
              </div>
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
                <div class="flex min-w-0 items-start">
                  <QuoteLineImageControl
                    :variant-id="variantId"
                    :image="row.image ?? null"
                    :disabled="!auth.canWrite"
                    @uploaded="setRowImage(row, $event)"
                    @removed="removeRowImage(row)"
                  />
                  <textarea v-model="row.title" v-autosize rows="1" :class="[titleAreaClass, 'min-w-0 flex-1']"></textarea>
                </div>
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

            <!-- Section footer: add line + subtotal -->
            <div :class="[gridCols, 'bg-neutral-50/60 border-b border-neutral-200 border-l-[3px] border-l-primary-400 px-3 py-2']">
              <span></span>
              <button type="button" class="col-span-5 cursor-pointer text-xs text-primary-600 hover:text-primary-700 text-left" @click="addLineToSection(block)">
                + {{ t('tri.quote.add_line_to_section') }}
              </button>
              <div class="flex flex-col items-end text-sm leading-tight">
                <div class="flex items-center justify-end gap-1">
                  <span class="text-neutral-600">{{ t('tri.quote.section_total_named', { name: block.title || t('tri.quote.sections') }) }}:</span>
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
        <UiButton type="button" variant="outline" size="sm" @click="addSection">
          {{ t('tri.quote.add_section') }}
        </UiButton>
        <UiButton type="button" variant="outline" size="sm" @click="addLine()">
          {{ t('tri.quote.add_line') }}
        </UiButton>
      </div>
    </template>

    <!-- Poznámka pod položkami + souhrn cen -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
      <UiCard padding class="sm:col-span-2">
        <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('tri.quote.note_below') }}</label>
        <textarea v-model="noteBelowItems" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring"></textarea>
      </UiCard>

      <UiCard padding>
        <div class="border-b border-neutral-100 pb-3">
          <button
            type="button"
            class="text-xs text-primary-700 hover:text-primary-800 cursor-pointer font-medium"
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
          <span class="font-mono tabular-nums">{{ formatMoney(Math.round(previewTotals.subtotal), 'CZK', 0) }}</span>
        </div>
        <div v-if="previewTotals.discountTotal > 0" class="flex justify-between text-danger-600">
          <span>{{ t('tri.quote.total_discount') }}</span>
          <span class="font-mono tabular-nums">−{{ formatMoney(Math.round(previewTotals.discountTotal), 'CZK', 0) }}</span>
        </div>
        <div v-if="previewTotals.commissionTotal > 0" class="flex justify-between text-primary-700">
          <span>{{ t('tri.quote.total_commission') }}</span>
          <span class="font-mono tabular-nums">+{{ formatMoney(Math.round(previewTotals.commissionTotal), 'CZK', 0) }}</span>
        </div>
        <div v-if="previewTotals.base21 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_21') }} <span class="text-neutral-400">({{ formatMoney(Math.round(previewTotals.base21), 'CZK', 0) }})</span></span>
          <span class="font-mono tabular-nums">{{ formatMoney(Math.round(previewTotals.vat21), 'CZK', 0) }}</span>
        </div>
        <div v-if="previewTotals.base12 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_12') }} <span class="text-neutral-400">({{ formatMoney(Math.round(previewTotals.base12), 'CZK', 0) }})</span></span>
          <span class="font-mono tabular-nums">{{ formatMoney(Math.round(previewTotals.vat12), 'CZK', 0) }}</span>
        </div>
        <div v-if="previewTotals.base0 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_0') }} <span class="text-neutral-400">({{ formatMoney(Math.round(previewTotals.base0), 'CZK', 0) }})</span></span>
          <span class="font-mono tabular-nums">—</span>
        </div>
        <div class="flex justify-between pt-2 mt-1 border-t border-neutral-200 font-semibold text-base">
          <span>{{ t('tri.quote.total_with_vat') }}</span>
          <span class="font-mono tabular-nums">{{ formatMoney(Math.round(previewTotals.total), 'CZK', 0) }}</span>
        </div>
        </div>
      </UiCard>
    </div>

    <UiCard>
      <div class="p-4 flex justify-between items-center">
        <UiButton
          :to="{ name: 'tri-job-detail', params: { id: jobId } }"
          variant="ghost"
        >
          {{ t('common.back') }}
        </UiButton>
        <UiButton type="button" :loading="saving" :disabled="saving" @click="save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </UiButton>
      </div>
    </UiCard>
  </div>
</template>

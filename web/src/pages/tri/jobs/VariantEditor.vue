<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriQuoteVariant, type TriQuoteLineItem, type TriJob, type TriVariantStatus, type TriPriceListItem } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { calculateVariantPricing, type TriAdjustmentType, type TriPricingLine, type TriPricingSection } from '@/composables/useTriQuotePricing'
import JobHeaderInfo from './JobHeaderInfo.vue'
import QuoteAdjustmentPanel from './QuoteAdjustmentPanel.vue'
import VariantItemsNotionTable from './VariantItemsNotionTable.vue'
import CatalogPickerModal from './CatalogPickerModal.vue'
import QuoteRepriceModal from './QuoteRepriceModal.vue'
import OfficeBreadcrumb from '@/components/tri/OfficeBreadcrumb.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import { unitPriceForTotal } from '@/composables/tri/priceInput'
import UiButton from '@/components/ui/UiButton.vue'
import UiSelect from '@/components/ui/UiSelect.vue'
import UiCard from '@/components/ui/UiCard.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'

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
  row.is_manufactured ??= true
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
    is_manufactured: true,
    catalog_item_id: null,
  })
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
  return sectionItemsAsRows(section).reduce((s, r) => s + (lineResult(r)?.lineTotalBeforeQuote ?? 0), 0)
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

function setLineTotal(row: Row, raw: string) {
  if (raw.trim() === '' || (row.line_discount_type === 'percent' && Number(row.line_discount_value) >= 100)) { toast.error(t('office.invalid_total')); return }
  const previous = row.base_unit_price
  const result = unitPriceForTotal(Number(raw), Number(row.quantity), unit => {
    row.base_unit_price = unit
    return lineAfter(row)
  })
  row.base_unit_price = result ?? previous
  if (result === null) toast.error(t('office.invalid_total'))
}
const undoBlocks = ref<Block[] | null>(null)
function rememberDelete() { undoBlocks.value = JSON.parse(JSON.stringify(blocks.value)) }
function undoDelete() { if (undoBlocks.value) { blocks.value = undoBlocks.value; undoBlocks.value = null } }
async function createLineTraveler(row: Row) {
  if (!job.value || !row.id) return
  if (isDirty.value) { toast.error(t('office.save_before_traveler')); return }
  if (job.value.approved_variant_id !== variantId.value || !['confirmed','completed'].includes(job.value.status)) { toast.error(t('office.traveler_requires_approval')); return }
  try { await triApi.travelers.generate(jobId.value, row.id); toast.success(t('office.traveler_created')) } catch (e) { toast.error(apiErrorMessage(e,t('common.error'))) }
}

// --- Add / delete ---

function addLine() {
  blocks.value.push({ kind: 'item', row: newRow() })
}

function addLineToSection(section: SectionBlock) {
  section.items.push(newRow())
}

const repriceOpen=ref(false)
const catalogLines=computed(()=>blocks.value.flatMap(b=>b.kind==='item'?[b.row]:b.items))
function applyReprice(prices:Map<number,number>,releaseId:number,date:string){
  for(const row of catalogLines.value){if(row.source_product_id&&prices.has(row.source_product_id)){row.base_unit_price=prices.get(row.source_product_id)!;row.source_release_id=releaseId}}
  if(variant.value){variant.value.price_release_id=releaseId;variant.value.price_effective_date=date}
  repriceOpen.value=false
}
const catalogOpen = ref(false)
const catalogTarget = ref<SectionBlock | null>(null)

function openCatalogPicker(section: SectionBlock | null = null) {
  catalogTarget.value = section
  catalogOpen.value = true
}

function snapshotToRow(item: TriPriceListItem): Row {
  return wrapRow({
    catalog_item_id: item.source_release_id ? null : item.id ?? null,
    source_product_id: item.source_product_id,
    source_release_id: item.source_release_id,
    image_id: item.image_id ?? item.image?.id ?? null,
    image: item.image ?? null,
    designation: item.designation,
    title: item.title,
    description: item.description,
    quantity: item.default_quantity > 0 ? item.default_quantity : 1,
    unit: item.unit || 'ks',
    base_unit_price: item.base_unit_price,
    vat_rate: item.vat_rate,
    is_manufactured: true,
  })
}

function insertFromCatalog(items: TriPriceListItem[], releaseId?:number, effectiveDate?:string) {
  if(variant.value && releaseId){variant.value.price_release_id=releaseId;variant.value.price_effective_date=effectiveDate}
  const rows = items.map(snapshotToRow)
  const section = catalogTarget.value
  if (section) {
    section.items.push(...rows)
  } else {
    blocks.value.push(...rows.map((row) => ({ kind: 'item' as const, row })))
  }
  catalogTarget.value = null
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
  rememberDelete()
  blocks.value.splice(bi, 1)
}

function deleteSectionItem(section: SectionBlock, ii: number) {
  if (!confirm(t('tri.quote.delete_line_confirm'))) return
  rememberDelete()
  section.items.splice(ii, 1)
}

function deleteSection(bi: number) {
  const b = blocks.value[bi]
  if (b.kind !== 'section') return
  if (!confirm(t('tri.quote.delete_section_confirm'))) return
  rememberDelete()
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
    priceRelease: variant.value?.price_release_id ?? null,
    priceDate: variant.value?.price_effective_date ?? null,
    name: variant.value?.name ?? null,
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
  if(catalogLines.value.some(row=>!Number.isFinite(Number(row.quantity))||Number(row.quantity)<=0||!Number.isFinite(Number(row.base_unit_price))||Number(row.base_unit_price)<0)){toast.error(t('office.invalid_total'));return}
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
      price_release_id: variant.value.price_release_id,
      price_effective_date: variant.value.price_effective_date,
      job_date: variant.value.job_date,
      valid_until: variant.value.valid_until,
      name: variant.value.name?.trim() || null,
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
  <CardSkeleton v-if="loading" :blocks="3" />
  <div v-else-if="variant" class="space-y-6">
    <div>
      <OfficeBreadcrumb :items="[{label:t('tri.jobs.title'),to:'/tri/jobs'},{label:variant.job_number,to:{name:'tri-job-detail',params:{id:jobId}}},{label:t('tri.quote.variant_label')+' '+variant.variant_code}]" />
      <UiPageHeader :title="t('tri.quote.variant_label')+' '+variant.variant_code" :subtitle="t('tri.quote.header_job')+' '+variant.job_number"><template #actions><UiButton v-if="hasLineItems" variant="secondary" :loading="saving" @click="downloadPdf">{{ t('invoice.download_pdf') }}</UiButton></template></UiPageHeader>
    </div>
    <UiCard padding><h2 class="mb-4">{{ t('office.basic_info') }}</h2><div class="grid sm:grid-cols-[2fr_1fr_1fr] gap-5">
      <label class="grid gap-1">{{ t('tri.quote.variant_name') }}<input v-model="variant.name" :disabled="!auth.canWrite" maxlength="120" :placeholder="t('tri.quote.variant_name_placeholder')" class="w-full border px-3 bg-surface" /></label>
      <UiSelect :label="t('tri.quote.status_label')" :model-value="variant.status" :disabled="!auth.canWrite || statusUpdating" @update:model-value="changeVariantStatus(String($event ?? '') as TriVariantStatus)"><option v-for="status in variantStatusOptions" :key="status" :value="status">{{ t(`tri.quote.status_${status}`) }}</option></UiSelect>
      <label class="grid gap-1">{{ t('tri.quote.created_date') }}<input v-model="variant.job_date" :disabled="!auth.canWrite" type="date" class="border px-3 bg-surface" /></label>
    </div></UiCard>
    <UiCard><JobHeaderInfo v-if="job" :contacts="job.contacts ?? []" :site-street="job.site_street" :site-city="job.site_city" :site-zip="job.site_zip" /></UiCard>
    <div v-if="undoBlocks" class="flex justify-between items-center p-3 bg-primary-50"><span>{{ t('office.deleted_undo') }}</span><UiButton variant="secondary" @click="undoDelete">{{ t('office.undo') }}</UiButton></div>
    <!-- Items table -->
    <div v-if="variant.price_release_id" class="flex justify-between items-center border border-neutral-200 p-4 text-sm"><span>{{t('office.release')}} #{{variant.price_release_id}} · {{variant.price_effective_date}}</span><UiButton v-if="auth.canWrite" variant="secondary" @click="repriceOpen=true">{{t('office.reprice')}}</UiButton></div>
    <VariantItemsNotionTable
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
      @set-total="setLineTotal"
      @traveler="createLineTraveler"
      @add-line="addLine"
      @add-line-to-section="addLineToSection"
      @insert-from-catalog="openCatalogPicker()"
      @insert-from-catalog-section="openCatalogPicker"
      @add-section="addSection"
      @delete-standalone="deleteStandalone"
      @delete-section-item="(section, ii) => deleteSectionItem(section, ii)"
      @delete-section="deleteSection"
    />

    <div class="grid grid-cols-1 lg:grid-cols-[3fr_2fr] gap-6 items-start">
      <UiCard padding><h2 class="mb-4">{{ t('office.quote_discount') }}</h2><QuoteAdjustmentPanel v-model:discount-type="quoteDiscountType" v-model:discount-value="quoteDiscountValue" /></UiCard>
      <UiCard padding><h2 class="mb-4">{{ t('office.quote_summary') }}</h2>
        <div class="space-y-1.5 text-sm">
        <div class="flex justify-between">
          <span class="text-neutral-500">{{ t('tri.quote.total_base') }}</span>
          <span class="font-mono tabular-nums">{{ formatMoney(previewTotals.subtotal, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.discountTotal > 0" class="flex justify-between text-danger-600">
          <span>{{ t('tri.quote.total_discount') }}</span>
          <span class="font-mono tabular-nums">−{{ formatMoney(previewTotals.discountTotal, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.base21 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_21') }} <span class="text-neutral-400">({{ formatMoney(previewTotals.base21, 'CZK') }})</span></span>
          <span class="font-mono tabular-nums">{{ formatMoney(previewTotals.vat21, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.base12 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_12') }} <span class="text-neutral-400">({{ formatMoney(previewTotals.base12, 'CZK') }})</span></span>
          <span class="font-mono tabular-nums">{{ formatMoney(previewTotals.vat12, 'CZK') }}</span>
        </div>
        <div v-if="previewTotals.base0 > 0" class="flex justify-between text-neutral-600">
          <span>{{ t('tri.quote.vat_0') }} <span class="text-neutral-400">({{ formatMoney(previewTotals.base0, 'CZK') }})</span></span>
          <span class="font-mono tabular-nums">—</span>
        </div>
        <div class="flex justify-between pt-2 mt-1 border-t border-neutral-200 font-semibold text-base">
          <span>{{ t('tri.quote.total_with_vat') }}</span>
          <span class="font-mono tabular-nums">{{ formatMoney(previewTotals.total, 'CZK') }}</span>
        </div>
        </div>
      </UiCard>
    </div>

    <UiCard padding><label class="block mb-2">{{ t('tri.quote.note_below') }}</label><textarea v-model="noteBelowItems" :disabled="!auth.canWrite" rows="3" class="w-full border p-3 bg-surface" /></UiCard>
    <div class="office-save-bar">
        <UiButton
          :to="{ name: 'tri-job-detail', params: { id: jobId } }"
          variant="ghost"
        >
          {{ t('common.back') }}
        </UiButton>
        <UiButton v-if="auth.canWrite" type="button" :loading="saving" :disabled="saving" @click="save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </UiButton>
    </div>
    <QuoteRepriceModal v-if="repriceOpen&&variant?.price_release_id" :release-id="variant.price_release_id" :lines="catalogLines" @close="repriceOpen=false" @apply="applyReprice"/>
    <CatalogPickerModal v-model:open="catalogOpen" :release-id="variant?.price_release_id" :effective-date="variant?.price_effective_date" @insert="insertFromCatalog" />
  </div>
</template>

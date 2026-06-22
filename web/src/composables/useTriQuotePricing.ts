/**
 * Mirrors backend QuoteCalculator for live preview in VariantEditor.
 */

export type TriAdjustmentType = 'percent' | 'absolute'

export interface TriAdjustment {
  type: TriAdjustmentType
  value: number
}

export interface TriPricingSection {
  tempId?: string
  id?: number
  discount_type?: TriAdjustmentType | null
  discount_value?: number | null
  commission_type?: TriAdjustmentType | null
  commission_value?: number | null
}

export interface TriPricingLine {
  quantity: number
  base_unit_price: number
  vat_rate: number
  markup_type?: TriAdjustmentType | null
  markup_value?: number | null
  line_discount_type?: TriAdjustmentType | null
  line_discount_value?: number | null
  quote_section_id?: number | null
  section_temp_id?: string | null
}

export interface TriPricingVariant {
  quote_discount_type?: TriAdjustmentType | null
  quote_discount_value?: number | null
  quote_commission_type?: TriAdjustmentType | null
  quote_commission_value?: number | null
}

export interface TriLinePricingResult {
  lineBase: number
  offer: number
  markupAmount: number
  afterLineDiscount: number
  lineTotal: number
  lineTotalBeforeQuote: number
}

export interface TriVariantPricingResult {
  lines: TriLinePricingResult[]
  subtotal: number
  base21: number
  base12: number
  base0: number
  vat21: number
  vat12: number
  total: number
  commissionTotal: number
  discountTotal: number
}

function round2(n: number): number {
  return Math.round((n + Number.EPSILON) * 100) / 100
}

function parseAdjustment(
  type?: TriAdjustmentType | null,
  value?: number | null,
): TriAdjustment | null {
  if (!type || value == null || value <= 0) return null
  return { type, value }
}

function applyAdjustment(amount: number, adjustment: TriAdjustment | null): number {
  if (!adjustment) return round2(amount)
  if (adjustment.type === 'percent') {
    return round2(amount * (1 - adjustment.value / 100))
  }
  return round2(Math.max(0, amount - adjustment.value))
}

function applyCommissionGrossup(
  base: number,
  commission: TriAdjustment | null,
  share: number,
): number {
  if (!commission) return round2(base)
  if (commission.type === 'percent') {
    if (commission.value >= 100) return round2(base)
    return round2(base / (1 - commission.value / 100))
  }
  return round2(base + commission.value * share)
}

function buildSectionMap(sections: TriPricingSection[]): Map<string, TriPricingSection> {
  const map = new Map<string, TriPricingSection>()
  for (const section of sections) {
    if (section.id != null) map.set(String(section.id), section)
    if (section.tempId != null) map.set(String(section.tempId), section)
  }
  return map
}

function resolveSection(
  line: TriPricingLine,
  sectionMap: Map<string, TriPricingSection>,
): TriPricingSection | null {
  const ref = line.quote_section_id ?? line.section_temp_id
  if (ref == null) return null
  return sectionMap.get(String(ref)) ?? null
}

export function calculateVariantPricing(
  sections: TriPricingSection[],
  lines: TriPricingLine[],
  variant: TriPricingVariant,
): TriVariantPricingResult {
  const sectionMap = buildSectionMap(sections)
  const quoteCommission = parseAdjustment(
    variant.quote_commission_type,
    variant.quote_commission_value,
  )
  const quoteDiscount = parseAdjustment(
    variant.quote_discount_type,
    variant.quote_discount_value,
  )

  type Row = {
    input: TriPricingLine
    lineBase: number
    section: TriPricingSection | null
    offer: number
    markupAmount: number
    afterLineDiscount: number
    lineTotalBeforeQuote: number
    vatRate: number
  }

  const rows: Row[] = lines.map((line) => ({
    input: line,
    lineBase: round2((Number(line.quantity) || 0) * (Number(line.base_unit_price) || 0)),
    section: resolveSection(line, sectionMap),
    offer: 0,
    markupAmount: 0,
    afterLineDiscount: 0,
    lineTotalBeforeQuote: 0,
    vatRate: Number(line.vat_rate) || 21,
  }))

  const hasQuoteCommission = quoteCommission !== null
  const quoteBases: number[] = []
  const sectionBaseSums = new Map<string, number>()

  rows.forEach((row, idx) => {
    if (hasQuoteCommission && quoteCommission.type === 'absolute') {
      quoteBases[idx] = row.lineBase
    }
    if (
      !hasQuoteCommission &&
      row.section &&
      parseAdjustment(row.section.commission_type, row.section.commission_value)?.type === 'absolute'
    ) {
      const ref = String(row.input.quote_section_id ?? row.input.section_temp_id ?? '')
      sectionBaseSums.set(ref, (sectionBaseSums.get(ref) ?? 0) + row.lineBase)
    }
  })

  const quoteBaseSum = quoteBases.reduce((s, v) => s + v, 0)

  for (const row of rows) {
    const lineCommission = parseAdjustment(row.input.markup_type, row.input.markup_value)
    let offer: number
    if (hasQuoteCommission) {
      const share = quoteBaseSum > 0 ? row.lineBase / quoteBaseSum : 0
      offer = applyCommissionGrossup(row.lineBase, quoteCommission, share)
    } else if (row.section) {
      const sectionCommission = parseAdjustment(
        row.section.commission_type,
        row.section.commission_value,
      )
      if (sectionCommission) {
        const ref = String(row.input.quote_section_id ?? row.input.section_temp_id ?? '')
        const sectionSum = sectionBaseSums.get(ref) ?? row.lineBase
        const share = sectionSum > 0 ? row.lineBase / sectionSum : 0
        offer = applyCommissionGrossup(row.lineBase, sectionCommission, share)
      } else {
        offer = applyCommissionGrossup(row.lineBase, lineCommission, 1)
      }
    } else {
      offer = applyCommissionGrossup(row.lineBase, lineCommission, 1)
    }
    row.offer = offer
    row.markupAmount = round2(offer - row.lineBase)
    row.afterLineDiscount = applyAdjustment(
      offer,
      parseAdjustment(row.input.line_discount_type, row.input.line_discount_value),
    )
  }

  const offerSum = rows.reduce((s, r) => s + r.offer, 0)

  const sectionGroups = new Map<string, number[]>()
  rows.forEach((row, idx) => {
    const ref = row.input.quote_section_id ?? row.input.section_temp_id
    if (ref == null || !row.section) {
      row.lineTotalBeforeQuote = row.afterLineDiscount
      return
    }
    const key = String(ref)
    if (!sectionGroups.has(key)) sectionGroups.set(key, [])
    sectionGroups.get(key)!.push(idx)
  })

  for (const indices of sectionGroups.values()) {
    const section = rows[indices[0]!]!.section!
    const discount = parseAdjustment(section.discount_type, section.discount_value)
    if (!discount) {
      for (const idx of indices) {
        rows[idx]!.lineTotalBeforeQuote = rows[idx]!.afterLineDiscount
      }
      continue
    }

    const amounts = indices.map((idx) => rows[idx]!.afterLineDiscount)
    const sum = round2(amounts.reduce((s, v) => s + v, 0))

    if (discount.type === 'percent') {
      for (let i = 0; i < indices.length; i++) {
        const idx = indices[i]!
        rows[idx]!.lineTotalBeforeQuote = applyAdjustment(amounts[i]!, discount)
      }
    } else {
      let remaining = round2(Math.min(discount.value, sum))
      const lastIdx = indices[indices.length - 1]!
      for (let i = 0; i < indices.length; i++) {
        const idx = indices[i]!
        if (idx === lastIdx) {
          rows[idx]!.lineTotalBeforeQuote = round2(Math.max(0, amounts[i]! - remaining))
          continue
        }
        const share = sum > 0 ? amounts[i]! / sum : 0
        const cut = round2(discount.value * share)
        remaining -= cut
        rows[idx]!.lineTotalBeforeQuote = round2(Math.max(0, amounts[i]! - cut))
      }
    }
  }

  const linesSubtotal = round2(rows.reduce((s, r) => s + r.lineTotalBeforeQuote, 0))
  const subtotal = applyAdjustment(linesSubtotal, quoteDiscount)
  const commissionTotal = round2(rows.reduce((s, r) => s + r.markupAmount, 0))
  const discountTotal = round2(Math.max(0, offerSum - subtotal))

  const ratio = linesSubtotal > 0 ? subtotal / linesSubtotal : 1
  let base21 = 0
  let base12 = 0
  let base0 = 0
  for (const row of rows) {
    const adjusted = round2(row.lineTotalBeforeQuote * ratio)
    if (row.vatRate === 21) base21 += adjusted
    else if (row.vatRate === 12) base12 += adjusted
    else base0 += adjusted
  }
  base21 = round2(base21)
  base12 = round2(base12)
  base0 = round2(base0)
  const vat21 = round2(base21 * 0.21)
  const vat12 = round2(base12 * 0.12)
  const total = round2(base21 + vat21 + base12 + vat12 + base0)

  return {
    lines: rows.map((r) => ({
      lineBase: r.lineBase,
      offer: r.offer,
      markupAmount: r.markupAmount,
      afterLineDiscount: r.afterLineDiscount,
      lineTotal: r.lineTotalBeforeQuote,
      lineTotalBeforeQuote: r.lineTotalBeforeQuote,
    })),
    subtotal,
    base21,
    base12,
    base0,
    vat21,
    vat12,
    total,
    commissionTotal,
    discountTotal,
  }
}

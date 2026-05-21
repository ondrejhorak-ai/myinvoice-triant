import { api } from './client'

export interface DphPriznaniLine {
  base: number
  vat: number
  count: number
  label: string
}

export interface DphPriznaniPreview {
  summary: {
    period: string
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    lines: Record<string, DphPriznaniLine>
    total_vat_output: number
    total_vat_input: number
    tax_due: number
    is_excess_deduction: boolean
    submission_deadline: string
    supplier_vat_period: string
  }
  warnings: string[]
}

export interface DphSettings {
  vat_period: 'monthly' | 'quarterly' | null
  is_vat_payer: boolean
  taxpayer_type: 'fo' | 'po' | null
  has_financial_office: boolean
}

export interface DphTrendRow {
  period: string
  vat_output: number
  vat_input: number
  vat_due: number
}

export const reportsApi = {
  dphSettings: () =>
    api.get<DphSettings>('/reports/dphdp3/settings').then(r => r.data),

  dphPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphPriznaniPreview>('/reports/dphdp3/preview', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  dphTrend: (months = 12) =>
    api.get<DphTrendRow[]>('/reports/dphdp3/trend', { params: { months } }).then(r => r.data),

  /** URL na download endpoint — frontend ho otevírá v novém okně */
  dphDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dphdp3?${params.toString()}`
  },
}

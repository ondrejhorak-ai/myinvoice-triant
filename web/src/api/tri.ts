import { api } from './client'
import type { InvoiceListMeta, MonthGroup } from './invoices'

export interface TriTag {
  id: number
  supplier_id: number
  name: string
  slug: string
  color: string
  created_at?: string
}

export interface TriJobContact {
  client_id: number
  sort_order: number
  company_name: string
  first_name: string | null
  last_name: string | null
  main_email: string | null
  phone: string | null
  ic: string | null
  tags: TriTag[]
}

export interface TriJob {
  id: number
  supplier_id: number
  number: string
  title: string
  status: 'active' | 'confirmed' | 'rejected' | 'completed'
  owner_user_id: number
  owner_name: string
  owner_email?: string
  customer_client_id: number | null
  customer_name: string | null
  customer_email?: string | null
  approved_variant_id: number | null
  site_street: string | null
  site_city: string | null
  site_zip: string | null
  site_country: string
  notes: string | null
  contacts?: TriJobContact[]
  variants?: TriQuoteVariantSummary[]
  initial_variant_id?: number
  created_at?: string
  updated_at?: string
}

export type TriVariantStatus = 'draft' | 'sent' | 'approved'

export interface TriQuoteVariantSummary {
  id: number
  job_id: number
  variant_code: string
  number: string
  status: TriVariantStatus
  subtotal: number
  total_with_vat: number
  job_date: string
}

export interface TriQuoteSection {
  id: number
  sort_order: number
  title: string
  discount_type?: 'percent' | 'absolute' | null
  discount_value?: number | null
  commission_type?: 'percent' | 'absolute' | null
  commission_value?: number | null
}

export interface TriQuoteLineItem {
  id?: number
  quote_section_id?: number | null
  section_temp_id?: string | null
  sort_order?: number
  designation: string
  title: string
  description?: string | null
  quantity: number
  unit: string
  base_unit_price: number
  vat_rate: number
  markup_type?: 'percent' | 'absolute' | null
  markup_value?: number | null
  markup_amount?: number
  line_discount_type?: 'percent' | 'absolute' | null
  line_discount_value?: number | null
  line_total?: number
}

export interface TriQuoteVariant extends TriQuoteVariantSummary {
  job_number: string
  job_title: string
  customer_client_id: number | null
  valid_until: string | null
  quote_discount_type: 'percent' | 'absolute' | null
  quote_discount_value: number | null
  quote_commission_type: 'percent' | 'absolute' | null
  quote_commission_value: number | null
  subtotal: number
  vat_base_21: number
  vat_amount_21: number
  vat_base_12: number
  vat_amount_12: number
  vat_base_0: number
  commission_total: number
  discount_total: number
  version: number
  lock_version: number
  internal_notes: string | null
  note_above_items: string | null
  note_below_items: string | null
  sections: TriQuoteSection[]
  line_items: TriQuoteLineItem[]
}

export interface TriJobInvoiceSummary {
  variant_total_with_vat: number
  variant_subtotal: number
  invoiced_total: number
  paid_advances_total: number
  remaining_to_invoice: number
}

export interface TriJobInvoice {
  id: number
  varsymbol: string | null
  invoice_type: 'invoice' | 'proforma' | 'credit_note' | 'cancellation'
  status: string
  issue_date: string
  due_date: string
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  paid_at: string | null
  client_id: number
  client_name: string
  linked_at: string
}

export interface TriJobLink {
  id: number
  number: string
  title: string
}

export const triApi = {
  tags: {
    list: () => api.get<{ data: TriTag[] }>('/tri/tags').then((r) => r.data.data),
    create: (payload: { name: string; color?: string }) =>
      api.post<TriTag>('/tri/tags', payload).then((r) => r.data),
    update: (id: number, payload: { name: string; color: string }) =>
      api.put<TriTag>(`/tri/tags/${id}`, payload).then((r) => r.data),
    delete: (id: number) => api.delete(`/tri/tags/${id}`),
    forClient: (clientId: number) =>
      api.get<{ tags: TriTag[] }>(`/tri/clients/${clientId}/tags`).then((r) => r.data.tags),
    setClient: (clientId: number, tagIds: number[]) =>
      api.put<{ tags: TriTag[] }>(`/tri/clients/${clientId}/tags`, { tag_ids: tagIds }).then((r) => r.data.tags),
  },
  jobs: {
    list: (params?: Record<string, string | number>) =>
      api.get<{ data: TriJob[]; meta: { total: number; page: number; pages: number } }>('/tri/jobs', { params }).then((r) => r.data),
    get: (id: number) => api.get<TriJob>(`/tri/jobs/${id}`).then((r) => r.data),
    suggestNumber: () =>
      api.get<{ suggested: string; year_suffix: string }>('/tri/jobs/suggest-number').then((r) => r.data),
    checkNumber: (number: string, excludeId?: number) =>
      api.get<{ available: boolean }>('/tri/jobs/check-number', { params: { number, exclude_id: excludeId } }).then((r) => r.data),
    create: (payload: Record<string, unknown>) =>
      api.post<TriJob>('/tri/jobs', payload).then((r) => r.data),
    update: (id: number, payload: Record<string, unknown>) =>
      api.put<TriJob>(`/tri/jobs/${id}`, payload).then((r) => r.data),
    updateStatus: (id: number, status: string) =>
      api.post(`/tri/jobs/${id}/status`, { status }),
    archive: (id: number) => api.post(`/tri/jobs/${id}/archive`),
    delete: (id: number) => api.delete(`/tri/jobs/${id}`),
  },
  variants: {
    get: (id: number) => api.get<TriQuoteVariant>(`/tri/variants/${id}`).then((r) => r.data),
    create: (jobId: number) => api.post<TriQuoteVariant>(`/tri/jobs/${jobId}/variants`).then((r) => r.data),
    save: (id: number, payload: Record<string, unknown>) =>
      api.put<TriQuoteVariant>(`/tri/variants/${id}`, payload).then((r) => r.data),
    duplicate: (id: number) => api.post<TriQuoteVariant>(`/tri/variants/${id}/duplicate`).then((r) => r.data),
    updateStatus: (id: number, status: TriVariantStatus) =>
      api.post<TriQuoteVariant>(`/tri/variants/${id}/status`, { status }).then((r) => r.data),
    approve: (id: number) => api.post<TriQuoteVariant>(`/tri/variants/${id}/approve`).then((r) => r.data),
  },
  jobInvoices: {
    list: (jobId: number) =>
      api.get<{ invoices: TriJobInvoice[]; summary: TriJobInvoiceSummary }>(`/tri/jobs/${jobId}/invoices`).then((r) => r.data),
    createAdvance: (jobId: number, payload?: { percent?: number; amount?: number; text?: string }) =>
      api.post<{ invoice_id: number; edit_url: string }>(`/tri/jobs/${jobId}/invoices/advance`, payload ?? {}).then((r) => r.data),
    createFinal: (jobId: number) =>
      api.post<{ invoice_id: number; edit_url: string }>(`/tri/jobs/${jobId}/invoices/final`).then((r) => r.data),
  },
  invoices: {
    list: (params?: Record<string, string | number>) =>
      api.get<{ data: MonthGroup[]; meta: InvoiceListMeta }>('/tri/invoices', { params }).then((r) => r.data),
    getJob: (invoiceId: number) =>
      api.get<{ job: TriJobLink | null }>(`/tri/invoices/${invoiceId}/job`).then((r) => r.data.job),
    setJob: (invoiceId: number, jobId: number | null) =>
      api.put<{ job: TriJobLink | null }>(`/tri/invoices/${invoiceId}/job`, { job_id: jobId }).then((r) => r.data.job),
  },
}

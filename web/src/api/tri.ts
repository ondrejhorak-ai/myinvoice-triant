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
  street?: string | null
  city?: string | null
  zip?: string | null
  country_iso2?: string | null
  tags: TriTag[]
}

export interface TriJobAssignee {
  user_id: number
  name: string
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
  assignees?: TriJobAssignee[]
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

export interface TriQuoteImage {
  id: number
  url: string
  width_px: number
  height_px: number
  size_bytes: number
}

export interface TriQuoteLineItem {
  id?: number
  image_id?: number | null
  image?: TriQuoteImage | null
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
  catalog_item_id?: number | null
  is_manufactured?: boolean
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

export interface TriJobActivityItem {
  id: number
  job_id: number
  user_id: number | null
  user_name: string | null
  kind: 'comment' | 'event'
  event_type: string | null
  body: string | null
  payload: Record<string, unknown> | null
  created_at: string
  updated_at: string | null
}

export interface TriJobActivityPage {
  data: TriJobActivityItem[]
  has_more: boolean
}

export interface TriPriceListItem {
  id?: number
  price_list_id?: number
  sort_order?: number
  image_id?: number | null
  image?: TriQuoteImage | null
  designation: string
  title: string
  description?: string | null
  default_quantity: number
  unit: string
  base_unit_price: number
  vat_rate: number
  price_updated_at?: string | null
  price_list_name?: string | null
}

export interface TriPriceList {
  id: number
  supplier_id: number
  name: string
  note: string | null
  item_count?: number
  items?: TriPriceListItem[]
  created_at?: string
  updated_at?: string
}

export type TriTravelerStatus = 'open' | 'done'
export type TriTravelerStation =
  | 'konstrukce'
  | 'narezove_centrum'
  | 'cnc'
  | 'olepovacka'
  | 'dyhovani_brouseni'
  | 'montaz'
  | 'lakovna'
  | 'brouseni'
  | 'baleni'

export interface TriTravelerOperation {
  id: number
  station: TriTravelerStation
  hours: number | null
  note: string | null
}

export interface TriTraveler {
  id: number
  job_id: number
  job_number: string
  job_title: string
  job_status?: string
  quote_line_item_id: number | null
  number: string
  designation: string
  title: string
  description: string | null
  quantity: number
  unit: string
  status: TriTravelerStatus
  hours_total?: number
  operations?: TriTravelerOperation[]
  created_at?: string
  updated_at?: string
}

export interface TriTravelerHoursSummary {
  total: number
  by_station: Array<{ station: TriTravelerStation; hours: number }>
}

export type TriCalendarKind = 'shifts' | 'dispatch' | 'production'
export type TriCalendarStation = 'konstrukce' | 'vyroba' | 'kompletace' | 'lakovna' | 'expedice' | 'montaz'
export type TriCalendarStatus = 'planned' | 'confirmed' | 'in_progress' | 'done'

export interface TriCalendarEvent {
  id: number
  calendar: TriCalendarKind
  job_id: number | null
  job_number: string | null
  job_title: string | null
  title: string
  station: TriCalendarStation | null
  starts_at: string
  ends_at: string | null
  all_day: boolean
  status: TriCalendarStatus
  note: string | null
}

export type TriComplaintStatus = 'open' | 'closed'

export interface TriComplaintComment {
  id: number
  complaint_id: number
  user_id: number | null
  user_name: string | null
  body: string
  created_at: string
  updated_at: string | null
}

export interface TriComplaint {
  id: number
  job_id: number
  job_number: string
  job_title: string
  title: string
  description: string | null
  status: TriComplaintStatus
  created_at: string
  closed_at: string | null
  updated_at: string
  comments?: TriComplaintComment[]
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
    listUsers: () => api.get<{ data: Array<{ id: number; name: string }> }>('/tri/users').then((r) => r.data.data),
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
    uploadImage: (id: number, file: File) => {
      const data = new FormData()
      data.append('file', file)
      return api.post<TriQuoteImage>(`/tri/variants/${id}/images`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then((r) => r.data)
    },
    pdfUrl: (id: number, download: boolean = false) => {
      const sid = localStorage.getItem('myinvoice.current_supplier_id')
      const params = new URLSearchParams()
      if (download) params.set('download', '1')
      if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
      const qs = params.toString()
      return `/api/tri/variants/${id}/pdf${qs ? '?' + qs : ''}`
    },
  },
  activity: {
    list: (jobId: number, params?: { before_id?: number; after_id?: number; limit?: number }) =>
      api.get<TriJobActivityPage>(`/tri/jobs/${jobId}/activity`, { params }).then((r) => r.data),
    create: (jobId: number, body: string) =>
      api.post<TriJobActivityItem>(`/tri/jobs/${jobId}/activity`, { body }).then((r) => r.data),
    update: (id: number, body: string) =>
      api.put<TriJobActivityItem>(`/tri/activity/${id}`, { body }).then((r) => r.data),
    remove: (id: number) => api.delete(`/tri/activity/${id}`),
  },
  jobInvoices: {
    list: (jobId: number) =>
      api.get<{ invoices: TriJobInvoice[]; summary: TriJobInvoiceSummary }>(`/tri/jobs/${jobId}/invoices`).then((r) => r.data),
    createAdvance: (jobId: number, payload?: { percent?: number; amount?: number; text?: string }) =>
      api.post<{ invoice_id: number; edit_url: string }>(`/tri/jobs/${jobId}/invoices/advance`, payload ?? {}).then((r) => r.data),
    createFinal: (jobId: number) =>
      api.post<{ invoice_id: number; edit_url: string }>(`/tri/jobs/${jobId}/invoices/final`).then((r) => r.data),
  },
  priceLists: {
    list: (params?: Record<string, string | number>) =>
      api.get<{ data: TriPriceList[] }>('/tri/price-lists', { params }).then((r) => r.data),
    get: (id: number) => api.get<TriPriceList>(`/tri/price-lists/${id}`).then((r) => r.data),
    create: (payload: Record<string, unknown>) =>
      api.post<TriPriceList>('/tri/price-lists', payload).then((r) => r.data),
    update: (id: number, payload: Record<string, unknown>) =>
      api.put<TriPriceList>(`/tri/price-lists/${id}`, payload).then((r) => r.data),
    delete: (id: number) => api.delete(`/tri/price-lists/${id}`),
    uploadImage: (id: number, file: File) => {
      const data = new FormData()
      data.append('file', file)
      return api.post<TriQuoteImage>(`/tri/price-lists/${id}/images`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      }).then((r) => r.data)
    },
    pdfUrl: (id: number, download: boolean = false) => {
      const sid = localStorage.getItem('myinvoice.current_supplier_id')
      const params = new URLSearchParams()
      if (download) params.set('download', '1')
      if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
      const qs = params.toString()
      return `/api/tri/price-lists/${id}/pdf${qs ? '?' + qs : ''}`
    },
    searchItems: (q?: string) =>
      api.get<{ data: TriPriceListItem[] }>('/tri/catalog-items', { params: q ? { q } : undefined }).then((r) => r.data.data),
  },
  travelers: {
    list: (params?: Record<string, string | number>) =>
      api.get<{ data: TriTraveler[] }>('/tri/travelers', { params }).then((r) => r.data),
    listForJob: (jobId: number) =>
      api.get<{ data: TriTraveler[] }>(`/tri/jobs/${jobId}/travelers`).then((r) => r.data),
    get: (id: number) => api.get<TriTraveler>(`/tri/travelers/${id}`).then((r) => r.data),
    saveOperations: (
      id: number,
      payload: {
        operations?: Array<{ station: string; hours: string | number | null; note?: string | null }>
        status?: TriTravelerStatus
      },
    ) => api.put<TriTraveler>(`/tri/travelers/${id}/operations`, payload).then((r) => r.data),
    generate: (jobId: number) =>
      api.post<{ created: number; operations_added: number; data: TriTraveler[] }>(
        `/tri/jobs/${jobId}/travelers/generate`,
      ).then((r) => r.data),
    pdfUrl: (id: number, download: boolean = false) => {
      const sid = localStorage.getItem('myinvoice.current_supplier_id')
      const params = new URLSearchParams()
      if (download) params.set('download', '1')
      if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
      const qs = params.toString()
      return `/api/tri/travelers/${id}/pdf${qs ? '?' + qs : ''}`
    },
    jobPdfUrl: (jobId: number, download: boolean = false) => {
      const sid = localStorage.getItem('myinvoice.current_supplier_id')
      const params = new URLSearchParams()
      if (download) params.set('download', '1')
      if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
      const qs = params.toString()
      return `/api/tri/jobs/${jobId}/travelers/pdf${qs ? '?' + qs : ''}`
    },
  },
  calendar: {
    list: (params: { calendar: TriCalendarKind; from: string; to: string }) =>
      api.get<{ data: TriCalendarEvent[] }>('/tri/calendar', { params }).then((r) => r.data),
    listForJob: (jobId: number) =>
      api.get<{ data: TriCalendarEvent[] }>(`/tri/jobs/${jobId}/calendar`).then((r) => r.data),
    get: (id: number) => api.get<TriCalendarEvent>(`/tri/calendar/${id}`).then((r) => r.data),
    create: (payload: Record<string, unknown>) =>
      api.post<TriCalendarEvent>('/tri/calendar', payload).then((r) => r.data),
    update: (id: number, payload: Record<string, unknown>) =>
      api.put<TriCalendarEvent>(`/tri/calendar/${id}`, payload).then((r) => r.data),
    delete: (id: number) => api.delete(`/tri/calendar/${id}`),
  },
  complaints: {
    list: (params?: Record<string, string | number>) =>
      api.get<{ data: TriComplaint[] }>('/tri/complaints', { params }).then((r) => r.data),
    listForJob: (jobId: number) =>
      api.get<{ data: TriComplaint[] }>(`/tri/jobs/${jobId}/complaints`).then((r) => r.data),
    get: (id: number) => api.get<TriComplaint>(`/tri/complaints/${id}`).then((r) => r.data),
    create: (payload: { job_id: number; title: string; description?: string | null }) =>
      api.post<TriComplaint>('/tri/complaints', payload).then((r) => r.data),
    update: (id: number, payload: { title: string; description?: string | null }) =>
      api.put<TriComplaint>(`/tri/complaints/${id}`, payload).then((r) => r.data),
    updateStatus: (id: number, status: TriComplaintStatus) =>
      api.post<TriComplaint>(`/tri/complaints/${id}/status`, { status }).then((r) => r.data),
    addComment: (id: number, body: string) =>
      api.post<TriComplaintComment>(`/tri/complaints/${id}/comments`, { body }).then((r) => r.data),
    updateComment: (id: number, body: string) =>
      api.put<TriComplaintComment>(`/tri/complaint-comments/${id}`, { body }).then((r) => r.data),
    removeComment: (id: number) => api.delete(`/tri/complaint-comments/${id}`),
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

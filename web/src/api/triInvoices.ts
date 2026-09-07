import { api } from './client'
import type { Invoice, InvoicePayload, MonthGroup } from './invoices'

export interface InvoiceListMeta {
  total: number
  page: number
  per_page: number
  pages: number
}

export interface TriInvoiceMeta {
  vat_rates: Array<{
    id: number
    code: string | null
    name: string | null
    rate_percent: number
    is_active: boolean
    is_default: boolean
    is_reverse_charge: boolean
  }>
  currencies: Array<{
    id: number
    code: string
    name: string | null
    label: string
    symbol: string
    decimals: number
    is_active: boolean
    is_default: boolean
  }>
  units: Array<{ id: number; code: string; name: string | null; label: string }>
  branding_profiles: Array<{ id: number; name: string | null; is_default: boolean }>
  jobs: Array<{ id: number; number: string; title: string; myucto_project_id: number | null }>
}

export const triInvoicesApi = {
  meta: () => api.get<TriInvoiceMeta>('/tri/invoices/meta').then((r) => r.data),
  list: (params?: Record<string, string | number>) =>
    api.get<{ data: MonthGroup[]; meta: InvoiceListMeta }>('/tri/invoices', { params }).then((r) => r.data),
  get: (id: number) => api.get<Invoice>(`/tri/invoices/${id}`).then((r) => r.data),
  create: (payload: InvoicePayload) => api.post<Invoice>('/tri/invoices', payload).then((r) => r.data),
  update: (id: number, payload: InvoicePayload) =>
    api.put<Invoice>(`/tri/invoices/${id}`, payload).then((r) => r.data),
  remove: (id: number) => api.delete(`/tri/invoices/${id}`),
  previewVarsymbol: (type: string, issueDate: string, clientId?: number) =>
    api.get<{ varsymbol?: string; has_template?: boolean }>(
      '/tri/invoices/preview-varsymbol',
      { params: { type, issue_date: issueDate, ...(clientId ? { client_id: clientId } : {}) } },
    ).then((r) => r.data),
  pdfUrl: (id: number, download = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/tri/invoices/${id}/pdf${qs ? `?${qs}` : ''}`
  },
  issue: (id: number) => api.post<Invoice>(`/tri/invoices/${id}/issue`).then((r) => r.data),
  /** Daňový doklad ze zaplacené zálohy — vrací NOVÝ doklad (jiné id). */
  issueFinal: (id: number) => api.post<Invoice>(`/tri/invoices/${id}/issue-final`).then((r) => r.data),
  recipients: (id: number) =>
    api.get<{ to?: string[]; cc?: string[]; data?: { to?: string[] } }>(`/tri/invoices/${id}/recipients`).then((r) => r.data),
  send: (id: number, payload: { to?: string[]; cc?: string; bcc?: string; note?: string; subject?: string }) =>
    api.post<Invoice>(`/tri/invoices/${id}/send`, payload).then((r) => r.data),
  reminder: (id: number) => api.post<Invoice>(`/tri/invoices/${id}/reminder`).then((r) => r.data),
  publicLink: (id: number) => api.post<{ url?: string; public_token?: string }>(`/tri/invoices/${id}/public-link`).then((r) => r.data),
  clone: (id: number) => api.post<Invoice>(`/tri/invoices/${id}/clone`).then((r) => r.data),
  markPaid: (id: number, paidAt?: string) =>
    api.post<Invoice>(`/tri/invoices/${id}/mark-paid`, paidAt ? { paid_at: paidAt } : {}).then((r) => r.data),
  unmarkPaid: (id: number) => api.post<Invoice>(`/tri/invoices/${id}/unmark-paid`).then((r) => r.data),
  payments: (id: number) => api.get(`/tri/invoices/${id}/payments`).then((r) => r.data),
  addPayment: (id: number, payload: Record<string, unknown>) =>
    api.post(`/tri/invoices/${id}/payments`, payload).then((r) => r.data),
}

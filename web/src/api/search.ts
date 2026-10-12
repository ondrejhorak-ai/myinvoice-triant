import { api } from './client'

export interface SearchJob {
  id: number
  number: string
  title: string
  contact: string | null
}

export interface SearchClient {
  id: number
  company_name: string
  first_name?: string | null
  last_name?: string | null
  party_kind?: string
  main_email: string | null
  is_customer: boolean
  is_vendor: boolean
}

export interface SearchInvoice {
  id: number
  varsymbol: string | null
  supplier_order_number?: string | null
  invoice_type: string
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
  company_name: string
}

export interface SearchResults {
  q: string
  jobs: SearchJob[]
  clients: SearchClient[]
  invoices: SearchInvoice[]
}

export const searchApi = {
  /** Globální vyhledávání — zakázky, kontakty a faktury podle čísla, názvu a kontaktu. */
  query: (q: string) => api.get<SearchResults>('/search', { params: { q } }).then(r => r.data),
}

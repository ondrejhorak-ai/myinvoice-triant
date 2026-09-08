import { api } from './client'

export interface SearchClient {
  id: number
  company_name: string
  main_email: string | null
  is_customer: boolean
  is_vendor: boolean
}

export interface SearchInvoice {
  id: number
  varsymbol: string | null
  invoice_type: string
  status: string
  issue_date: string | null
  total_with_vat: number
  currency: string
  company_name: string
}

export interface SearchResults {
  q: string
  clients: SearchClient[]
  invoices: SearchInvoice[]
}

export const searchApi = {
  /** Globální vyhledávání — klienti/dodavatelé + vydané faktury (dle čísla dokladu). */
  query: (q: string) => api.get<SearchResults>('/search', { params: { q } }).then(r => r.data),
}

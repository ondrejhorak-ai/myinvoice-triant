import { api } from './client'
import type { TriQuoteImage } from './tri'
export interface CatalogProduct {
  id: number
  code: string
  title: string
  description: string | null
  unit: string
  vat_rate: number
  default_quantity: number
  category: string
  tags_json: string
  parameters: string | null
  image_id: number | null
  image?: TriQuoteImage | null
  lock_version: number
}
export interface Release {
  id: number
  sequence: number
  status: 'draft' | 'published'
  valid_from: string | null
  valid_until: string | null
  lock_version: number
  published_at: string | null
}
export interface ReleaseItem extends CatalogProduct {
  product_id: number
  unit_price: string | number | null
  price_changed_at: string | null
}
export interface ProductPrice {
  price_list_id: number
  name: string
  currency: string
  release_id: number
  sequence: number
  status: string
  valid_from: string | null
  valid_until: string | null
  unit_price: number | null
  price_changed_at: string | null
  author: string | null
}
export interface PriceList {
  current_sequence?: number | null
  valid_until?: string | null
  scheduled_from?: string | null
  archived_at?: string | null
  id: number
  name: string
  note: string | null
  currency: string
  item_count: number
  has_draft: number
  valid_from: string | null
  legacy_item_count: number
  releases: Release[]
  release: Release | null
  items: ReleaseItem[]
}
const base = '/tri/pricing'
export const pricingApi = {
  release: async (id: number) =>
    (await api.get<PriceList>(`${base}/releases/${id}`)).data,
  uploadImage: async (file: File) => {
    const data = new FormData()
    data.append('file', file)
    return (
      await api.post<TriQuoteImage>(`${base}/images`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    ).data
  },
  lists: async (includeArchived = false) =>
    (
      await api.get<{ data: PriceList[] }>(`${base}/lists`, {
        params: { include_archived: includeArchived ? 1 : undefined },
      })
    ).data.data,
  get: async (id: number, release?: number) =>
    (await api.get<PriceList>(`${base}/lists/${id}`, { params: { release } }))
      .data,
  archive: async (id: number, archived = true) =>
    (await api.post<PriceList>(`${base}/lists/${id}/archive`, { archived }))
      .data,
  create: async (data: { name: string; currency: string; note: string }) =>
    (await api.post<PriceList>(`${base}/lists`, data)).data,
  draft: async (id: number) =>
    (await api.post<PriceList>(`${base}/lists/${id}/draft`)).data,
  save: async (list: PriceList) =>
    (
      await api.put<PriceList>(
        `${base}/lists/${list.id}/releases/${list.release!.id}`,
        { ...list.release, items: list.items },
      )
    ).data,
  publish: async (list: PriceList) =>
    (
      await api.post<PriceList>(
        `${base}/lists/${list.id}/releases/${list.release!.id}/publish`,
        { lock_version: list.release!.lock_version },
      )
    ).data,
  catalog: async (params: Record<string, string | number> = {}) =>
    (
      await api.get<{ data: CatalogProduct[]; total: number }>(
        `${base}/products`,
        { params },
      )
    ).data,
  productPrices: async (id: number) =>
    (await api.get<{ data: ProductPrice[] }>(`${base}/products/${id}/prices`))
      .data.data,
  product: async (product: Partial<CatalogProduct> & { tags: string[] }) =>
    (
      await api.request<CatalogProduct>({
        url: `${base}/products${product.id ? '/' + product.id : ''}`,
        method: product.id ? 'put' : 'post',
        data: product,
      })
    ).data,
  importPreview: async (id: number, file: File) => {
    const data = new FormData()
    data.append('file', file)
    return (
      await api.post<{
        rows: {
          code: string
          unit_price: string
          unit: string
          currency: string
          error: string
        }[]
      }>(`${base}/lists/${id}/import-preview`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    ).data
  },
}

import { ref, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'

/** Only the latest filter request may replace rows; failed append never advances the page. */
export function usePagedRows<T>(
  fetchPage: (
    page: number,
  ) => Promise<{ data: T[]; meta: { pages?: number; total: number } }>,
) {
  const { t } = useI18n()
  const items = shallowRef<T[]>([])
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref('')
  const page = ref(0)
  const pages = ref(1)
  const total = ref(0)
  let request = 0
  async function load(reset = true) {
    if (
      !reset &&
      (loading.value || loadingMore.value || page.value >= pages.value)
    )
      return
    if (reset) { items.value = []; page.value = 0; pages.value = 1; total.value = 0 }
    const seq = ++request
    const next = reset ? 1 : page.value + 1
    loading.value = reset
    loadingMore.value = !reset
    error.value = ''
    try {
      const result = await fetchPage(next)
      if (seq !== request) return
      items.value = reset ? result.data : [...items.value, ...result.data]
      page.value = next
      pages.value = result.meta.pages ?? 1
      total.value = result.meta.total
    } catch (e) {
      if (seq === request) error.value = apiErrorMessage(e, t('common.error'))
    } finally {
      if (seq === request) {
        loading.value = false
        loadingMore.value = false
      }
    }
  }
  return { items, loading, loadingMore, error, page, pages, total, load }
}

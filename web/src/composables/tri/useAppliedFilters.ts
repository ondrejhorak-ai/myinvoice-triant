import { computed } from 'vue'

export interface FilterCondition { id: string; label: string }
export interface FilterDefinition {
  id: string
  active: () => boolean
  label: () => string
  clear: () => void
}

/** Definitions describe applied query fields only; drafts, search and sorting stay separate. */
export function useAppliedFilters(fields: FilterDefinition[]) {
  const conditions = computed<FilterCondition[]>(() => fields
    .filter(field => field.active())
    .map(field => ({ id: field.id, label: field.label() })))
  function removeFilter(id: string) { fields.find(field => field.id === id)?.clear() }
  function clearFilters() { fields.forEach(field => field.clear()) }
  return { conditions, removeFilter, clearFilters }
}

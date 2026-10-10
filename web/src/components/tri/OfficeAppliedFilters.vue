<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { FilterCondition } from '@/composables/tri/useAppliedFilters'
const props = withDefaults(defineProps<{ conditions: FilterCondition[]; focusTarget?: string }>(), { focusTarget: 'office-filter-button' })
const emit = defineEmits<{ remove: [id: string]; clear: [] }>()
const { t } = useI18n()
const container = ref<HTMLElement>()
async function remove(id: string, index: number) {
  emit('remove', id)
  await nextTick()
  const buttons = container.value?.querySelectorAll<HTMLButtonElement>('[data-filter-chip]')
  ;(buttons?.[Math.min(index, buttons.length - 1)] ?? document.getElementById(props.focusTarget))?.focus()
}
async function clear() {
  emit('clear')
  await nextTick()
  document.getElementById(props.focusTarget)?.focus()
}
</script>

<template>
  <div v-if="conditions.length" ref="container" class="office-applied-filters flex min-w-0 flex-wrap items-center gap-2 mb-4" :aria-label="t('office.applied_filters')" role="group">
    <button v-for="(condition, index) in conditions" :key="condition.id" type="button" data-filter-chip
      class="office-filter-chip focus-ring flex min-h-8 max-w-full items-center gap-2 rounded-[2px] border border-neutral-200 bg-primary-50 pl-3 pr-2 text-xs text-primary-700"
      :title="condition.label" :aria-label="t('office.remove_filter', { label: condition.label })" @click="remove(condition.id, index)">
      <span class="break-words min-w-0 text-left">{{ condition.label }}</span><span aria-hidden="true" class="shrink-0 text-sm">×</span>
    </button>
    <button type="button" class="office-filter-chip focus-ring min-h-8 px-2 text-xs text-primary-700 underline-offset-2 hover:underline" @click="clear">{{ t('office.clear_filters') }}</button>
  </div>
</template>

<style scoped>
@media (pointer: coarse) { .office-filter-chip { min-height: 44px; } }
</style>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
withDefaults(defineProps<{ count: number; expanded: boolean; id?: string }>(), { id: 'office-filter-button' })
defineEmits<{ click: [] }>()
const { t } = useI18n()
</script>

<template>
  <button :id="id" type="button" class="office-filter-button focus-ring inline-flex h-11 shrink-0 items-center justify-center gap-2 border px-4 text-sm font-semibold text-primary-700"
    :class="count ? 'bg-primary-50 border-primary-600' : 'bg-surface border-neutral-200'"
    :aria-label="count ? t('office.filters_active_label', { count }) : t('office.filters')"
    :aria-expanded="expanded" aria-haspopup="dialog" @click="$emit('click')">
    {{ t('office.filters') }}
    <span v-if="count" aria-hidden="true" class="inline-flex h-6 min-w-6 items-center justify-center rounded-[2px] bg-primary-600 px-1 text-xs text-white">{{ count }}</span>
  </button>
</template>

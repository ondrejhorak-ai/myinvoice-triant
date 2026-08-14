<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import type { TriAdjustmentType } from '@/composables/useTriQuotePricing'

const props = defineProps<{
  discountType?: TriAdjustmentType | '' | null
  discountValue?: number | null
  commissionType?: TriAdjustmentType | '' | null
  commissionValue?: number | null
  commissionOverridden?: boolean
  offerAmount?: number
  discountAmount?: number
  commissionAmount?: number
  finalAmount?: number
}>()

const emit = defineEmits<{
  'update:discountType': [value: TriAdjustmentType | '']
  'update:discountValue': [value: number | null]
  'update:commissionType': [value: TriAdjustmentType | '']
  'update:commissionValue': [value: number | null]
}>()

const { t } = useI18n()

const discountTypeModel = computed({
  get: () => props.discountType || 'percent',
  set: (v: TriAdjustmentType | '') => emit('update:discountType', v || 'percent'),
})
const discountValueModel = computed({
  get: () => props.discountValue,
  set: (v: number | null) => emit('update:discountValue', v),
})
const commissionTypeModel = computed({
  get: () => props.commissionType || 'percent',
  set: (v: TriAdjustmentType | '') => emit('update:commissionType', v || 'percent'),
})
const commissionValueModel = computed({
  get: () => props.commissionValue,
  set: (v: number | null) => emit('update:commissionValue', v),
})

const inputClass =
  'h-7 px-2 rounded-md text-xs bg-surface border border-neutral-300 shadow-xs outline-none focus-ring'
const selectClass = `${inputClass} w-14`
const rowGridClass = 'grid grid-cols-[auto_96px_56px] items-center gap-1.5'
const labelClass =
  'text-[11px] font-semibold uppercase tracking-wider text-neutral-400 text-right pr-1 inline-flex items-center justify-end gap-1'
</script>

<template>
  <div class="col-span-full border-t border-neutral-100 bg-neutral-50 px-3 py-2.5">
    <div class="flex flex-col items-end gap-2">
      <div :class="rowGridClass">
        <span :class="labelClass">
          {{ t('tri.quote.discount') }}
          <span
            class="inline-flex items-center justify-center w-3.5 h-3.5 rounded-full border border-neutral-300 text-[9px] font-bold text-neutral-400 cursor-help leading-none"
            :title="t('tri.quote.discount_help')"
            tabindex="0"
            :aria-label="t('tri.quote.discount_help')"
          >i</span>:
        </span>
        <input
          v-model.number="discountValueModel"
          type="number"
          step="0.01"
          min="0"
          :placeholder="discountTypeModel === 'percent' ? '%' : 'Kč'"
          :class="[inputClass, 'w-full text-right font-mono tabular-nums']"
        />
        <select v-model="discountTypeModel" :class="selectClass">
          <option value="percent">{{ t('tri.quote.adjustment_percent') }}</option>
          <option value="absolute">{{ t('tri.quote.adjustment_amount') }}</option>
        </select>
      </div>

      <div :class="rowGridClass">
        <span :class="labelClass">
          {{ t('tri.quote.commission') }}
          <span
            class="inline-flex items-center justify-center w-3.5 h-3.5 rounded-full border border-neutral-300 text-[9px] font-bold text-neutral-400 cursor-help leading-none"
            :title="t('tri.quote.commission_help')"
            tabindex="0"
            :aria-label="t('tri.quote.commission_help')"
          >i</span>:
        </span>
        <input
          v-model.number="commissionValueModel"
          type="number"
          step="0.01"
          min="0"
          :disabled="commissionOverridden"
          :placeholder="commissionTypeModel === 'percent' ? '%' : 'Kč'"
          :class="[inputClass, 'w-full text-right font-mono tabular-nums']"
        />
        <select
          v-model="commissionTypeModel"
          :class="selectClass"
          :disabled="commissionOverridden"
        >
          <option value="percent">{{ t('tri.quote.adjustment_percent') }}</option>
          <option value="absolute">{{ t('tri.quote.adjustment_amount') }}</option>
        </select>
      </div>

      <p v-if="commissionOverridden" class="text-[11px] text-neutral-500 text-right max-w-xs">
        {{ t('tri.quote.commission_overridden') }}
      </p>

      <div
        v-if="finalAmount != null"
        class="flex flex-col items-end gap-0.5 text-xs text-neutral-600 pt-1 border-t border-neutral-200/80 w-full max-w-[220px]"
      >
        <span v-if="commissionAmount != null && commissionAmount > 0">
          {{ t('tri.quote.commission_amount') }}:
          <strong class="font-mono tabular-nums text-primary-700">+{{ formatMoney(Math.round(commissionAmount), 'CZK', 0) }}</strong>
        </span>
        <span v-if="discountAmount != null && discountAmount > 0">
          {{ t('tri.quote.discount_amount') }}:
          <strong class="font-mono tabular-nums text-danger-600">−{{ formatMoney(Math.round(discountAmount), 'CZK', 0) }}</strong>
        </span>
        <span v-if="offerAmount != null && offerAmount !== finalAmount">
          {{ t('tri.quote.after_discount') }}:
          <strong class="font-mono tabular-nums text-neutral-900">{{ formatMoney(Math.round(finalAmount), 'CZK', 0) }}</strong>
        </span>
      </div>
    </div>
  </div>
</template>

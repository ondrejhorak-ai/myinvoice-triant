<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import type { TriPriceListItem, TriQuoteImage } from '@/api/tri'
import QuoteLineImageControl from '@/pages/tri/jobs/QuoteLineImageControl.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { formatDate } from '@/composables/useFormat'

export type PriceListRow = TriPriceListItem & { _uid: string }

const rows = defineModel<PriceListRow[]>('rows', { required: true })

defineProps<{
  canWrite: boolean
  upload: (file: File) => Promise<TriQuoteImage>
}>()

const emit = defineEmits<{
  add: []
}>()

const { t } = useI18n()

const noSpinClass =
  '[appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none'

function setRowImage(row: PriceListRow, image: TriQuoteImage) {
  row.image_id = image.id
  row.image = image
}

function removeRowImage(row: PriceListRow) {
  row.image_id = null
  row.image = null
}

function removeRow(index: number) {
  rows.value.splice(index, 1)
}

function autosize(el: HTMLTextAreaElement) {
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight}px`
}

const autosizeState = new WeakMap<HTMLTextAreaElement, { onInput: () => void; ro: ResizeObserver }>()
const vAutosize = {
  mounted(el: HTMLTextAreaElement) {
    const onInput = () => autosize(el)
    const ro = new ResizeObserver(() => autosize(el))
    el.addEventListener('input', onInput)
    ro.observe(el)
    autosizeState.set(el, { onInput, ro })
    autosize(el)
  },
  updated(el: HTMLTextAreaElement) {
    autosize(el)
  },
  unmounted(el: HTMLTextAreaElement) {
    const state = autosizeState.get(el)
    if (!state) return
    el.removeEventListener('input', state.onInput)
    state.ro.disconnect()
    autosizeState.delete(el)
  },
}
</script>

<template>
  <div class="overflow-x-auto">
    <div class="min-w-[960px] border border-neutral-200 rounded-lg overflow-hidden">
      <div
        class="grid bg-neutral-50 text-[11px] font-semibold uppercase tracking-wider text-neutral-500 border-b border-neutral-200"
        style="grid-template-columns: 28px 96px minmax(180px, 1.4fr) minmax(140px, 1fr) 72px 56px 96px 64px 110px 40px"
      >
        <div />
        <div class="px-2 py-2.5">{{ t('tri.price_lists.designation') }}</div>
        <div class="px-2 py-2.5">{{ t('tri.price_lists.item_title') }}</div>
        <div class="px-2 py-2.5">{{ t('tri.price_lists.description') }}</div>
        <div class="px-2 py-2.5 text-right">{{ t('tri.price_lists.quantity') }}</div>
        <div class="px-2 py-2.5">{{ t('tri.price_lists.unit') }}</div>
        <div class="px-2 py-2.5 text-right">{{ t('tri.price_lists.unit_price') }}</div>
        <div class="px-2 py-2.5 text-right">{{ t('tri.price_lists.vat_rate') }}</div>
        <div class="px-2 py-2.5">{{ t('tri.price_lists.price_updated') }}</div>
        <div />
      </div>

      <div v-if="!rows.length" class="px-4 py-8 text-center text-sm text-neutral-400">
        {{ t('tri.price_lists.add_item') }}
      </div>

      <draggable
        v-else
        v-model="rows"
        item-key="_uid"
        handle=".drag-handle"
        :animation="150"
        ghost-class="opacity-50"
        class="divide-y divide-neutral-200"
      >
        <template #item="{ element: row, index }">
          <div
            class="group/row grid items-stretch bg-surface"
            style="grid-template-columns: 28px 96px minmax(180px, 1.4fr) minmax(140px, 1fr) 72px 56px 96px 64px 110px 40px"
          >
            <div
              class="drag-handle cursor-grab active:cursor-grabbing flex items-center justify-center border-r border-neutral-200 text-neutral-300 group-hover/row:text-neutral-500"
              :title="t('tri.price_lists.drag_handle')"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <circle cx="5" cy="4" r="1.2" /><circle cx="11" cy="4" r="1.2" />
                <circle cx="5" cy="8" r="1.2" /><circle cx="11" cy="8" r="1.2" />
                <circle cx="5" cy="12" r="1.2" /><circle cx="11" cy="12" r="1.2" />
              </svg>
            </div>
            <div class="border-r border-neutral-200">
              <input
                v-model="row.designation"
                :disabled="!canWrite"
                class="w-full h-full min-h-9 px-2 text-sm bg-transparent outline-none focus-ring"
              />
            </div>
            <div class="flex min-w-0 border-r border-neutral-200">
              <QuoteLineImageControl
                :image="row.image ?? null"
                :disabled="!canWrite"
                :upload="upload"
                @uploaded="setRowImage(row, $event)"
                @removed="removeRowImage(row)"
              />
              <textarea
                v-model="row.title"
                v-autosize
                rows="1"
                :disabled="!canWrite"
                class="min-w-0 flex-1 min-h-9 py-2 px-2 text-sm bg-transparent outline-none focus-ring resize-none overflow-hidden whitespace-pre-wrap leading-snug"
              />
            </div>
            <div class="border-r border-neutral-200">
              <textarea
                v-model="row.description"
                v-autosize
                rows="1"
                :disabled="!canWrite"
                class="w-full min-h-9 py-2 px-2 text-sm bg-transparent outline-none focus-ring resize-none overflow-hidden whitespace-pre-wrap leading-snug"
              />
            </div>
            <div class="border-r border-neutral-200">
              <input
                v-model.number="row.default_quantity"
                type="number"
                step="0.001"
                :disabled="!canWrite"
                :class="[noSpinClass, 'w-full h-full min-h-9 px-2 text-sm font-mono tabular-nums bg-transparent outline-none focus-ring']"
              />
            </div>
            <div class="border-r border-neutral-200">
              <input
                v-model="row.unit"
                :disabled="!canWrite"
                class="w-full h-full min-h-9 px-1 text-sm bg-transparent outline-none focus-ring"
              />
            </div>
            <div class="border-r border-neutral-200">
              <input
                v-model.number="row.base_unit_price"
                type="number"
                step="1"
                :disabled="!canWrite"
                :class="[noSpinClass, 'w-full h-full min-h-9 px-2 text-sm font-mono tabular-nums text-right bg-transparent outline-none focus-ring']"
              />
            </div>
            <div class="border-r border-neutral-200">
              <select
                v-model.number="row.vat_rate"
                :disabled="!canWrite"
                class="w-full h-full min-h-9 px-1 text-sm font-mono tabular-nums text-right bg-transparent outline-none focus-ring"
              >
                <option :value="21">21</option>
                <option :value="12">12</option>
                <option :value="0">0</option>
              </select>
            </div>
            <div class="border-r border-neutral-200 px-2 py-1.5 text-[11px] text-neutral-500 flex items-center">
              {{ row.price_updated_at ? formatDate(row.price_updated_at) : '—' }}
            </div>
            <div class="flex items-center justify-center">
              <button
                v-if="canWrite"
                type="button"
                class="h-8 w-8 text-neutral-400 hover:text-danger-600"
                :title="t('common.delete')"
                @click="removeRow(index)"
              >×</button>
            </div>
          </div>
        </template>
      </draggable>
    </div>

    <div v-if="canWrite" class="mt-3">
      <UiButton variant="secondary" size="sm" @click="emit('add')">
        <template #icon>
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
        </template>
        {{ t('tri.price_lists.add_item') }}
      </UiButton>
    </div>
  </div>
</template>

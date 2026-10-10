<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { pricingApi, type PriceList } from '@/api/triPricing'
import type { TriQuoteLineItem } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiButton from '@/components/ui/UiButton.vue'
const props = defineProps<{ releaseId: number; lines: TriQuoteLineItem[] }>()
const emit = defineEmits<{
  close: []
  apply: [prices: Map<number, number>, releaseId: number, date: string]
}>()
const { t } = useI18n(),
  source = ref<PriceList | null>(null),
  target = ref<PriceList | null>(null),
  date = ref(new Date().toLocaleDateString('en-CA')),
  error = ref(''),
  loading = ref(false)
const diffs = ref<{ code: string; old: number; next: number; pid: number }[]>(
  [],
)
async function preview() {
  loading.value = true
  error.value = ''
  target.value = null
  diffs.value = []
  try {
    source.value = await pricingApi.release(props.releaseId)
    const current = await pricingApi.get(source.value.id)
    const r = current.releases.find(
      (r) =>
        r.status === 'published' &&
        r.valid_from! <= date.value &&
        (!r.valid_until || r.valid_until >= date.value),
    )
    if (!r) throw new Error(t('office.no_effective_release'))
    const next = await pricingApi.get(source.value.id, Number(r.id))
    for (const line of props.lines.filter((l) => l.source_product_id)) {
      const product = next.items.find(
        (i) => Number(i.product_id) === line.source_product_id,
      )
      if (!product || product.unit !== line.unit)
        throw new Error(t('office.reprice_mismatch'))
      diffs.value.push({
        code: line.designation,
        old: Number(line.base_unit_price),
        next: Number(product.unit_price),
        pid: Number(product.product_id),
      })
    }
    target.value = next
  } catch (e) {
    error.value =
      e instanceof Error ? e.message : apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
function apply() {
  if (!target.value?.release) return
  emit(
    'apply',
    new Map(diffs.value.map((d) => [d.pid, d.next])),
    Number(target.value.release.id),
    date.value,
  )
}
onMounted(preview)
</script>
<template>
  <Modal :title="t('office.reprice')" @close="emit('close')"
    ><div class="office-page space-y-4">
      <p>{{ t('office.reprice_hint') }}</p>
      <UiInput
        v-model="date"
        type="date"
        :label="t('office.effective_date')"
        @change="preview"
      />
      <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
      <p v-if="loading">{{ t('common.loading') }}</p>
      <table v-else class="w-full">
        <thead>
          <tr>
            <th>{{ t('office.code') }}</th>
            <th>{{ t('office.old_price') }}</th>
            <th>{{ t('office.new_price') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(d, i) in diffs" :key="i">
            <td>{{ d.code }}</td>
            <td>{{ formatMoney(d.old) }}</td>
            <td
              :class="d.old !== d.next ? 'text-primary-600 font-semibold' : ''"
            >
              {{ formatMoney(d.next) }}
            </td>
          </tr>
        </tbody>
      </table>
      <UiButton
        :disabled="loading || !target || !diffs.length"
        @click="apply"
        >{{ t('office.confirm_reprice') }}</UiButton
      >
    </div></Modal
  >
</template>

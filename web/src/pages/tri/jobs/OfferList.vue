<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { usePagedRows } from '@/composables/tri/usePagedRows'
import { formatMoney, formatDate } from '@/composables/useFormat'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiTable from '@/components/ui/UiTable.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
interface Offer {
  id: number
  job_id: number
  number: string
  name: string | null
  job_title: string
  customer_name: string
  status: string
  total_with_vat: number
  job_date: string
}
const { t } = useI18n(),
  q = ref(''),
  status = ref('')
const { items, loading, error, page, pages, load } = usePagedRows<Offer>(
  async (page) =>
    (
      await api.get('/tri/offers', {
        params: { page, q: q.value, status: status.value },
      })
    ).data,
)
let timer: ReturnType<typeof setTimeout>
watch([q, status], () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(), 250)
})
onBeforeUnmount(() => clearTimeout(timer))
onMounted(() => load())
</script>
<template>
  <div class="space-y-6">
    <UiPageHeader
      :title="t('office.offers')"
      :subtitle="t('office.offers_hint')"
    />
    <div class="flex gap-4 flex-wrap">
      <UiInput
        v-model="q"
        type="search"
        :label="t('common.search')"
        class="w-96"
      /><label class="grid gap-1"
        >{{ t('common.status')
        }}<select
          v-model="status"
          class="border border-neutral-200 px-3 min-w-48"
        >
          <option value="">{{ t('common.all') }}</option>
          <option
            v-for="s in ['draft', 'sent', 'approved', 'rejected']"
            :key="s"
            :value="s"
          >
            {{ t(`tri.quote.status_${s}`) }}
          </option>
        </select></label
      >
    </div>
    <p v-if="error" role="alert">{{ error }}</p>
    <div class="border border-neutral-200 overflow-x-auto">
      <UiTable
        ><template #head
          ><tr>
            <th>{{ t('tri.jobs.number') }}</th>
            <th>{{ t('common.name') }}</th>
            <th>{{ t('tri.jobs.customer') }}</th>
            <th>{{ t('tri.quote.created_date') }}</th>
            <th>{{ t('common.status') }}</th>
            <th>{{ t('tri.quote.total_with_vat') }}</th>
          </tr></template
        >
        <tr v-for="v in items" :key="v.id">
          <td>
            <RouterLink
              class="text-primary-600 font-semibold"
              :to="`/tri/jobs/${v.job_id}/variants/${v.id}`"
              >{{ v.number }}</RouterLink
            >
          </td>
          <td>{{ v.name || v.job_title }}</td>
          <td>{{ v.customer_name || '—' }}</td>
          <td>{{ formatDate(v.job_date) }}</td>
          <td>
            <UiBadge>{{ t(`tri.quote.status_${v.status}`) }}</UiBadge>
          </td>
          <td>{{ formatMoney(v.total_with_vat) }}</td>
        </tr>
        <tr v-if="!items.length">
          <td colspan="6">
            {{ t(loading ? 'common.loading' : 'tri.jobs.no_jobs') }}
          </td>
        </tr></UiTable
      >
    </div>
    <div v-if="page < pages" class="text-center">
      <UiButton variant="secondary" @click="load(false)">{{
        t('office.more')
      }}</UiButton>
    </div>
  </div>
</template>

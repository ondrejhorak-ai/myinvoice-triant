<script setup lang="ts">
import OfficeFilterButton from '@/components/tri/OfficeFilterButton.vue'
import OfficeAppliedFilters from '@/components/tri/OfficeAppliedFilters.vue'
import { useAppliedFilters } from '@/composables/tri/useAppliedFilters'

import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { pricingApi, type PriceList } from '@/api/triPricing'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import OfficeMenu from '@/components/tri/OfficeMenu.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiTable from '@/components/ui/UiTable.vue'
const { t } = useI18n()
const auth = useAuthStore()
const lists = ref<PriceList[]>([]),
  q = ref(''),
  error = ref(''),
  loading = ref(true)
const filterOpen = ref(false),
  currency = ref(''),
  status = ref(''),
  archived = ref(false),
  count = ref(25)
const draft = ref({ currency: '', status: '', archived: false })
function openFilters() { draft.value = { currency: currency.value, status: status.value, archived: archived.value }; filterOpen.value = true }
function applyFilters() { currency.value = draft.value.currency; status.value = draft.value.status; archived.value = draft.value.archived; filterOpen.value = false }
const { conditions, removeFilter, clearFilters } = useAppliedFilters([
  { id: 'currency', active: () => !!currency.value, label: () => `${t('common.currency')}: ${currency.value}`, clear: () => { currency.value = '' } },
  { id: 'status', active: () => !!status.value, label: () => `${t('common.status')}: ${t(status.value === 'draft' ? 'office.draft' : status.value === 'published' ? 'office.published' : 'office.no_release')}`, clear: () => { status.value = '' } },
  { id: 'archive', active: () => !archived.value, label: () => `${t('office.archive_filter')}: ${t('office.not_archived')}`, clear: () => { archived.value = true } },
])
const filtered = computed(() =>
  lists.value.filter(
    (l) =>
      `${l.name} ${l.note ?? ''}`
        .toLocaleLowerCase()
        .includes(q.value.toLocaleLowerCase()) &&
      (!currency.value || l.currency === currency.value) &&
      (!status.value ||
        (status.value === 'draft'
          ? Number(l.has_draft) > 0
          : status.value === 'published'
            ? !!l.valid_from
            : !l.valid_from && !Number(l.has_draft))) &&
      (archived.value || !l.archived_at),
  ),
)
watch([q, currency, status, archived], () => (count.value = 25))
async function archive(l: PriceList) {
  if (
    !confirm(
      t(
        l.archived_at
          ? 'office.restore_list_confirm'
          : 'office.archive_list_confirm',
      ),
    )
  )
    return
  try {
    await pricingApi.archive(l.id, !l.archived_at)
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  }
}
async function load() {
  loading.value = true
  error.value = ''
  try {
    lists.value = await pricingApi.lists(true)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>
<template>
  <div class="space-y-6">
    <UiPageHeader
      :title="t('tri.price_lists.title')"
      :subtitle="t('office.pricing_subtitle')"
      ><template #actions
        ><UiButton v-if="auth.canWrite" to="/tri/price-lists/new"
          >+ {{ t('tri.price_lists.new') }}</UiButton
        ></template
      ></UiPageHeader
    >
    <nav class="office-tabs">
      <RouterLink class="active" to="/tri/price-lists">{{
        t('tri.price_lists.title')
      }}</RouterLink
      ><RouterLink to="/tri/catalog">{{ t('office.catalog') }}</RouterLink>
    </nav>
    <div class="office-filter-toolbar flex flex-wrap items-end gap-4">
      <UiInput
        v-model="q"
        type="search"
        :label="t('common.search')"
        :placeholder="t('office.pricing_search')"
        class="max-w-sm"
      /><OfficeFilterButton :count="conditions.length" :expanded="filterOpen" @click="openFilters" />
    </div>
    <OfficeAppliedFilters :conditions="conditions" @remove="removeFilter" @clear="clearFilters" />
    <p v-if="error" role="alert">
      {{ error }}
      <UiButton variant="secondary" @click="load">{{
        t('office.retry')
      }}</UiButton>
    </p>
    <p v-else-if="loading">{{ t('common.loading') }}</p>
    <div v-else class="border border-neutral-200 overflow-x-auto">
      <UiTable
        ><template #head
          ><tr>
            <th>{{ t('tri.price_lists.name') }}</th>
            <th>{{ t('common.currency') }}</th>
            <th>{{ t('tri.price_lists.item_count') }}</th>
            <th>{{ t('office.release') }}</th>
            <th>{{ t('office.valid_from') }}</th>
            <th>{{ t('office.valid_until') }}</th>
            <th>{{ t('common.status') }}</th>
            <th>{{ t('office.next_release') }}</th>
            <th>
              <span class="sr-only">{{ t('office.actions') }}</span>
            </th>
          </tr></template
        >
        <tr v-for="l in filtered.slice(0, count)" :key="l.id">
          <td>
            <RouterLink
              class="text-primary-600 font-semibold"
              :to="`/tri/price-lists/${l.id}`"
              >{{ l.name }}</RouterLink
            >
            <p class="text-xs text-neutral-500 mt-1">{{ l.note }}</p>
          </td>
          <td>{{ l.currency }}</td>
          <td>{{ l.item_count }}</td>
          <td>{{ l.current_sequence || '—' }}</td>
          <td>{{ l.valid_from || '—' }}</td>
          <td>{{ l.valid_until || '—' }}</td>
          <td>
            <UiBadge>{{
              t(
                l.archived_at
                  ? 'office.archived'
                  : l.valid_from
                    ? 'office.published'
                    : Number(l.has_draft)
                      ? 'office.draft'
                      : 'office.no_release',
              )
            }}</UiBadge>
          </td>
          <td>
            <span v-if="l.scheduled_from"
              >{{ t('office.scheduled') }} · {{ l.scheduled_from }}</span
            ><span v-else-if="Number(l.has_draft)">{{ t('office.draft') }}</span
            ><span v-else>—</span>
          </td>
          <td>
            <OfficeMenu v-if="auth.canWrite"
              ><button @click="archive(l)">
                {{ t(l.archived_at ? 'common.unarchive' : 'office.archive') }}
              </button></OfficeMenu
            >
          </td>
        </tr>
        <tr v-if="!filtered.length">
          <td colspan="9">{{ t('tri.price_lists.no_data') }}</td>
        </tr></UiTable
      >
    </div>
    <div v-if="count < filtered.length" class="text-center">
      <UiButton variant="secondary" @click="count += 25">{{
        t('office.more')
      }}</UiButton>
    </div>
    <Modal
      v-if="filterOpen"
      :title="t('office.filters')"
      @close="filterOpen = false"
      ><div class="office-page space-y-4">
        <label class="grid gap-1"
          >{{ t('common.currency')
          }}<select v-model="draft.currency" class="border px-3">
            <option value="">{{ t('common.all') }}</option>
            <option
              v-for="c in [...new Set(lists.map((l) => l.currency))]"
              :key="c"
            >
              {{ c }}
            </option>
          </select></label
        ><label class="grid gap-1"
          >{{ t('common.status')
          }}<select v-model="draft.status" class="border px-3">
            <option value="">{{ t('common.all') }}</option>
            <option value="draft">{{ t('office.draft') }}</option>
            <option value="published">{{ t('office.published') }}</option>
            <option value="none">{{ t('office.no_release') }}</option>
          </select></label
        ><label class="flex gap-2"
          ><input v-model="draft.archived" type="checkbox" />{{
            t('office.include_archived')
          }}</label
        ><UiButton variant="secondary" @click="clearFilters(); filterOpen=false">{{ t('office.clear_filters') }}</UiButton><UiButton @click="applyFilters">{{
          t('office.apply_filters')
        }}</UiButton>
      </div></Modal
    >
  </div>
</template>

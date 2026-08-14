<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriPriceList } from '@/api/tri'
import { useAuthStore } from '@/stores/auth'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiTable from '@/components/ui/UiTable.vue'
import { useRowLink } from '@/composables/useRowLink'

const { t } = useI18n()
const auth = useAuthStore()
const navigateRow = useRowLink()

const items = ref<TriPriceList[]>([])
const loading = ref(false)
const q = ref('')

async function load() {
  loading.value = true
  try {
    const r = await triApi.priceLists.list({ q: q.value || undefined } as Record<string, string | number>)
    items.value = r.data
  } finally {
    loading.value = false
  }
}

function openList(row: TriPriceList, e?: MouseEvent) {
  navigateRow({ name: 'tri-price-list-edit', params: { id: row.id } }, e)
}

onMounted(() => load())
watch(q, () => load())
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.price_lists.title')">
      <template #actions>
        <UiButton v-if="auth.canWrite" to="/tri/price-lists/new" size="sm">
          <template #icon>
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m6-6H6"/></svg>
          </template>
          {{ t('tri.price_lists.new') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <UiCard>
      <div class="px-4 py-3 border-b border-neutral-200">
        <UiInput
          v-model="q"
          type="search"
          size="sm"
          :placeholder="t('common.search')"
        >
          <template #prefix>
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z"/></svg>
          </template>
        </UiInput>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="4" />

      <EmptyState
        v-else-if="items.length === 0"
        :title="t('tri.price_lists.no_data')"
        :cta="auth.canWrite ? t('tri.price_lists.create_first') : undefined"
        to="/tri/price-lists/new"
      />

      <div v-else class="hidden md:block">
        <UiTable>
          <template #head>
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.price_lists.name') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.price_lists.note') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.price_lists.item_count') }}</th>
              <th class="px-4 py-2.5 w-28"></th>
            </tr>
          </template>
          <tr
            v-for="row in items"
            :key="row.id"
            class="cursor-pointer"
            @click="openList(row, $event)"
            @auxclick.prevent="openList(row, $event)"
          >
            <td class="px-4 py-3 font-medium text-neutral-900">{{ row.name }}</td>
            <td class="px-4 py-3 text-neutral-600 truncate max-w-md">{{ row.note || '—' }}</td>
            <td class="px-4 py-3 text-right tabular-nums text-neutral-600">{{ row.item_count ?? 0 }}</td>
            <td class="px-4 py-3 text-right" @click.stop>
              <UiButton :to="{ name: 'tri-price-list-edit', params: { id: row.id } }" variant="ghost" size="sm">
                {{ t('common.edit') }}
              </UiButton>
            </td>
          </tr>
        </UiTable>
      </div>

      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="row in items"
          :key="`m-${row.id}`"
          class="cursor-pointer hover:bg-neutral-50 px-4 py-3"
          @click="openList(row, $event)"
          @auxclick.prevent="openList(row, $event)"
        >
          <div class="font-medium text-neutral-900 truncate">{{ row.name }}</div>
          <div class="mt-1 text-xs text-neutral-500">
            {{ t('tri.price_lists.item_count') }}: {{ row.item_count ?? 0 }}
          </div>
        </div>
      </div>
    </UiCard>
  </div>
</template>

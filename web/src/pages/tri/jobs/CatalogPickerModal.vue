<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriPriceListItem } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ insert: [items: TriPriceListItem[]] }>()

const { t } = useI18n()
const toast = useToast()
const q = ref('')
const loading = ref(false)
const items = ref<TriPriceListItem[]>([])
const selected = ref<Set<number>>(new Set())

const grouped = computed(() => {
  const map = new Map<string, TriPriceListItem[]>()
  for (const item of items.value) {
    const key = item.price_list_name || t('nav.tri_price_lists')
    const list = map.get(key) ?? []
    list.push(item)
    map.set(key, list)
  }
  return [...map.entries()]
})

async function load() {
  loading.value = true
  try {
    items.value = await triApi.priceLists.searchItems(q.value || undefined)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

function toggle(id: number) {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

function insert() {
  const picked = items.value.filter((item) => item.id != null && selected.value.has(item.id))
  if (!picked.length) return
  emit('insert', picked)
  open.value = false
}

watch(open, (isOpen) => {
  if (!isOpen) return
  selected.value = new Set()
  q.value = ''
  load()
})

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(q, () => {
  if (!open.value) return
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(), 250)
})
</script>

<template>
  <Modal v-if="open" :title="t('tri.quote.insert_from_catalog')" width-class="max-w-3xl" @close="open = false">
    <div class="space-y-4">
      <UiInput v-model="q" type="search" size="sm" :placeholder="t('tri.quote.catalog_search')">
        <template #prefix>
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z"/></svg>
        </template>
      </UiInput>

      <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="!items.length" class="text-sm text-neutral-500">{{ t('tri.quote.catalog_empty') }}</p>

      <div v-else class="space-y-4 max-h-[50vh] overflow-y-auto">
        <div v-for="[listName, listItems] in grouped" :key="listName">
          <div class="text-[11px] font-semibold uppercase tracking-wider text-neutral-400 mb-1.5">{{ listName }}</div>
          <ul class="divide-y divide-neutral-100 border border-neutral-200 rounded-md overflow-hidden">
            <li
              v-for="item in listItems"
              :key="item.id"
              class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-neutral-50"
              @click="item.id && toggle(item.id)"
            >
              <input
                type="checkbox"
                class="rounded border-neutral-300 text-primary-600"
                :checked="!!item.id && selected.has(item.id)"
                @click.stop
                @change="item.id && toggle(item.id)"
              />
              <img v-if="item.image" :src="item.image.url" alt="" class="h-9 w-9 object-contain bg-white border border-neutral-200 rounded" />
              <div class="min-w-0 flex-1">
                <div class="text-sm font-medium text-neutral-900 truncate">
                  <span v-if="item.designation" class="font-mono text-neutral-500 mr-1.5">{{ item.designation }}</span>
                  {{ item.title }}
                </div>
                <div v-if="item.description" class="text-xs text-neutral-500 truncate">{{ item.description }}</div>
              </div>
              <div class="text-sm tabular-nums text-neutral-700 whitespace-nowrap">
                {{ formatMoney(item.base_unit_price) }}
                <span class="text-neutral-400">/ {{ item.unit }}</span>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <div class="flex items-center justify-between pt-1">
        <span class="text-sm text-neutral-500">{{ t('tri.quote.catalog_selected', { n: selected.size }) }}</span>
        <div class="flex gap-2">
          <UiButton variant="secondary" size="sm" @click="open = false">{{ t('common.cancel') }}</UiButton>
          <UiButton size="sm" :disabled="selected.size === 0" @click="insert">{{ t('tri.quote.catalog_insert') }}</UiButton>
        </div>
      </div>
    </div>
  </Modal>
</template>

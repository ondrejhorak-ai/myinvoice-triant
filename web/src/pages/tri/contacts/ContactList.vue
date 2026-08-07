<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { clientsApi, type Client } from '@/api/clients'
import { triApi, type TriTag } from '@/api/tri'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { clientIsIncomplete } from '@/utils/clientCompleteness'

const { t } = useI18n()
const auth = useAuthStore()

const items = ref<Client[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const showArchived = ref(false)
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
const triTags = ref<TriTag[]>([])
const triTagFilter = ref<number | null>(null)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

const filteredItems = computed(() => items.value)

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await clientsApi.list({
      q: search.value,
      archived: showArchived.value,
      sort: sort.value,
      role: 'customers',
      tri_tag_id: triTagFilter.value ?? undefined,
      tri_stats: true,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(async () => {
  load(true)
  triTags.value = await triApi.tags.list().catch(() => [])
})
watch(showArchived, () => load(true))
watch(sort, () => load(true))
watch(triTagFilter, () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

const navigateRow = useRowLink()
function openContact(c: Client, e?: MouseEvent) {
  navigateRow(`/tri/contacts/${c.id}`, e)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('tri.contacts.title') }}</h1>
      <RouterLink
        v-if="auth.canWrite"
        to="/tri/contacts/new"
        class="inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        + {{ t('tri.contacts.new') }}
      </RouterLink>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center gap-3">
        <input
          v-model="search"
          type="search"
          :placeholder="t('common.search')"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('client.show_archived') }}
        </label>
        <select v-model.number="triTagFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option :value="null">{{ t('tri.tags.label') }} — {{ t('common.all') }}</option>
          <option v-for="tag in triTags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
        </select>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="7" />

      <EmptyState v-else-if="!items.length"
        :title="t('tri.contacts.no_data')"
        :cta="t('tri.contacts.create_first')"
        to="/tri/contacts/new" />

      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.company') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.email') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.phone') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.tags.label') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('nav.projects') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('common.revenue') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.last_activity') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="c in filteredItems"
            :key="c.id"
            @click="openContact(c, $event)"
            @auxclick.prevent="openContact(c, $event)"
            class="cursor-pointer hover:bg-neutral-50"
          >
            <td class="px-4 py-3">
              <div class="font-medium text-neutral-900 flex items-center gap-1.5">
                <span>{{ c.company_name }}</span>
                <span
                  v-if="clientIsIncomplete(c)"
                  class="text-warning-600"
                  :title="t('client.incomplete_list_hint')"
                >⚠</span>
              </div>
              <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
            </td>
            <td class="px-4 py-3 text-neutral-600">{{ c.main_email?.trim() || '—' }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ c.phone || '—' }}</td>
            <td class="px-4 py-3">
              <div v-if="c.tri_tags?.length" class="flex flex-wrap gap-1">
                <span
                  v-for="tag in c.tri_tags"
                  :key="tag.id"
                  class="inline-block px-2 py-0.5 rounded-full text-xs font-medium text-white"
                  :style="{ backgroundColor: tag.color }"
                >{{ tag.name }}</span>
              </div>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-center">
              <span v-if="c.tri_jobs_count" class="inline-block px-2 py-0.5 text-xs bg-primary-50 text-primary-700 rounded">
                {{ c.tri_jobs_count }}
              </span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <span v-if="c.tri_revenue && c.tri_revenue > 0">{{ formatMoney(Math.round(c.tri_revenue), 'CZK', 0) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-neutral-600 text-xs">
              <span v-if="c.tri_last_job_date">{{ formatDate(c.tri_last_job_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="c in filteredItems"
          :key="`m-${c.id}`"
          @click="openContact(c, $event)"
          @auxclick.prevent="openContact(c, $event)"
          class="cursor-pointer hover:bg-neutral-50 transition px-4 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate flex items-center gap-1.5">
              <span>{{ c.company_name }}</span>
              <span v-if="clientIsIncomplete(c)" class="text-warning-600 shrink-0" :title="t('client.incomplete_list_hint')">⚠</span>
            </div>
            <div class="font-mono text-sm whitespace-nowrap">
              <span v-if="c.tri_revenue && c.tri_revenue > 0">{{ formatMoney(Math.round(c.tri_revenue), 'CZK', 0) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </div>
          </div>
          <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
          <div class="mt-1 text-xs text-neutral-500 truncate">
            <span v-if="c.main_email">{{ c.main_email }}</span>
            <span v-if="c.main_email && c.phone" class="text-neutral-400"> · </span>
            <span v-if="c.phone">{{ c.phone }}</span>
            <span v-if="!c.main_email && !c.phone" class="text-neutral-300">—</span>
          </div>
          <div v-if="c.tri_tags?.length" class="flex flex-wrap gap-1 mt-1.5">
            <span
              v-for="tag in c.tri_tags"
              :key="tag.id"
              class="inline-block px-2 py-0.5 rounded-full text-xs font-medium text-white"
              :style="{ backgroundColor: tag.color }"
            >{{ tag.name }}</span>
          </div>
          <div class="flex items-center justify-between gap-2 mt-2 text-xs">
            <span class="text-neutral-600">
              <span v-if="c.tri_last_job_date">{{ formatDate(c.tri_last_job_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </span>
            <span v-if="c.tri_jobs_count" class="px-2 py-0.5 bg-primary-50 text-primary-700 rounded">
              {{ t('nav.projects') }}: {{ c.tri_jobs_count }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: filteredItems.length, total: total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJobActivityItem } from '@/api/tri'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import UiButton from '@/components/ui/UiButton.vue'
import UiCard from '@/components/ui/UiCard.vue'

const props = defineProps<{ jobId: number }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const items = ref<TriJobActivityItem[]>([])
const hasMore = ref(false)
const loading = ref(true)
const loadingOlder = ref(false)

const newComment = ref('')
const sending = ref(false)

const editingId = ref<number | null>(null)
const editText = ref('')
const editBusy = ref(false)

const POLL_INTERVAL_MS = 30000
let pollTimer: ReturnType<typeof setInterval> | null = null

const currentUserId = computed(() => auth.user?.id ?? null)

function formatDateTime(value: string): string {
  const d = new Date(value.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return value
  return d.toLocaleString('cs-CZ', {
    day: 'numeric',
    month: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function initials(name: string | null): string {
  if (!name) return '?'
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('')
}

const p = (item: TriJobActivityItem, key: string): string => String(item.payload?.[key] ?? '')

function eventText(item: TriJobActivityItem): string {
  const type = item.event_type ?? ''
  switch (type) {
    case 'job_created':
      return t('tri.activity.event.job_created', { number: p(item, 'number') })
    case 'job_updated': {
      const raw = (item.payload?.fields as string[] | undefined) ?? []
      const labelMap: Record<string, string> = {
        number: t('tri.jobs.number'),
        title: t('tri.jobs.title_field'),
        site_street: t('tri.jobs.site_address'),
        site_city: t('tri.jobs.site_address'),
        site_zip: t('tri.jobs.site_address'),
        notes: t('tri.jobs.notes'),
      }
      const labels = [...new Set(raw.map((f) => labelMap[f] ?? f))]
      return t('tri.activity.event.job_updated', { fields: labels.join(', ') })
    }
    case 'job_archived':
      return t('tri.activity.event.job_archived')
    case 'status_changed':
      return t('tri.activity.event.status_changed', {
        from: t(`tri.jobs.status_${p(item, 'from')}`),
        to: t(`tri.jobs.status_${p(item, 'to')}`),
      })
    case 'variant_created':
      return item.payload?.duplicated
        ? t('tri.activity.event.variant_duplicated', { number: p(item, 'number') })
        : t('tri.activity.event.variant_created', { number: p(item, 'number') })
    case 'variant_status_changed':
      return t('tri.activity.event.variant_status_changed', {
        code: p(item, 'variant_code'),
        from: t(`tri.quote.status_${p(item, 'from')}`),
        to: t(`tri.quote.status_${p(item, 'to')}`),
      })
    case 'variant_approved':
      return t('tri.activity.event.variant_approved', { number: p(item, 'number') })
    case 'contact_added':
      return t('tri.activity.event.contact_added', { name: p(item, 'name') })
    case 'contact_removed':
      return t('tri.activity.event.contact_removed', { name: p(item, 'name') })
    case 'assignee_added':
      return t('tri.activity.event.assignee_added', { name: p(item, 'name') })
    case 'assignee_removed':
      return t('tri.activity.event.assignee_removed', { name: p(item, 'name') })
    case 'invoice_created':
      return p(item, 'invoice_kind') === 'advance'
        ? t('tri.activity.event.invoice_created_advance')
        : t('tri.activity.event.invoice_created_final')
    case 'invoice_linked':
      return t('tri.activity.event.invoice_linked', { varsymbol: p(item, 'varsymbol') || '—' })
    case 'invoice_unlinked':
      return t('tri.activity.event.invoice_unlinked', { varsymbol: p(item, 'varsymbol') || '—' })
    default:
      return t('tri.activity.event.unknown')
  }
}

async function load() {
  loading.value = true
  try {
    const r = await triApi.activity.list(props.jobId)
    items.value = r.data
    hasMore.value = r.has_more
  } finally {
    loading.value = false
  }
}

async function loadOlder() {
  if (!items.value.length) return
  loadingOlder.value = true
  try {
    const r = await triApi.activity.list(props.jobId, { before_id: items.value[0]!.id })
    items.value = [...r.data, ...items.value]
    hasMore.value = r.has_more
  } finally {
    loadingOlder.value = false
  }
}

async function pollNew() {
  const lastId = items.value.at(-1)?.id
  try {
    const r = await triApi.activity.list(props.jobId, lastId !== undefined ? { after_id: lastId } : {})
    if (r.data.length) {
      const known = new Set(items.value.map((i) => i.id))
      items.value = [...items.value, ...r.data.filter((i) => !known.has(i.id))]
    }
  } catch {
    // polling selhal (např. výpadek sítě) — zkusíme příště
  }
}

async function send() {
  const text = newComment.value.trim()
  if (!text || sending.value) return
  sending.value = true
  try {
    const item = await triApi.activity.create(props.jobId, text)
    items.value = [...items.value, item]
    newComment.value = ''
  } catch {
    toast.error(t('common.error'))
  } finally {
    sending.value = false
  }
}

function startEdit(item: TriJobActivityItem) {
  editingId.value = item.id
  editText.value = item.body ?? ''
}

function cancelEdit() {
  editingId.value = null
  editText.value = ''
}

async function saveEdit() {
  if (editingId.value === null) return
  const text = editText.value.trim()
  if (!text || editBusy.value) return
  editBusy.value = true
  try {
    const updated = await triApi.activity.update(editingId.value, text)
    items.value = items.value.map((i) => (i.id === updated.id ? updated : i))
    cancelEdit()
  } catch {
    toast.error(t('common.error'))
  } finally {
    editBusy.value = false
  }
}

async function removeComment(item: TriJobActivityItem) {
  if (!window.confirm(t('tri.activity.delete_confirm'))) return
  try {
    await triApi.activity.remove(item.id)
    items.value = items.value.filter((i) => i.id !== item.id)
  } catch {
    toast.error(t('common.error'))
  }
}

function canModify(item: TriJobActivityItem): boolean {
  return item.kind === 'comment' && item.user_id !== null && item.user_id === currentUserId.value
}

function canDelete(item: TriJobActivityItem): boolean {
  return item.kind === 'comment' && (canModify(item) || auth.isAdmin)
}

onMounted(async () => {
  await load()
  pollTimer = setInterval(pollNew, POLL_INTERVAL_MS)
})

onUnmounted(() => {
  if (pollTimer !== null) clearInterval(pollTimer)
})
</script>

<template>
  <UiCard>
    <div v-if="loading" class="p-5 text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else class="p-5 space-y-3">
      <div v-if="hasMore" class="text-center">
        <UiButton
          type="button"
          variant="outline"
          size="sm"
          :loading="loadingOlder"
          :disabled="loadingOlder"
          @click="loadOlder"
        >
          {{ loadingOlder ? t('common.loading') : t('tri.activity.load_older') }}
        </UiButton>
      </div>

      <p v-if="items.length === 0" class="text-sm text-neutral-500">{{ t('tri.activity.empty') }}</p>

      <template v-for="item in items" :key="item.id">
        <!-- Systémová událost -->
        <div v-if="item.kind === 'event'" class="flex items-baseline gap-2 pl-11 text-xs text-neutral-500">
          <span class="inline-block w-1.5 h-1.5 rounded-full bg-neutral-300 shrink-0 translate-y-[-1px]"></span>
          <p class="min-w-0">
            <span class="font-medium text-neutral-600">{{ item.user_name ?? t('tri.activity.system') }}</span>
            {{ eventText(item) }}
            <span class="text-neutral-400 whitespace-nowrap">· {{ formatDateTime(item.created_at) }}</span>
          </p>
        </div>

        <!-- Komentář -->
        <div v-else class="flex gap-3 group">
          <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold shrink-0">
            {{ initials(item.user_name) }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-2">
              <span class="text-sm font-semibold text-neutral-800">{{ item.user_name ?? t('tri.activity.system') }}</span>
              <span class="text-xs text-neutral-400">{{ formatDateTime(item.created_at) }}</span>
              <span v-if="item.updated_at" class="text-xs text-neutral-400 italic">({{ t('tri.activity.edited') }})</span>
              <span class="ml-auto hidden group-hover:flex gap-2">
                <button
                  v-if="canModify(item) && editingId !== item.id"
                  type="button"
                  class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-800"
                  @click="startEdit(item)"
                >
                  {{ t('common.edit') }}
                </button>
                <button
                  v-if="canDelete(item)"
                  type="button"
                  class="cursor-pointer text-xs text-danger-600 hover:text-danger-700"
                  @click="removeComment(item)"
                >
                  {{ t('common.delete') }}
                </button>
              </span>
            </div>

            <div v-if="editingId === item.id" class="mt-1 space-y-2">
              <textarea
                v-model="editText"
                rows="2"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring resize-y"
                @keydown.ctrl.enter.prevent="saveEdit"
              ></textarea>
              <div class="flex gap-2">
                <UiButton
                  type="button"
                  size="sm"
                  :loading="editBusy"
                  :disabled="editBusy || !editText.trim()"
                  @click="saveEdit"
                >
                  {{ t('common.save') }}
                </UiButton>
                <UiButton type="button" variant="secondary" size="sm" @click="cancelEdit">
                  {{ t('common.cancel') }}
                </UiButton>
              </div>
            </div>
            <p v-else class="mt-0.5 text-sm text-neutral-700 whitespace-pre-wrap break-words">{{ item.body }}</p>
          </div>
        </div>
      </template>

      <!-- Nový komentář -->
      <div v-if="auth.canWrite" class="flex gap-3 pt-2 border-t border-neutral-100">
        <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold shrink-0">
          {{ initials(auth.user?.name ?? null) }}
        </div>
        <div class="flex-1 space-y-2">
          <textarea
            v-model="newComment"
            rows="2"
            :placeholder="t('tri.activity.placeholder')"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring resize-y"
            @keydown.ctrl.enter.prevent="send"
          ></textarea>
          <div class="flex items-center justify-between">
            <span class="text-xs text-neutral-400">{{ t('tri.activity.send_hint') }}</span>
            <UiButton
              type="button"
              size="sm"
              :loading="sending"
              :disabled="sending || !newComment.trim()"
              @click="send"
            >
              {{ t('tri.activity.send') }}
            </UiButton>
          </div>
        </div>
      </div>
    </div>
  </UiCard>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriComplaint, type TriComplaintComment } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import CardSkeleton from '@/components/ui/CardSkeleton.vue'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const id = computed(() => Number(route.params.id))
const item = ref<TriComplaint | null>(null)
const loading = ref(true)
const saving = ref(false)
const statusBusy = ref(false)
const titleDraft = ref('')
const descriptionDraft = ref('')

const comments = computed(() => item.value?.comments ?? [])
const currentUserId = computed(() => auth.user?.id ?? null)
const newComment = ref('')
const sending = ref(false)
const editingId = ref<number | null>(null)
const editText = ref('')
const editBusy = ref(false)

const POLL_INTERVAL_MS = 30000
let pollTimer: ReturnType<typeof setInterval> | null = null

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

function hydrate(row: TriComplaint) {
  item.value = row
  titleDraft.value = row.title
  descriptionDraft.value = row.description ?? ''
}

async function load() {
  loading.value = true
  try {
    hydrate(await triApi.complaints.get(id.value))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!item.value || saving.value) return
  saving.value = true
  try {
    hydrate(await triApi.complaints.update(item.value.id, {
      title: titleDraft.value,
      description: descriptionDraft.value,
    }))
    toast.success(t('tri.complaints.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function setStatus(status: 'open' | 'closed') {
  if (!item.value || statusBusy.value) return
  statusBusy.value = true
  try {
    hydrate(await triApi.complaints.updateStatus(item.value.id, status))
    toast.success(status === 'closed' ? t('tri.complaints.closed') : t('tri.complaints.reopened'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    statusBusy.value = false
  }
}

async function pollComments() {
  if (!item.value) return
  try {
    const row = await triApi.complaints.get(item.value.id)
    if (editingId.value !== null) return
    item.value = {
      ...item.value,
      comments: row.comments ?? [],
      status: row.status,
      closed_at: row.closed_at,
    }
  } catch {
    // polling selhal — zkusíme příště
  }
}

async function send() {
  const text = newComment.value.trim()
  if (!text || sending.value || !item.value) return
  sending.value = true
  try {
    const comment = await triApi.complaints.addComment(item.value.id, text)
    item.value = { ...item.value, comments: [...comments.value, comment] }
    newComment.value = ''
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    sending.value = false
  }
}

function startEdit(comment: TriComplaintComment) {
  editingId.value = comment.id
  editText.value = comment.body
}

function cancelEdit() {
  editingId.value = null
  editText.value = ''
}

async function saveEdit() {
  if (editingId.value === null || !item.value) return
  const text = editText.value.trim()
  if (!text || editBusy.value) return
  editBusy.value = true
  try {
    const updated = await triApi.complaints.updateComment(editingId.value, text)
    item.value = {
      ...item.value,
      comments: comments.value.map((c) => (c.id === updated.id ? updated : c)),
    }
    cancelEdit()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    editBusy.value = false
  }
}

async function removeComment(comment: TriComplaintComment) {
  if (!item.value || !window.confirm(t('tri.activity.delete_confirm'))) return
  try {
    await triApi.complaints.removeComment(comment.id)
    item.value = {
      ...item.value,
      comments: comments.value.filter((c) => c.id !== comment.id),
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

function canModify(comment: TriComplaintComment): boolean {
  return comment.user_id !== null && comment.user_id === currentUserId.value
}

function canDelete(comment: TriComplaintComment): boolean {
  return canModify(comment) || auth.isAdmin
}

onMounted(async () => {
  await load()
  pollTimer = setInterval(pollComments, POLL_INTERVAL_MS)
})
watch(id, () => load())
onUnmounted(() => {
  if (pollTimer !== null) clearInterval(pollTimer)
})
</script>

<template>
  <CardSkeleton v-if="loading" :blocks="2" />
  <div v-else-if="item" class="space-y-6">
    <UiPageHeader :title="item.title">
      <template #below>
        <p class="mt-1 text-sm text-neutral-500">
          <RouterLink class="text-primary-700 font-medium hover:underline" :to="{ name: 'tri-job-detail', params: { id: item.job_id } }">
            {{ item.job_number }} — {{ item.job_title }}
          </RouterLink>
        </p>
      </template>
      <template #actions>
        <UiBadge :variant="item.status === 'closed' ? 'neutral' : 'warning'">
          {{ t(`tri.complaints.status_${item.status}`) }}
        </UiBadge>
        <UiButton v-if="auth.canWrite && item.status === 'open'" variant="outline" size="sm" :loading="statusBusy" @click="setStatus('closed')">
          {{ t('tri.complaints.close') }}
        </UiButton>
        <UiButton v-else-if="auth.canWrite" variant="outline" size="sm" :loading="statusBusy" @click="setStatus('open')">
          {{ t('tri.complaints.reopen') }}
        </UiButton>
        <UiButton variant="secondary" size="sm" :to="{ name: 'tri-complaints' }">
          {{ t('tri.complaints.back_to_list') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <UiCard>
        <div class="p-5 space-y-4">
          <UiInput v-model="titleDraft" :label="t('tri.complaints.title_label')" :disabled="!auth.canWrite" />
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('tri.complaints.description') }}
            <textarea
              v-model="descriptionDraft"
              rows="5"
              :disabled="!auth.canWrite"
              class="mt-1.5 w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring resize-y disabled:bg-neutral-50"
            ></textarea>
          </label>
          <p v-if="item.closed_at" class="text-xs text-neutral-500">
            {{ t('tri.complaints.closed_at') }}: {{ formatDateTime(item.closed_at) }}
          </p>
          <div v-if="auth.canWrite" class="flex justify-end">
            <UiButton type="button" size="sm" :loading="saving" :disabled="saving || !titleDraft.trim()" @click="save">
              {{ t('common.save') }}
            </UiButton>
          </div>
        </div>
      </UiCard>

      <UiCard>
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="font-semibold text-neutral-900">{{ t('tri.complaints.chat_title') }}</h3>
        </div>
        <div class="p-5 space-y-3">
          <p v-if="comments.length === 0" class="text-sm text-neutral-500">{{ t('tri.complaints.chat_empty') }}</p>
          <div v-for="comment in comments" :key="comment.id" class="flex gap-3 group">
            <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold shrink-0">
              {{ initials(comment.user_name) }}
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-baseline gap-2">
                <span class="text-sm font-semibold text-neutral-800">{{ comment.user_name ?? t('tri.activity.system') }}</span>
                <span class="text-xs text-neutral-400">{{ formatDateTime(comment.created_at) }}</span>
                <span v-if="comment.updated_at" class="text-xs text-neutral-400 italic">({{ t('tri.activity.edited') }})</span>
                <span class="ml-auto hidden group-hover:flex gap-2">
                  <button
                    v-if="canModify(comment) && editingId !== comment.id"
                    type="button"
                    class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-800"
                    @click="startEdit(comment)"
                  >
                    {{ t('common.edit') }}
                  </button>
                  <button
                    v-if="canDelete(comment)"
                    type="button"
                    class="cursor-pointer text-xs text-danger-600 hover:text-danger-700"
                    @click="removeComment(comment)"
                  >
                    {{ t('common.delete') }}
                  </button>
                </span>
              </div>
              <div v-if="editingId === comment.id" class="mt-1 space-y-2">
                <textarea
                  v-model="editText"
                  rows="2"
                  class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring resize-y"
                  @keydown.ctrl.enter.prevent="saveEdit"
                ></textarea>
                <div class="flex gap-2">
                  <UiButton type="button" size="sm" :loading="editBusy" :disabled="editBusy || !editText.trim()" @click="saveEdit">
                    {{ t('common.save') }}
                  </UiButton>
                  <UiButton type="button" variant="secondary" size="sm" @click="cancelEdit">
                    {{ t('common.cancel') }}
                  </UiButton>
                </div>
              </div>
              <p v-else class="mt-0.5 text-sm text-neutral-700 whitespace-pre-wrap break-words">{{ comment.body }}</p>
            </div>
          </div>

          <div v-if="auth.canWrite" class="flex gap-3 pt-2 border-t border-neutral-100">
            <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold shrink-0">
              {{ initials(auth.user?.name ?? null) }}
            </div>
            <div class="flex-1 space-y-2">
              <textarea
                v-model="newComment"
                rows="2"
                :placeholder="t('tri.complaints.chat_placeholder')"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring resize-y"
                @keydown.ctrl.enter.prevent="send"
              ></textarea>
              <div class="flex items-center justify-between">
                <span class="text-xs text-neutral-400">{{ t('tri.activity.send_hint') }}</span>
                <UiButton type="button" size="sm" :loading="sending" :disabled="sending || !newComment.trim()" @click="send">
                  {{ t('tri.activity.send') }}
                </UiButton>
              </div>
            </div>
          </div>
        </div>
      </UiCard>
    </div>
  </div>
</template>

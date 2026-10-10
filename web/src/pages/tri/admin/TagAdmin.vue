<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriTag } from '@/api/tri'
import { useToast } from '@/composables/useToast'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'

const { t } = useI18n()
const toast = useToast()

const tags = ref<TriTag[]>([])
const loading = ref(false)
const form = ref({ name: '', color: '#6366f1' })
const editing = ref<TriTag | null>(null)

async function load() {
  loading.value = true
  try {
    tags.value = await triApi.tags.list()
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!form.value.name.trim()) return
  try {
    if (editing.value) {
      await triApi.tags.update(editing.value.id, { name: form.value.name, color: form.value.color })
    } else {
      await triApi.tags.create({ name: form.value.name, color: form.value.color })
    }
    form.value = { name: '', color: '#6366f1' }
    editing.value = null
    await load()
    toast.success(t('common.saved'))
  } catch {
    toast.error(t('common.error'))
  }
}

function startEdit(tag: TriTag) {
  editing.value = tag
  form.value = { name: tag.name, color: tag.color }
}

async function remove(tag: TriTag) {
  if (!confirm(t('tri.tags.delete_confirm', { name: tag.name }))) return
  await triApi.tags.delete(tag.id)
  await load()
}

onMounted(() => load())
</script>

<template>
  <div class="max-w-xl">
    <h1 class="text-2xl font-semibold">{{ t('tri.tags.admin_title') }}</h1>
    <p class="text-neutral-600 mb-6">{{ t('tri.tags.admin_subtitle') }}</p>

    <form class="bg-surface border border-neutral-200 rounded-lg shadow-xs p-4 mb-6 space-y-3" @submit.prevent="save">
      <h2 class="font-medium">{{ editing ? t('common.edit') : t('tri.tags.new') }}</h2>
      <UiInput v-model="form.name" :placeholder="t('tri.tags.name')" required />
      <input v-model="form.color" type="color" class="h-10 w-20" />
      <div class="flex gap-2">
        <UiButton type="submit" size="sm">{{ t('common.save') }}</UiButton>
        <UiButton
          v-if="editing"
          type="button"
          variant="secondary"
          size="sm"
          @click="editing = null; form = { name: '', color: '#6366f1' }"
        >
          {{ t('common.cancel') }}
        </UiButton>
      </div>
    </form>

    <TableSkeleton v-if="loading" :rows="4" :cols="2" />
    <EmptyState v-else-if="!tags.length" compact :title="t('common.no_data')" />
    <ul v-else class="space-y-2">
      <li
        v-for="tag in tags"
        :key="tag.id"
        class="bg-surface border border-neutral-200 rounded-lg shadow-xs p-3 flex items-center justify-between"
      >
        <span class="px-2 py-1 rounded text-white text-sm" :style="{ backgroundColor: tag.color }">{{ tag.name }}</span>
        <div class="flex gap-2">
          <UiButton type="button" variant="ghost" size="sm" @click="startEdit(tag)">{{ t('common.edit') }}</UiButton>
          <UiButton type="button" variant="danger" size="sm" @click="remove(tag)">{{ t('common.delete') }}</UiButton>
        </div>
      </li>
    </ul>
  </div>
</template>

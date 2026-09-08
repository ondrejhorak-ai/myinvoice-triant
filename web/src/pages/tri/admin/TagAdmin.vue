<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriTag } from '@/api/tri'
import { useToast } from '@/composables/useToast'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

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
      <input
        v-model="form.name"
        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        :placeholder="t('tri.tags.name')"
        required
      />
      <input v-model="form.color" type="color" class="h-10 w-20" />
      <div class="flex gap-2">
        <button
          type="submit"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
        >
          {{ t('common.save') }}
        </button>
        <button
          v-if="editing"
          type="button"
          class="cursor-pointer inline-flex items-center gap-1.5 px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md"
          @click="editing = null; form = { name: '', color: '#6366f1' }"
        >
          {{ t('common.cancel') }}
        </button>
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
          <button type="button" class="text-sm text-primary-600 hover:text-primary-700" @click="startEdit(tag)">{{ t('common.edit') }}</button>
          <button type="button" class="text-sm text-danger-500 hover:text-danger-600" @click="remove(tag)">{{ t('common.delete') }}</button>
        </div>
      </li>
    </ul>
  </div>
</template>

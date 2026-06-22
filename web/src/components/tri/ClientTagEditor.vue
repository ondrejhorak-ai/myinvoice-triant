<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriTag } from '@/api/tri'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  clientId?: number | null
  readonly?: boolean
}>()

const { t } = useI18n()
const toast = useToast()

const allTags = ref<TriTag[]>([])
const selected = ref<number[]>([])
const loading = ref(false)
const saving = ref(false)

async function loadTags() {
  allTags.value = await triApi.tags.list()
}

async function loadClientTags() {
  if (!props.clientId) {
    selected.value = []
    return
  }
  loading.value = true
  try {
    const tags = await triApi.tags.forClient(props.clientId)
    selected.value = tags.map((t) => t.id)
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!props.clientId || props.readonly) return
  saving.value = true
  try {
    await triApi.tags.setClient(props.clientId, selected.value)
    toast.success(t('tri.tags.saved'))
  } catch {
    toast.error(t('common.error'))
  } finally {
    saving.value = false
  }
}

function toggle(id: number) {
  if (props.readonly) return
  const set = new Set(selected.value)
  if (set.has(id)) set.delete(id)
  else set.add(id)
  selected.value = [...set]
}

onMounted(async () => {
  await loadTags()
  await loadClientTags()
})

watch(() => props.clientId, () => { void loadClientTags() })

defineExpose({ save, selected })
</script>

<template>
  <div class="space-y-2">
    <label class="text-sm font-medium text-neutral-700">{{ t('tri.tags.label') }}</label>
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else class="flex flex-wrap gap-2">
      <button
        v-for="tag in allTags"
        :key="tag.id"
        type="button"
        class="px-2.5 py-1 rounded-full text-xs font-medium border transition-colors"
        :class="selected.includes(tag.id)
          ? 'border-transparent text-white'
          : 'border-neutral-200 bg-white text-neutral-700 hover:border-neutral-300'"
        :style="selected.includes(tag.id) ? { backgroundColor: tag.color } : {}"
        :disabled="readonly"
        @click="toggle(tag.id)"
      >
        {{ tag.name }}
      </button>
      <span v-if="allTags.length === 0" class="text-sm text-neutral-500">{{ t('tri.tags.empty') }}</span>
    </div>
    <button
      v-if="clientId && !readonly"
      type="button"
      class="cursor-pointer inline-flex items-center gap-1.5 h-7 px-2.5 text-xs border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md disabled:opacity-50"
      :disabled="saving"
      @click="save"
    >
      {{ saving ? t('common.saving') : t('tri.tags.save') }}
    </button>
  </div>
</template>

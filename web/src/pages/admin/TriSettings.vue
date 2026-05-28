<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { TRI_SIDEBAR_MODULES, normalizeHiddenSidebarModules } from '@/config/triSidebar'

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const hiddenModules = ref<string[]>([])

const hideableModules = computed(() => TRI_SIDEBAR_MODULES.filter((item) => item.hideable))

async function load() {
  loading.value = true
  try {
    const data = await settingsApi.getTriSidebarSettings()
    hiddenModules.value = normalizeHiddenSidebarModules(data.hidden_modules)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function isVisible(moduleId: string): boolean {
  return !hiddenModules.value.includes(moduleId)
}

function toggleModule(moduleId: string, visible: boolean) {
  const next = new Set(hiddenModules.value)
  if (visible) {
    next.delete(moduleId)
  } else {
    next.add(moduleId)
  }
  hiddenModules.value = [...next]
}

async function save() {
  saving.value = true
  try {
    const updated = await settingsApi.updateTriSidebarSettings({
      hidden_modules: normalizeHiddenSidebarModules(hiddenModules.value),
    })
    hiddenModules.value = normalizeHiddenSidebarModules(updated.hidden_modules)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('tri_settings.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('tri_settings.subtitle') }}</p>
    </div>

    <section class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <p class="text-xs text-neutral-500 mb-4">{{ t('tri_settings.fixed_hint') }}</p>

      <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>

      <div v-else class="space-y-2">
        <label
          v-for="module in hideableModules"
          :key="module.id"
          class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-neutral-50"
        >
          <input
            :checked="isVisible(module.id)"
            type="checkbox"
            class="rounded border-neutral-300 text-primary-600"
            @change="toggleModule(module.id, ($event.target as HTMLInputElement).checked)"
          />
          <span class="text-sm text-neutral-800">{{ t(module.labelKey) }}</span>
        </label>
      </div>

      <div class="mt-5 flex justify-end">
        <button
          :disabled="saving"
          class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm font-medium rounded-md"
          @click="save"
        >
          {{ saving ? t('common.loading') : t('common.save') }}
        </button>
      </div>
    </section>
  </div>
</template>

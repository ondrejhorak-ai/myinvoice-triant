<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob, type TriJobLink } from '@/api/tri'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  invoiceId?: number | null
  modelValue?: number | null
  readonly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { t } = useI18n()
const toast = useToast()

const jobs = ref<TriJob[]>([])
const linkedJob = ref<TriJobLink | null>(null)
const loading = ref(false)
const saving = ref(false)

const selectedJobId = computed({
  get: () => props.modelValue ?? linkedJob.value?.id ?? null,
  set: (v: number | null) => emit('update:modelValue', v),
})

const jobOptions = computed(() =>
  jobs.value.map((j) => ({
    value: j.id,
    label: `${j.number} — ${j.title}`,
    secondary: j.customer_name ?? undefined,
  })),
)

async function loadJobs() {
  const r = await triApi.jobs.list({ per_page: 200 })
  jobs.value = r.data
}

async function loadLinkedJob() {
  if (!props.invoiceId) {
    linkedJob.value = null
    return
  }
  loading.value = true
  try {
    linkedJob.value = await triApi.invoices.getJob(props.invoiceId)
    if (linkedJob.value) {
      emit('update:modelValue', linkedJob.value.id)
    }
  } finally {
    loading.value = false
  }
}

async function onJobChange(jobId: number | null) {
  selectedJobId.value = jobId
  if (!props.invoiceId || props.readonly) return
  saving.value = true
  try {
    linkedJob.value = await triApi.invoices.setJob(props.invoiceId, jobId)
    toast.success(t('tri.invoices.job_saved'))
  } catch {
    toast.error(t('common.error'))
    await loadLinkedJob()
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadJobs()
  await loadLinkedJob()
})

watch(() => props.invoiceId, () => { void loadLinkedJob() })
</script>

<template>
  <div class="space-y-1">
    <label class="block text-sm font-medium text-neutral-700">{{ t('tri.invoices.job_label') }}</label>
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <template v-else>
      <SearchableSelect
        v-if="!readonly"
        :model-value="selectedJobId"
        :options="jobOptions"
        :placeholder="t('tri.invoices.no_job')"
        :disabled="saving"
        @update:model-value="onJobChange"
      />
      <div v-else-if="linkedJob" class="text-sm">
        <RouterLink
          :to="{ name: 'tri-job-detail', params: { id: linkedJob.id } }"
          class="text-primary-700 hover:text-primary-800 hover:underline font-mono"
        >
          {{ linkedJob.number }}
        </RouterLink>
        <span class="text-neutral-600"> — {{ linkedJob.title }}</span>
      </div>
      <p v-else class="text-sm text-neutral-500">{{ t('tri.invoices.no_job') }}</p>
    </template>
  </div>
</template>

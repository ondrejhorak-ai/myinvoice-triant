<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriComplaint, type TriJob } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import UiInput from '@/components/ui/UiInput.vue'
import UiButton from '@/components/ui/UiButton.vue'

const props = defineProps<{
  jobId?: number
  jobs?: TriJob[]
}>()

const emit = defineEmits<{
  close: []
  created: [row: TriComplaint]
}>()

const { t } = useI18n()
const toast = useToast()

const jobId = ref<number | ''>(props.jobId ?? '')
const title = ref('')
const description = ref('')
const saving = ref(false)

watch(
  () => props.jobId,
  (value) => {
    if (value) jobId.value = value
  },
)

async function submit() {
  if (!jobId.value || !title.value.trim() || saving.value) return
  saving.value = true
  try {
    const row = await triApi.complaints.create({
      job_id: Number(jobId.value),
      title: title.value.trim(),
      description: description.value.trim() || null,
    })
    toast.success(t('tri.complaints.created'))
    emit('created', row)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :title="t('tri.complaints.new')" width-class="max-w-lg" @close="emit('close')">
    <div class="space-y-4">
      <label v-if="!props.jobId" class="block text-sm font-medium text-neutral-700">
        {{ t('tri.complaints.job') }}
        <select
          v-model="jobId"
          class="mt-1.5 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs outline-none focus-ring"
        >
          <option value="">{{ t('tri.complaints.job_required') }}</option>
          <option v-for="j in jobs ?? []" :key="j.id" :value="j.id">{{ j.number }} — {{ j.title }}</option>
        </select>
      </label>
      <UiInput v-model="title" :label="t('tri.complaints.title_label')" />
      <label class="block text-sm font-medium text-neutral-700">
        {{ t('tri.complaints.description') }}
        <textarea
          v-model="description"
          rows="4"
          class="mt-1.5 w-full px-3 py-2 border border-neutral-300 rounded-md text-sm shadow-xs outline-none focus-ring resize-y"
        ></textarea>
      </label>
      <div class="flex justify-end gap-2 pt-1">
        <UiButton type="button" variant="secondary" size="sm" @click="emit('close')">
          {{ t('common.cancel') }}
        </UiButton>
        <UiButton
          type="button"
          size="sm"
          :loading="saving"
          :disabled="saving || !jobId || !title.trim()"
          @click="submit"
        >
          {{ t('common.create') }}
        </UiButton>
      </div>
    </div>
  </Modal>
</template>

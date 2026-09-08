<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriQuoteImage } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  variantId?: number
  image: TriQuoteImage | null
  disabled?: boolean
  upload?: (file: File) => Promise<TriQuoteImage>
}>()

const emit = defineEmits<{
  uploaded: [image: TriQuoteImage]
  removed: []
}>()

const MAX_BYTES = 10 * 1024 * 1024
const ACCEPTED = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
const input = ref<HTMLInputElement | null>(null)
const uploading = ref(false)
const { t } = useI18n()
const toast = useToast()

function choose() {
  if (!props.disabled && !uploading.value) input.value?.click()
}

async function selected(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (!file) return
  if (!ACCEPTED.has(file.type) && !/\.(jpe?g|png|webp|gif)$/i.test(file.name)) {
    toast.error(t('tri.quote.image_invalid_type'))
    return
  }
  if (file.size > MAX_BYTES) {
    toast.error(t('tri.quote.image_too_large'))
    return
  }
  uploading.value = true
  try {
    const image = props.upload
      ? await props.upload(file)
      : await triApi.variants.uploadImage(props.variantId as number, file)
    emit('uploaded', image)
    toast.success(t('tri.quote.image_uploaded'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('tri.quote.image_upload_failed')))
  } finally {
    uploading.value = false
  }
}
</script>

<template>
  <div class="shrink-0 flex items-center gap-1 py-1 pl-1">
    <input
      ref="input"
      type="file"
      class="hidden"
      accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
      :disabled="disabled || uploading"
      @change="selected"
    />
    <button
      type="button"
      class="relative flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded border border-neutral-200 bg-neutral-50 text-neutral-400 hover:border-primary-400 hover:text-primary-600 disabled:cursor-wait disabled:opacity-60"
      :title="image ? t('tri.quote.image_replace') : t('tri.quote.image_add')"
      :disabled="disabled || uploading"
      @click="choose"
    >
      <img v-if="image" :src="image.url" alt="" class="h-full w-full object-contain bg-surface" />
      <span v-else-if="uploading" class="text-xs">…</span>
      <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M4 6h4l1.5-2h5L16 6h4v13H4z" />
        <circle cx="12" cy="12.5" r="3.5" />
      </svg>
    </button>
    <button
      v-if="image && !disabled"
      type="button"
      class="h-6 w-5 text-sm text-danger-500 hover:text-danger-700"
      :title="t('tri.quote.image_remove')"
      @click="emit('removed')"
    >×</button>
  </div>
</template>

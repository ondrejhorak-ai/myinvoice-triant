<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientIncompleteKind, type ClientCompletenessFields } from '@/utils/clientCompleteness'

const props = defineProps<{
  client: ClientCompletenessFields
  editTo: string
}>()

const { t } = useI18n()

const messageKey = computed(() => {
  const kind = clientIncompleteKind(props.client)
  return kind ? `client.incomplete_banner_${kind}` : null
})
</script>

<template>
  <div
    v-if="messageKey"
    class="rounded-md bg-warning-50 border border-warning-500/30 px-4 py-3 text-sm text-warning-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
  >
    <span>{{ t(messageKey) }}</span>
    <RouterLink :to="editTo" class="text-warning-900 font-medium hover:underline shrink-0">
      {{ t('client.incomplete_banner_edit') }} →
    </RouterLink>
  </div>
</template>

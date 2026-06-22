<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import ContactForm from '@/pages/tri/contacts/ContactForm.vue'
import type { Client } from '@/api/clients'

const emit = defineEmits<{
  (e: 'created', client: Client): void
  (e: 'close'): void
}>()

const { t } = useI18n()

function onCreated(client: Client) {
  emit('created', client)
}
</script>

<template>
  <Modal :title="t('tri.contacts.new_title')" width-class="max-w-3xl" @close="emit('close')">
    <ContactForm embedded @created="onCreated" @cancel="emit('close')" />
  </Modal>
</template>

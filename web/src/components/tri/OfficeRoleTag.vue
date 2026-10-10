<script setup lang="ts">
import { computed } from 'vue'
const props = defineProps<{ name: string; slug?: string; color?: string }>()
const role = computed(() => {
  const raw = `${props.slug || ''} ${props.name}`
  if (/architekt|architect/i.test(raw)) return 'architect'
  if (/kontakt|contact/i.test(raw) && !/zakaznik|zákazník|customer/i.test(raw)) return 'contact'
  return 'customer'
})
</script>
<template>
  <span class="office-role" :data-role="role">{{ name }}</span>
</template>

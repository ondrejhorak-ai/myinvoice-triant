<script setup lang="ts">
import { computed } from 'vue'
const props = defineProps<{ name: string; slug?: string; color?: string }>()
const role = computed(() =>
  /architekt|architect/i.test(props.slug || props.name)
    ? 'architect'
    : 'customer',
)
</script>
<template>
  <span class="office-role" :data-role="role">{{ name }}</span>
</template>

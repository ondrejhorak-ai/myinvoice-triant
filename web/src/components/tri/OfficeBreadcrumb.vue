<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
defineProps<{ items: { label: string; to?: RouteLocationRaw }[] }>()
</script>
<template>
  <nav class="office-breadcrumb">
    <template v-for="(item, i) in items" :key="i">
      <span v-if="i" aria-hidden="true">›</span>
      <RouterLink v-if="item.to" :to="item.to">{{ item.label }}</RouterLink>
      <span v-else aria-current="page">{{ item.label }}</span>
    </template>
  </nav>
</template>

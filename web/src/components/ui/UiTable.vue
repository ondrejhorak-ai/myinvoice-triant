<script setup lang="ts">
withDefaults(defineProps<{
  /** Přidá `table-sticky-first` (první sloupec drží při horizontálním scrollu). */
  stickyFirst?: boolean
}>(), {
  stickyFirst: true,
})
</script>

<template>
  <div class="overflow-x-auto">
    <table
      class="w-full text-sm"
      :class="stickyFirst ? 'table-sticky-first' : ''"
    >
      <thead class="bg-neutral-50 text-neutral-500 text-[12px] uppercase tracking-wide">
        <slot name="head" />
      </thead>
      <tbody class="divide-y divide-neutral-100 [&_tr]:hover:bg-neutral-50">
        <slot />
      </tbody>
    </table>
  </div>
</template>

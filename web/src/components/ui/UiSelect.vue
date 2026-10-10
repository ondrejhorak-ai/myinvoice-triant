<script setup lang="ts">
import { computed, useAttrs, useId } from 'vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  label?: string
  hint?: string
  error?: string
  size?: 'sm' | 'md'
  id?: string
}>(), {
  size: 'md',
})

const model = defineModel<string | number | boolean | null>()
const attrs = useAttrs()

const classAttr = computed(() => attrs.class)
const selectAttrs = computed(() => {
  const rest = { ...attrs }
  delete rest.class
  return rest
})

const generatedId = useId()
const selectId = computed(() => props.id || (attrs.id as string | undefined) || generatedId)
const describedBy = computed(() => {
  if (props.error) return `${selectId.value}-error`
  if (props.hint) return `${selectId.value}-hint`
  return undefined
})

const SIZE: Record<'sm' | 'md', string> = {
  sm: 'h-9 text-sm',
  md: 'h-11 text-sm',
}
</script>

<template>
  <div class="min-w-0" :class="classAttr">
    <label v-if="label" class="block text-xs font-semibold text-neutral-600 mb-1" :for="selectId">
      {{ label }}
    </label>
    <select
      :id="selectId"
      v-model="model"
      v-bind="selectAttrs"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="describedBy"
      class="w-full px-3 border bg-surface outline-none focus-ring"
      :class="[SIZE[size], error ? 'border-danger-500' : 'border-neutral-200']"
    >
      <slot />
    </select>
    <p v-if="error" :id="`${selectId}-error`" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" :id="`${selectId}-hint`" class="mt-1.5 text-sm text-neutral-500">{{ hint }}</p>
  </div>
</template>

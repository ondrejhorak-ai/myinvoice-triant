<script setup lang="ts">
import { computed, useAttrs, useId } from 'vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  label?: string
  hint?: string
  error?: string
  /** sm/md are form fields. inline stays inside a grid or table cell. */
  size?: 'sm' | 'md' | 'inline'
  id?: string
}>(), {
  size: 'md',
})

const [model, modifiers] = defineModel<string | number | boolean | null>({
  set(value) {
    if (!modifiers.number || value === '' || value == null || typeof value === 'boolean') return value
    const n = typeof value === 'number' ? value : Number(value)
    return Number.isNaN(n) ? value : n
  },
})
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

const SIZE: Record<'sm' | 'md' | 'inline', string> = {
  sm: 'h-9 text-sm',
  md: 'h-11 text-sm',
  inline: 'ui-select-inline h-9 px-1.5 text-xs',
}
</script>

<template>
  <div
    :class="[size === 'inline' ? 'inline-flex min-w-0 max-w-full align-middle' : 'min-w-0', classAttr]"
  >
    <label v-if="label" class="block text-xs font-semibold text-neutral-600 mb-1" :for="selectId">
      {{ label }}
    </label>
    <div class="relative min-w-0 w-full">
      <select
        :id="selectId"
        v-model="model"
        v-bind="selectAttrs"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        class="ui-select w-full appearance-none border bg-surface bg-none outline-none focus-ring"
        :class="[SIZE[size], size === 'inline' ? 'pr-6' : 'px-3 pr-9', error ? 'border-danger-500' : 'border-neutral-200']"
      >
        <slot />
      </select>
      <svg
        class="pointer-events-none absolute top-1/2 -translate-y-1/2 text-neutral-500"
        :class="size === 'inline' ? 'right-1 h-3 w-3' : 'right-3 h-4 w-4'"
        viewBox="0 0 20 20"
        fill="none"
        aria-hidden="true"
      >
        <path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" />
      </svg>
    </div>
    <p v-if="error" :id="`${selectId}-error`" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" :id="`${selectId}-hint`" class="mt-1.5 text-sm text-neutral-500">{{ hint }}</p>
  </div>
</template>

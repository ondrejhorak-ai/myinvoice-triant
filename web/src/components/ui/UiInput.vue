<script setup lang="ts">
import { computed, useAttrs, useSlots, useId } from 'vue'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
  label?: string
  hint?: string
  error?: string
  size?: 'sm' | 'md'
  type?: string
  id?: string
}>(), {
  size: 'md',
  type: 'text',
})

const model = defineModel<string | number | null>()
const attrs = useAttrs()
const slots = useSlots()

const classAttr = computed(() => attrs.class)
const inputAttrs = computed(() => {
  const rest = { ...attrs }
  delete rest.class
  return rest
})

const generatedId = useId()
const inputId = computed(() => props.id || (attrs.id as string | undefined) || generatedId)
const describedBy = computed(() => {
  if (props.error) return inputId.value ? `${inputId.value}-error` : undefined
  if (props.hint) return inputId.value ? `${inputId.value}-hint` : undefined
  return undefined
})

const SIZE: Record<'sm' | 'md', string> = {
  sm: 'h-9 text-sm',
  md: 'h-11 text-sm',
}
</script>

<template>
  <div class="min-w-0" :class="classAttr">
    <label v-if="label" class="block text-xs font-semibold text-neutral-600 mb-1" :for="inputId">
      {{ label }}
    </label>
    <div class="relative">
      <span
        v-if="slots.prefix"
        class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-neutral-400 [&>svg]:w-4 [&>svg]:h-4"
      >
        <slot name="prefix" />
      </span>
      <input
        :id="inputId"
        v-model="model"
        :type="type"
        v-bind="inputAttrs"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        class="w-full px-3 border rounded-md bg-surface outline-none focus-ring placeholder:text-neutral-400"
        :class="[
          SIZE[size],
          slots.prefix ? 'pl-10' : '',
          error ? 'border-danger-500' : 'border-neutral-200',
        ]"
      />
    </div>
    <p v-if="error" :id="inputId ? `${inputId}-error` : undefined" class="mt-1.5 text-sm text-danger-600">{{ error }}</p>
    <p v-else-if="hint" :id="inputId ? `${inputId}-hint` : undefined" class="mt-1.5 text-sm text-neutral-500">{{ hint }}</p>
  </div>
</template>

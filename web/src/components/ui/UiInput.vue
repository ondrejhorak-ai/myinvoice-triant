<script setup lang="ts">
import { computed, ref, watch, useAttrs, useSlots, useId } from 'vue'
import { formatIsoDateCs, formatIsoTime, parseCzechDate, parseCzechTime } from '@/composables/czechDate'

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

const [model, modifiers] = defineModel<string | number | null>({
  set(value) {
    if (!modifiers.number || value === '' || value == null) return value
    const n = typeof value === 'number' ? value : Number(value)
    return Number.isNaN(n) ? value : n
  },
})
const attrs = useAttrs()
const slots = useSlots()

const classAttr = computed(() => attrs.class)
const inputAttrs = computed(() => {
  const rest = { ...attrs }
  delete rest.class
  delete rest.onChange
  delete rest.min
  delete rest.max
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

const isDate = computed(() => props.type === 'date')
const isTime = computed(() => props.type === 'time')
const isMasked = computed(() => isDate.value || isTime.value)
const textValue = ref('')
const textEl = ref<HTMLInputElement | null>(null)
const pickerEl = ref<HTMLInputElement | null>(null)

function maskFromModel(value: string | number | null | undefined): string {
  if (isDate.value) return formatIsoDateCs(value)
  if (isTime.value) return formatIsoTime(value == null ? '' : String(value))
  return ''
}

watch(() => model.value, (value) => {
  if (!isMasked.value) return
  if (textEl.value && document.activeElement === textEl.value) return
  textValue.value = maskFromModel(value)
}, { immediate: true })

function commitMask() {
  const raw = textValue.value.trim()
  if (!raw) {
    model.value = ''
    textValue.value = ''
    return
  }
  const next = isDate.value ? parseCzechDate(raw) : parseCzechTime(raw)
  if (next) {
    model.value = next
    textValue.value = maskFromModel(next)
    return
  }
  textValue.value = maskFromModel(model.value)
}

function onMaskedChange(event: Event) {
  commitMask()
  const listener = attrs.onChange as ((event: Event) => void) | undefined
  listener?.(event)
}

function onPicker(event: Event) {
  const next = (event.target as HTMLInputElement).value
  model.value = next
  textValue.value = maskFromModel(next)
}

function openPicker() {
  const el = pickerEl.value
  if (!el || el.disabled) return
  try {
    el.showPicker()
  } catch {
    el.focus()
  }
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
        v-if="isMasked"
        :id="inputId"
        ref="textEl"
        v-model="textValue"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        lang="cs"
        :placeholder="isDate ? 'dd. mm. rrrr' : 'hh:mm'"
        v-bind="inputAttrs"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="describedBy"
        class="w-full px-3 border rounded-md bg-surface outline-none focus-ring placeholder:text-neutral-400"
        :class="[
          SIZE[size],
          isDate ? 'pr-10' : '',
          slots.prefix ? 'pl-10' : '',
          error ? 'border-danger-500' : 'border-neutral-200',
        ]"
        @change="onMaskedChange"
        @blur="commitMask"
        @keydown.enter="commitMask"
      />
      <button
        v-if="isDate"
        type="button"
        class="absolute right-0 top-0 flex h-full w-10 items-center justify-center text-neutral-500"
        tabindex="-1"
        aria-label="Kalendář"
        @click="openPicker"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 3v2m8-2v2M4 9h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z" />
        </svg>
      </button>
      <input
        v-if="isDate"
        ref="pickerEl"
        type="date"
        lang="cs"
        class="pointer-events-none absolute h-px w-px opacity-0"
        tabindex="-1"
        :value="model || ''"
        :min="inputAttrs.min as string | undefined"
        :max="inputAttrs.max as string | undefined"
        :disabled="inputAttrs.disabled != null && inputAttrs.disabled !== false"
        @change="onPicker"
      />
      <input
        v-if="!isMasked"
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

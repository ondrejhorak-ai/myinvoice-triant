<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'

const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger'
  size?: 'sm' | 'md'
  loading?: boolean
  disabled?: boolean
  type?: 'button' | 'submit' | 'reset'
  to?: RouteLocationRaw
  href?: string
}>(), {
  variant: 'primary',
  size: 'md',
  type: 'button',
})

const emit = defineEmits<{ click: [e: MouseEvent] }>()

const VARIANT: Record<NonNullable<typeof props.variant>, string> = {
  primary:   'bg-primary-600 hover:bg-primary-700 text-white shadow-xs',
  secondary: 'bg-surface border border-neutral-300 text-neutral-700 shadow-xs hover:bg-neutral-50',
  outline:   'bg-transparent border border-neutral-300 text-neutral-700 hover:bg-neutral-50',
  ghost:     'bg-transparent text-neutral-700 hover:bg-neutral-100',
  danger:    'bg-danger-600 hover:bg-danger-600/90 text-white shadow-xs',
}

const SIZE: Record<NonNullable<typeof props.size>, string> = {
  sm: 'h-9 px-3 text-sm gap-1.5',
  md: 'h-10 px-4 text-sm gap-2',
}

const classes = computed(() => [
  'inline-flex items-center justify-center font-semibold rounded-md cursor-pointer',
  'disabled:opacity-50 disabled:pointer-events-none',
  'focus-ring',
  VARIANT[props.variant],
  SIZE[props.size],
].join(' '))

const isDisabled = computed(() => props.disabled || props.loading)

function onClick(e: MouseEvent) {
  if (isDisabled.value) {
    e.preventDefault()
    return
  }
  emit('click', e)
}
</script>

<template>
  <RouterLink
    v-if="to && !isDisabled"
    :to="to"
    :class="classes"
    @click="onClick"
  >
    <svg v-if="loading" class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
    </svg>
    <span v-else-if="$slots.icon" class="shrink-0 inline-flex w-4 h-4 items-center justify-center [&>svg]:w-4 [&>svg]:h-4">
      <slot name="icon" />
    </span>
    <slot />
  </RouterLink>
  <a
    v-else-if="href && !isDisabled"
    :href="href"
    :class="classes"
    @click="onClick"
  >
    <svg v-if="loading" class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
    </svg>
    <span v-else-if="$slots.icon" class="shrink-0 inline-flex w-4 h-4 items-center justify-center [&>svg]:w-4 [&>svg]:h-4">
      <slot name="icon" />
    </span>
    <slot />
  </a>
  <button
    v-else
    :type="type"
    :class="classes"
    :disabled="isDisabled"
    :aria-busy="loading || undefined"
    @click="onClick"
  >
    <svg v-if="loading" class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
    </svg>
    <span v-else-if="$slots.icon" class="shrink-0 inline-flex w-4 h-4 items-center justify-center [&>svg]:w-4 [&>svg]:h-4">
      <slot name="icon" />
    </span>
    <slot />
  </button>
</template>

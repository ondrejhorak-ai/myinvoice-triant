<script setup lang="ts">
withDefaults(defineProps<{
  /** Status tokeny + sémantické varianty. */
  variant?: 'draft' | 'issued' | 'sent' | 'paid' | 'overdue' | 'cancelled' | 'neutral' | 'primary' | 'success' | 'warning' | 'danger'
}>(), {
  variant: 'neutral',
})

const VARIANT: Record<string, string> = {
  draft:      'bg-status-draft-bg text-status-draft-fg',
  issued:     'bg-status-issued-bg text-status-issued-fg',
  sent:       'bg-status-sent-bg text-status-sent-fg',
  paid:       'bg-status-paid-bg text-status-paid-fg',
  overdue:    'bg-status-overdue-bg text-status-overdue-fg',
  cancelled:  'bg-status-cancelled-bg text-status-cancelled-fg',
  neutral:    'bg-neutral-100 text-neutral-700',
  primary:    'bg-primary-50 text-primary-700',
  success:    'bg-success-50 text-success-700',
  warning:    'bg-warning-50 text-warning-600',
  danger:     'bg-danger-50 text-danger-600',
}
</script>

<template>
  <span
    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
    :class="VARIANT[variant]"
  >
    <slot />
  </span>
</template>

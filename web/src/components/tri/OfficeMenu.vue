<script setup lang="ts">
import { ref, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
const { t } = useI18n(),
  root = ref<HTMLElement>(),
  popup = ref<HTMLElement>(),
  trigger = ref<HTMLButtonElement>(),
  open = ref(false),
  position = ref({ top: '0px', left: '0px' })
function close(restore = false) {
  open.value = false
  if (restore) trigger.value?.focus()
}
function outside(e: PointerEvent) {
  if (
    !root.value?.contains(e.target as Node) &&
    !popup.value?.contains(e.target as Node)
  )
    close()
}
function key(e: KeyboardEvent) {
  if (!open.value) return
  if (e.key === 'Escape') {
    e.stopPropagation()
    close(true)
  } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
    e.preventDefault()
    const buttons = [
      ...popup.value!.querySelectorAll<HTMLButtonElement>(
        'button:not(:disabled),a[href]',
      ),
    ]
    const current = buttons.indexOf(document.activeElement as HTMLButtonElement)
    const next =
      e.key === 'Home'
        ? 0
        : e.key === 'End'
          ? buttons.length - 1
          : (current + (e.key === 'ArrowDown' ? 1 : -1) + buttons.length) %
            buttons.length
    buttons[next]?.focus()
  } else if (e.key === 'Tab') close()
}
async function toggle() {
  if (open.value) {
    close()
    return
  }
  const r = trigger.value!.getBoundingClientRect()
  position.value = {
    top: `${r.bottom}px`,
    left: `${Math.max(8, Math.min(window.innerWidth - 224, r.right - 208))}px`,
  }
  open.value = true
  await nextTick()
  const h = popup.value!.offsetHeight
  position.value.top = `${Math.max(8, r.bottom + h > window.innerHeight ? r.top - h : r.bottom)}px`
  popup.value
    ?.querySelector<HTMLButtonElement>('button:not(:disabled)')
    ?.focus()
}
function scroll(e: Event) {
  if (!popup.value?.contains(e.target as Node)) close()
}
document.addEventListener('pointerdown', outside)
document.addEventListener('keydown', key)
window.addEventListener('scroll', scroll, true)
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', outside)
  document.removeEventListener('keydown', key)
  window.removeEventListener('scroll', scroll, true)
})
</script>
<template>
  <div ref="root" class="relative shrink-0">
    <button
      ref="trigger"
      type="button"
      class="w-9 h-9 cursor-pointer text-neutral-500 hover:bg-primary-50 focus-ring"
      :aria-label="t('office.actions')"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click.stop="toggle"
    >
      ⋯</button
    ><Teleport to="body"
      ><div
        v-if="open"
        ref="popup"
        role="menu"
        :style="position"
        class="fixed z-[70] bg-surface border border-neutral-200 shadow-lg p-1 w-52 text-sm [&_button]:block [&_button]:w-full [&_button]:text-left [&_button]:px-3 [&_button]:py-2 [&_button]:cursor-pointer [&_button:hover]:bg-primary-50 [&_button:disabled]:opacity-40"
        @click="close(true)"
      >
        <slot /></div
    ></Teleport>
  </div>
</template>

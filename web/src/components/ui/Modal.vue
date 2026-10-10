<script setup lang="ts">
import { ref, useId, nextTick, onMounted, onBeforeUnmount } from 'vue'
import {useI18n} from 'vue-i18n'
const {t}=useI18n()
const dialog=ref<HTMLElement|null>(null),titleId=useId()
let previousFocus:HTMLElement|null=null,previousOverflow=''

/**
 * Generic modal — backdrop, ESC close, click-outside close, sticky header.
 * Tělo se scrolluje, hlavička i patička zůstávají. Šířka přes `widthClass`
 * (Tailwind utility). Volitelný slot `footer` drží akční tlačítka dialogu.
 */
const props = withDefaults(defineProps<{
  title: string
  widthClass?: string
}>(), {
  widthClass: 'max-w-3xl',
})

const emit = defineEmits<{
  (e: 'close'): void
}>()

function onKey(e: KeyboardEvent) {
  const dialogs=document.querySelectorAll('[data-office-modal]')
  if(dialogs[dialogs.length-1]!==dialog.value)return
  if (e.key === 'Escape') {e.preventDefault();e.stopImmediatePropagation();emit('close')}
  if(e.key==='Tab'){
    const controls=[...dialog.value!.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href],[tabindex="0"]')].filter(el=>el.offsetParent!==null)
    const first=controls[0],last=controls[controls.length-1]
    if(!first){e.preventDefault();dialog.value?.focus()}else if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}
  }
}
onMounted(async () => {
  previousFocus=document.activeElement as HTMLElement
  previousOverflow=document.body.style.overflow
  document.addEventListener('keydown', onKey)
  document.body.style.overflow = 'hidden'
  await nextTick()
  dialog.value?.querySelector<HTMLElement>('input,select,textarea,button')?.focus()
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKey)
  document.body.style.overflow = previousOverflow
  previousFocus?.focus()
})

void props
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
      @click.self="emit('close')">
      <div ref="dialog" data-office-modal role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1" class="bg-surface rounded-lg shadow-xl w-full flex flex-col max-h-[90vh]" :class="widthClass">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between shrink-0">
          <h2 :id="titleId" class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" @click="emit('close')" :aria-label="t('common.close')"
            class="cursor-pointer w-8 h-8 inline-flex items-center justify-center rounded-md text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>
        <div class="overflow-y-auto flex-1 p-5">
          <slot />
        </div>
        <footer v-if="$slots.footer"
          class="px-5 py-3 border-t border-neutral-200 flex items-center justify-end gap-2 shrink-0">
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>

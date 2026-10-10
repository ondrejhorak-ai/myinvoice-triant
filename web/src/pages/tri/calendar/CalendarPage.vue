<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  triApi,
  type TriCalendarEvent,
  type TriCalendarKind,
  type TriCalendarStation,
  type TriJob,
} from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const KINDS: TriCalendarKind[] = ['shifts', 'dispatch', 'production']
const STATIONS: TriCalendarStation[] = ['konstrukce', 'vyroba', 'kompletace', 'lakovna', 'expedice', 'montaz']

function nextStatusOf(calendar: TriCalendarKind, status: string): string | null {
  if (calendar === 'dispatch') return status === 'planned' ? 'confirmed' : status === 'confirmed' ? 'planned' : null
  if (calendar === 'production') return status === 'planned' ? 'in_progress' : status === 'in_progress' ? 'done' : null
  return null
}

function eventClass(ev: { calendar: TriCalendarKind }) {
  return `office-event office-event-${ev.calendar}`
}

const layers = ref<Record<TriCalendarKind, boolean>>({ shifts: true, dispatch: true, production: true })
const view = ref<'month' | 'week'>('week')
const chooserOpen = ref(false)
const pendingDay = ref('')
const overflowDay = ref<string | null>(null)
const overflowEvents = computed(() => overflowDay.value ? eventsOn(overflowDay.value) : [])
const cursor = ref(startOfDay(new Date()))
const events = ref<TriCalendarEvent[]>([])
const jobs = ref<TriJob[]>([])
const loading = ref(false)
const saving = ref(false)
const modalOpen = ref(false)
const editing = ref<TriCalendarEvent | null>(null)
const form = ref(blankForm('production', isoDate(new Date())))

function startOfDay(d: Date) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate())
}
function isoDate(d: Date) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
function addDays(d: Date, n: number) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)
}
function mondayOf(d: Date) {
  const dow = d.getDay() === 0 ? 7 : d.getDay()
  return addDays(d, 1 - dow)
}
function monthGrid(d: Date): string[] {
  const first = new Date(d.getFullYear(), d.getMonth(), 1)
  const start = mondayOf(first)
  return Array.from({ length: 42 }, (_, i) => isoDate(addDays(start, i)))
}
function weekDays(d: Date): string[] {
  const start = mondayOf(d)
  return Array.from({ length: 7 }, (_, i) => isoDate(addDays(start, i)))
}

const days = computed(() => view.value === 'month' ? monthGrid(cursor.value) : weekDays(cursor.value))
const rangeFrom = computed(() => days.value[0])
const rangeTo = computed(() => isoDate(addDays(new Date(days.value[days.value.length - 1] + 'T00:00:00'), 1)))
const heading = computed(() => {
  const fmt = new Intl.DateTimeFormat(locale.value === 'en' ? 'en-GB' : 'cs-CZ', {
    month: 'long',
    year: 'numeric',
    ...(view.value === 'week' ? { day: 'numeric' } : {}),
  })
  if (view.value === 'week') {
    return `${fmt.format(new Date(days.value[0] + 'T00:00:00'))} – ${fmt.format(new Date(days.value[6] + 'T00:00:00'))}`
  }
  return fmt.format(cursor.value)
})
const inMonth = computed(() => {
  const m = cursor.value.getMonth()
  const y = cursor.value.getFullYear()
  return (day: string) => {
    const d = new Date(day + 'T00:00:00')
    return d.getMonth() === m && d.getFullYear() === y
  }
})

const visibleEvents = computed(() => events.value.filter((ev) => layers.value[ev.calendar]))
function eventStart(ev: TriCalendarEvent) { return ev.starts_at.slice(0, 10) }
function eventEnd(ev: TriCalendarEvent) { return (ev.ends_at ?? ev.starts_at).slice(0, 10) }
function spansDays(ev: TriCalendarEvent) { return eventEnd(ev) > eventStart(ev) }
function eventsOn(day: string, spanned = false) {
  return visibleEvents.value.filter((ev) => {
    if (eventStart(ev) > day || eventEnd(ev) < day) return false
    if (view.value === 'week' && spansDays(ev) !== spanned) return false
    return true
  })
}
const spanEvents = computed(() => visibleEvents.value.filter(spansDays))

async function load() {
  loading.value = true
  try {
    const r = await triApi.calendar.list({ from: rangeFrom.value, to: rangeTo.value })
    events.value = r.data
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

async function loadJobs() {
  const r = await triApi.jobs.list({ per_page: 100 })
  jobs.value = r.data
}

function blankForm(calendar: TriCalendarKind, day: string) {
  return {
    title: '',
    calendar,
    job_id: '' as string | number,
    station: (calendar === 'production' ? 'vyroba' : '') as string,
    all_day: true,
    starts_date: day,
    starts_time: '08:00',
    ends_date: day,
    ends_time: '16:00',
    note: '',
    status: 'planned',
  }
}

function openCreate(day: string) {
  pendingDay.value = day
  chooserOpen.value = true
}
function pickKind(calendar: TriCalendarKind) {
  chooserOpen.value = false
  editing.value = null
  form.value = blankForm(calendar, pendingDay.value)
  if (calendar === 'shifts') form.value.all_day = true
  modalOpen.value = true
}

function openEdit(ev: TriCalendarEvent) {
  editing.value = ev
  const start = ev.starts_at.replace(' ', 'T')
  const end = (ev.ends_at ?? ev.starts_at).replace(' ', 'T')
  form.value = {
    title: ev.title,
    calendar: ev.calendar,
    job_id: ev.job_id ?? '',
    station: ev.station ?? (ev.calendar === 'production' ? 'vyroba' : ''),
    all_day: ev.all_day,
    starts_date: start.slice(0, 10),
    starts_time: start.slice(11, 16) || '08:00',
    ends_date: end.slice(0, 10),
    ends_time: end.slice(11, 16) || '16:00',
    note: ev.note ?? '',
    status: ev.status,
  }
  modalOpen.value = true
}

function payload() {
  const allDay = form.value.all_day
  return {
    title: form.value.title,
    calendar: form.value.calendar,
    job_id: form.value.job_id === '' ? null : Number(form.value.job_id),
    station: form.value.calendar === 'production' ? form.value.station : null,
    all_day: allDay ? 1 : 0,
    starts_at: allDay ? form.value.starts_date : `${form.value.starts_date}T${form.value.starts_time}`,
    ends_at: form.value.ends_date
      ? (allDay ? form.value.ends_date : `${form.value.ends_date}T${form.value.ends_time}`)
      : null,
    note: form.value.note,
    status: form.value.status,
  }
}

async function save() {
  saving.value = true
  try {
    if (editing.value) await triApi.calendar.update(editing.value.id, payload())
    else await triApi.calendar.create(payload())
    modalOpen.value = false
    toast.success(t('common.saved'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!editing.value) return
  saving.value = true
  try {
    await triApi.calendar.delete(editing.value.id)
    modalOpen.value = false
    toast.success(t('common.deleted'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

function statusActionKey(calendar: TriCalendarKind, status: string) {
  const next = nextStatusOf(calendar, status)
  if (calendar === 'dispatch') return next === 'confirmed' ? 'confirm' : 'unconfirm'
  if (next === 'in_progress') return 'start_progress'
  if (next === 'done') return 'mark_done'
  return null
}

function shift(dir: number) {
  if (view.value === 'month') {
    cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + dir, 1)
  } else {
    cursor.value = addDays(cursor.value, dir * 7)
  }
}

onMounted(async () => {
  await loadJobs()
  await load()
})
watch([view, rangeFrom, rangeTo], () => load())
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.calendar.title')" :subtitle="heading">
      <template #actions>
        <UiButton v-if="auth.canWrite" @click="openCreate(isoDate(cursor))">
          {{ t('tri.calendar.new_event') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <div class="flex flex-wrap items-center gap-2 mb-4">
      <UiButton variant="secondary" @click="cursor = startOfDay(new Date())">{{ t('tri.calendar.today') }}</UiButton>
      <UiButton variant="secondary" aria-label="previous" @click="shift(-1)">←</UiButton>
      <UiButton variant="secondary" aria-label="next" @click="shift(1)">→</UiButton>
      <UiButton :variant="view === 'week' ? 'primary' : 'secondary'" @click="view = 'week'">{{ t('tri.calendar.week') }}</UiButton>
      <UiButton :variant="view === 'month' ? 'primary' : 'secondary'" @click="view = 'month'">{{ t('tri.calendar.month') }}</UiButton>
    </div>
    <div class="flex flex-wrap gap-3 mb-4">
      <button v-for="k in KINDS" :key="k" type="button" class="office-layer" :aria-pressed="layers[k]" @click="layers[k] = !layers[k]">
        {{ layers[k] ? '✓' : '' }} {{ t(`tri.calendar.${k}`) }}
      </button>
    </div>

    <div class="border border-neutral-200 overflow-hidden bg-surface" :class="loading ? 'opacity-60' : ''">
      <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50">
        <div v-for="(day, n) in days.slice(0, 7)" :key="day" class="px-2 py-2 text-xs text-neutral-600">
          {{ t(`tri.calendar.weekday_${n + 1}`) }}<span v-if="view === 'week'"> {{ Number(day.slice(8, 10)) }}.</span>
        </div>
      </div>
      <div v-if="view === 'week' && spanEvents.length" class="border-b border-neutral-200 p-2 grid gap-2">
        <button v-for="ev in spanEvents" :key="ev.id" type="button" :class="eventClass(ev)" @click="openEdit(ev)">
          {{ ev.title }}<span v-if="ev.job_number"> · {{ ev.job_number }}</span>
        </button>
      </div>
      <div class="grid grid-cols-7">
        <div
          v-for="day in (view === 'week' ? days : days)"
          :key="day"
          class="min-h-28 border-r border-b border-neutral-200 p-1.5 text-left"
          :class="view === 'week' ? 'min-h-48' : ''"
          @click="auth.canWrite ? openCreate(day) : undefined"
        >
          <div class="text-xs tabular-nums mb-1" :class="view === 'month' && !inMonth(day) ? 'text-neutral-400' : 'text-neutral-700'">
            {{ view === 'month' ? Number(day.slice(8, 10)) : '' }}
          </div>
          <button
            v-for="ev in eventsOn(day).slice(0, view === 'month' ? 2 : 8)"
            :key="ev.id"
            type="button"
            class="mb-1"
            :class="eventClass(ev)"
            @click.stop="openEdit(ev)"
          >
            {{ ev.title }}
          </button>
          <button
            v-if="view === 'month' && eventsOn(day).length > 2"
            type="button"
            class="text-xs text-primary-700 px-1"
            @click.stop="overflowDay = day"
          >
            {{ t('tri.calendar.more_events', { n: eventsOn(day).length - 2 }) }}
          </button>
        </div>
      </div>
    </div>
    <EmptyState v-if="!loading && events.length === 0" compact :title="t('tri.calendar.empty')" />

    <Modal v-if="chooserOpen" :title="t('tri.calendar.choose_calendar')" width-class="max-w-sm" @close="chooserOpen = false">
      <div class="grid gap-2">
        <UiButton v-for="k in KINDS" :key="k" variant="secondary" @click="pickKind(k)">{{ t(`tri.calendar.${k}`) }}</UiButton>
      </div>
    </Modal>
    <Modal v-if="overflowDay" :title="overflowDay" width-class="max-w-lg" @close="overflowDay = null">
      <p class="text-sm text-neutral-600 mb-3">{{ t('tri.calendar.day_events', { n: overflowEvents.length }) }}</p>
      <div class="grid gap-2">
        <button v-for="ev in overflowEvents" :key="ev.id" type="button" :class="eventClass(ev)" @click="overflowDay = null; openEdit(ev)">
          {{ t(`tri.calendar.${ev.calendar}`) }} · {{ ev.title }}
        </button>
      </div>
    </Modal>
    <Modal
      v-if="modalOpen"
      :title="editing ? t('tri.calendar.edit_event') : t('tri.calendar.new_event')"
      width-class="max-w-lg"
      @close="modalOpen = false"
    >
      <div class="space-y-4">
        <UiInput v-model="form.title" :label="t('tri.calendar.title_label')" :disabled="!auth.canWrite" />
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('tri.calendar.job') }}
          <select v-model="form.job_id" class="mt-1.5 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs" :disabled="!auth.canWrite">
            <option value="">{{ t('tri.calendar.job_none') }}</option>
            <option v-for="j in jobs" :key="j.id" :value="j.id">{{ j.number }} — {{ j.title }}</option>
          </select>
        </label>
        <label v-if="form.calendar === 'production'" class="block text-sm font-medium text-neutral-700">
          {{ t('tri.calendar.station') }}
          <select v-model="form.station" class="mt-1.5 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface shadow-xs" :disabled="!auth.canWrite">
            <option v-for="s in STATIONS" :key="s" :value="s">{{ t(`tri.calendar.station_${s}`) }}</option>
          </select>
        </label>
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.all_day" type="checkbox" class="rounded border-neutral-300" :disabled="!auth.canWrite">
          {{ t('tri.calendar.all_day') }}
        </label>
        <div class="grid grid-cols-2 gap-3">
          <UiInput v-model="form.starts_date" type="date" :label="t('tri.calendar.starts')" :disabled="!auth.canWrite" />
          <UiInput v-if="!form.all_day" v-model="form.starts_time" type="time" :label="' '" :disabled="!auth.canWrite" />
          <UiInput v-model="form.ends_date" type="date" :label="t('tri.calendar.ends')" :disabled="!auth.canWrite" />
          <UiInput v-if="!form.all_day" v-model="form.ends_time" type="time" :label="' '" :disabled="!auth.canWrite" />
        </div>
        <UiInput v-model="form.note" :label="t('tri.calendar.note')" :disabled="!auth.canWrite" />
        <div v-if="form.calendar !== 'shifts'" class="flex flex-wrap items-center gap-2">
          <span class="text-sm font-medium text-neutral-700">{{ t('tri.calendar.status') }}</span>
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="eventClass({ calendar: form.calendar })">
            {{ t(`tri.calendar.status_${form.status}`) }}
          </span>
          <UiButton
            v-if="auth.canWrite && nextStatusOf(form.calendar, form.status)"
            variant="outline"
            size="sm"
            @click="form.status = nextStatusOf(form.calendar, form.status) ?? form.status"
          >
            {{ t(`tri.calendar.${statusActionKey(form.calendar, form.status)}`) }}
          </UiButton>
        </div>
        <div v-if="editing?.job_id" class="text-sm">
          <RouterLink class="text-primary-700 font-medium hover:underline" :to="{ name: 'tri-job-detail', params: { id: editing.job_id } }">
            {{ t('tri.calendar.open_job') }} {{ editing.job_number }}
          </RouterLink>
        </div>
        <div v-if="auth.canWrite" class="flex justify-end gap-2 pt-2">
          <UiButton v-if="editing" variant="danger" size="sm" :loading="saving" @click="remove">{{ t('tri.calendar.delete') }}</UiButton>
          <UiButton size="sm" :loading="saving" @click="save">{{ t('common.save') }}</UiButton>
        </div>
      </div>
    </Modal>
  </div>
</template>

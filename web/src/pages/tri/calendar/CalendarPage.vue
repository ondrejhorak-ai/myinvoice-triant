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

function eventClass(ev: { calendar: TriCalendarKind; status: string }) {
  if (ev.calendar === 'dispatch') {
    return ev.status === 'confirmed'
      ? 'bg-amber-100 text-amber-900 border border-amber-300'
      : 'bg-transparent text-amber-900 border border-dashed border-amber-400'
  }
  if (ev.calendar === 'production') {
    if (ev.status === 'done') return 'bg-neutral-100 text-neutral-500 border border-neutral-200'
    if (ev.status === 'in_progress') return 'bg-emerald-100 text-emerald-800 border border-emerald-400 ring-1 ring-emerald-500'
    return 'bg-transparent text-emerald-800 border border-dashed border-emerald-400'
  }
  return 'bg-primary-100 text-primary-800 border border-primary-200'
}

const kind = ref<TriCalendarKind>('production')
const view = ref<'month' | 'week'>('month')
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

function eventsOn(day: string) {
  return events.value.filter((ev) => {
    const start = ev.starts_at.slice(0, 10)
    const end = (ev.ends_at ?? ev.starts_at).slice(0, 10)
    return start <= day && end >= day
  })
}

async function load() {
  loading.value = true
  try {
    const r = await triApi.calendar.list({ calendar: kind.value, from: rangeFrom.value, to: rangeTo.value })
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
  editing.value = null
  form.value = blankForm(kind.value, day)
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
watch([kind, view, rangeFrom, rangeTo], () => load())
</script>

<template>
  <div>
    <UiPageHeader :title="t('tri.calendar.title')" :subtitle="t('tri.calendar.subtitle')">
      <template #actions>
        <UiButton v-if="auth.canWrite" size="sm" @click="openCreate(isoDate(cursor))">
          {{ t('tri.calendar.new_event') }}
        </UiButton>
      </template>
    </UiPageHeader>

    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center mb-4">
      <div class="inline-flex flex-wrap rounded-md border border-neutral-200 p-0.5 bg-surface">
        <button
          v-for="k in KINDS"
          :key="k"
          type="button"
          class="h-9 px-3 text-sm font-semibold rounded-md"
          :class="kind === k ? 'bg-primary-50 text-primary-800' : 'text-neutral-600 hover:bg-neutral-50'"
          @click="kind = k"
        >
          {{ t(`tri.calendar.${k}`) }}
        </button>
      </div>
      <div class="inline-flex rounded-md border border-neutral-200 p-0.5 bg-surface">
        <button type="button" class="h-9 px-3 text-sm font-semibold rounded-md" :class="view === 'month' ? 'bg-neutral-100' : 'text-neutral-600'" @click="view = 'month'">{{ t('tri.calendar.month') }}</button>
        <button type="button" class="h-9 px-3 text-sm font-semibold rounded-md" :class="view === 'week' ? 'bg-neutral-100' : 'text-neutral-600'" @click="view = 'week'">{{ t('tri.calendar.week') }}</button>
      </div>
      <div class="flex items-center gap-1 sm:ml-auto">
        <UiButton variant="ghost" size="sm" @click="shift(-1)">←</UiButton>
        <UiButton variant="outline" size="sm" @click="cursor = startOfDay(new Date())">{{ t('tri.calendar.today') }}</UiButton>
        <UiButton variant="ghost" size="sm" @click="shift(1)">→</UiButton>
      </div>
    </div>

    <p class="text-sm font-medium text-neutral-700 mb-2 capitalize">{{ heading }}</p>

    <div class="border border-neutral-200 rounded-lg overflow-hidden bg-surface shadow-xs">
      <div class="grid grid-cols-7 border-b border-neutral-200 bg-neutral-50">
        <div v-for="n in 7" :key="n" class="px-0.5 sm:px-2 py-2 text-[10px] sm:text-xs font-semibold uppercase tracking-wider text-neutral-400 text-center truncate">
          {{ t(`tri.calendar.weekday_${n}`) }}
        </div>
      </div>
      <div class="grid grid-cols-7" :class="loading ? 'opacity-60' : ''">
        <div
          v-for="day in days"
          :key="day"
          class="min-h-[4.5rem] sm:min-h-[6.5rem] border-r border-b border-neutral-100 p-1 sm:p-1.5 text-left align-top hover:bg-neutral-50 cursor-pointer"
          :class="view === 'week' ? 'min-h-[10rem] sm:min-h-[14rem]' : ''"
          @click="auth.canWrite ? openCreate(day) : undefined"
        >
          <div class="text-xs tabular-nums mb-1" :class="inMonth(day) ? 'text-neutral-700 font-medium' : 'text-neutral-300'">
            {{ Number(day.slice(8, 10)) }}
          </div>
          <div
            v-for="ev in eventsOn(day)"
            :key="ev.id"
            class="mb-1 w-full truncate rounded-md px-1.5 py-0.5 text-xs font-medium"
            :class="eventClass(ev)"
            :title="`${ev.title} — ${t(`tri.calendar.status_${ev.status}`)}`"
            @click.stop="openEdit(ev)"
          >
            {{ ev.title }}
          </div>
        </div>
      </div>
    </div>
    <p v-if="!loading && events.length === 0" class="mt-3 text-sm text-neutral-500">{{ t('tri.calendar.empty') }}</p>

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
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="eventClass({ calendar: form.calendar, status: form.status })">
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

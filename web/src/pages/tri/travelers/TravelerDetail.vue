<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { triApi, type TriTraveler } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiCard from '@/components/ui/UiCard.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiTable from '@/components/ui/UiTable.vue'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const id = computed(() => Number(route.params.id))
const traveler = ref<TriTraveler | null>(null)
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    traveler.value = await triApi.travelers.get(id.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

onMounted(() => load())

function openPdf() {
  if (!traveler.value) return
  window.open(triApi.travelers.pdfUrl(traveler.value.id, false), '_blank')
}
</script>

<template>
  <div>
    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <template v-else-if="traveler">
      <UiPageHeader
        :title="traveler.number"
        :subtitle="`${traveler.job_number} — ${traveler.job_title}`"
      >
        <template #actions>
          <UiBadge :variant="traveler.status === 'done' ? 'success' : 'primary'">
            {{ t(`tri.travelers.status_${traveler.status}`) }}
          </UiBadge>
          <UiButton variant="outline" size="sm" @click="openPdf">
            {{ t('tri.travelers.download_pdf') }}
          </UiButton>
          <UiButton :to="{ name: 'tri-job-detail', params: { id: traveler.job_id } }" variant="ghost" size="sm">
            {{ t('tri.travelers.back_to_job') }}
          </UiButton>
        </template>
      </UiPageHeader>

      <div class="grid gap-4 lg:grid-cols-2">
        <UiCard>
          <div class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.item') }}</h3>
          </div>
          <dl class="p-5 space-y-3 text-sm">
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.designation') }}</dt>
              <dd class="mt-1 font-mono">{{ traveler.designation || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.item_title') }}</dt>
              <dd class="mt-1 font-medium text-neutral-900">{{ traveler.title }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.description') }}</dt>
              <dd class="mt-1 whitespace-pre-wrap text-neutral-700">{{ traveler.description || '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.quantity') }}</dt>
              <dd class="mt-1 tabular-nums">{{ traveler.quantity }} {{ traveler.unit }}</dd>
            </div>
          </dl>
        </UiCard>

        <UiCard>
          <div class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-neutral-400">{{ t('tri.travelers.stations') }}</h3>
          </div>
          <UiTable>
            <template #head>
              <tr>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('tri.travelers.station') }}</th>
                <th class="text-right px-4 py-2.5 font-medium">{{ t('tri.travelers.hours') }}</th>
              </tr>
            </template>
            <tr v-for="op in traveler.operations ?? []" :key="op.station">
              <td class="px-4 py-3">{{ t(`tri.travelers.station_${op.station}`) }}</td>
              <td class="px-4 py-3 text-right tabular-nums font-mono">{{ op.hours ?? '—' }}</td>
            </tr>
          </UiTable>
        </UiCard>
      </div>
    </template>
  </div>
</template>

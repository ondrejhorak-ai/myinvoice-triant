<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { triApi, type TriJob } from '@/api/tri'
import { apiErrorMessage } from '@/api/errors'
import UiPageHeader from '@/components/ui/UiPageHeader.vue'
import UiTable from '@/components/ui/UiTable.vue'
const { t } = useI18n(),
  counts = ref<Record<string, number>>({}),
  recent = ref<TriJob[]>([]),
  error = ref('')
const statuses = ['active', 'confirmed', 'completed', 'rejected']
onMounted(async () => {
  try {
    const results = await Promise.all(
      statuses.map((status) => triApi.jobs.list({ status, per_page: 1 })),
    )
    statuses.forEach((s, i) => (counts.value[s] = results[i]!.meta.total))
    recent.value = (await triApi.jobs.list({ per_page: 8 })).data
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  }
})
</script>
<template>
  <div class="space-y-6">
    <UiPageHeader
      :title="t('office.overview')"
      :subtitle="t('office.overview_hint')"
    />
    <p v-if="error" role="alert">{{ error }}</p>
    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-6">
      <RouterLink
        v-for="s in statuses"
        :key="s"
        to="/tri/jobs"
        class="bg-surface border border-neutral-200 p-5"
        ><p class="text-neutral-500 text-sm">{{ t(`tri.jobs.status_${s}`) }}</p>
        <p class="text-3xl font-semibold mt-3">
          {{ counts[s] ?? '—' }}
        </p></RouterLink
      >
    </div>
    <section class="bg-surface border border-neutral-200">
      <h2 class="p-5">{{ t('office.recent_jobs') }}</h2>
      <UiTable
        ><template #head
          ><tr>
            <th>{{ t('tri.jobs.number') }}</th>
            <th>{{ t('common.name') }}</th>
            <th>{{ t('tri.jobs.customer') }}</th>
          </tr></template
        >
        <tr v-for="j in recent" :key="j.id">
          <td>
            <RouterLink class="text-primary-600" :to="`/tri/jobs/${j.id}`">{{
              j.number
            }}</RouterLink>
          </td>
          <td>{{ j.title }}</td>
          <td>{{ j.customer_name || '—' }}</td>
        </tr></UiTable
      >
    </section>
  </div>
</template>

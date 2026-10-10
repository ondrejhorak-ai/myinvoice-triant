<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
</script>
<template>
  <div class="offer-row offer-table-head">
    <span /><span>{{ t('tri.quote.designation') }}</span
    ><span>{{ t('tri.quote.title') }}</span
    ><span>{{ t('tri.quote.quantity') }}</span
    ><span>{{ t('office.unit') }}</span
    ><span class="text-right">{{ t('tri.quote.unit_price') }}</span
    ><span>{{ t('tri.quote.vat_rate') }}</span
    ><span>{{ t('tri.quote.is_manufactured') }}</span
    ><span class="text-right">{{ t('office.total_net') }}</span
    ><span />
  </div>
</template>

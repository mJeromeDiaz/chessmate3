<template>
  <q-markup-table flat bordered dense data-testid="run-table">
    <thead>
      <tr>
        <th class="text-left">Séance</th>
        <th class="text-right">Durée</th>
        <th class="text-right">Terminés</th>
        <th class="text-right">Réussis</th>
        <th class="text-right">Précision</th>
        <th class="text-right">Par minute</th>
        <th class="text-right">Évolution</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in rows" :key="row.id">
        <td>{{ formatDate(row.startedAt) }}</td>
        <td class="text-right">{{
          formatDuration(row.summary?.durationMs)
        }}</td>
        <td class="text-right">{{ row.summary?.itemCount ?? '—' }}</td>
        <td class="text-right">{{ row.summary?.successCount ?? '—' }}</td>
        <td class="text-right">{{
          formatPercent(row.summary?.successRate)
        }}</td>
        <td class="text-right">{{ perMinute(row.summary?.itemsPerMinute) }}</td>
        <td
          class="text-right"
          :class="
            row.trend > 0
              ? 'text-positive'
              : row.trend < 0
                ? 'text-negative'
                : ''
          "
        >
          {{
            row.trend === null
              ? '—'
              : `${row.trend > 0 ? '+' : ''}${perMinute(row.trend)}`
          }}
        </td>
      </tr>
      <tr v-if="rows.length === 0">
        <td colspan="7" class="text-grey">Aucune séance pour l’instant.</td>
      </tr>
    </tbody>
  </q-markup-table>
</template>

<script setup>
/**
 * The closed runs of a subject, newest first, with the change in items per minute since the
 * previous run (budgets vary: per minute is what compares).
 */
import { computed } from 'vue'
import { formatDate, formatDuration, formatPercent } from '@/utils/format'
import { runTrends } from '@/utils/training'

const props = defineProps({
  /** @type {import('vue').PropType<{id: string, startedAt: string, summary: import('@/composables/training/useTimeboxedRun').RunSummary|null}[]>} newest first */
  runs: { type: Array, required: true }
})

const rows = computed(() => runTrends(props.runs))

/** @param {number|null|undefined} value */
function perMinute(value) {
  return value === null || value === undefined
    ? '—'
    : value.toLocaleString('fr-FR', { maximumFractionDigits: 2 })
}
</script>

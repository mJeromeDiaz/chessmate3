<template>
  <q-card flat bordered data-testid="run-recap">
    <q-card-section>
      <div class="text-h6">Récapitulatif de la séance</div>
      <div class="text-body2" data-testid="run-close-reason">{{
        closeReasonText(run)
      }}</div>
    </q-card-section>
    <q-card-section v-if="summary" class="row q-col-gutter-md">
      <div v-for="stat in stats" :key="stat.label" class="col-6 col-sm-4">
        <div class="text-caption text-grey">{{ stat.label }}</div>
        <div class="text-h6" :data-testid="stat.testid">{{ stat.value }}</div>
      </div>
    </q-card-section>
    <q-card-section v-if="details.length" class="text-caption text-grey">
      <div v-for="line in details" :key="line">{{ line }}</div>
    </q-card-section>
    <q-card-actions>
      <slot name="actions" />
    </q-card-actions>
  </q-card>
</template>

<script setup>
/**
 * The normalized recap of a closed run (same for every module), plus the details a module adds
 * in `summary.metrics`.
 */
import { computed } from 'vue'
import { formatDuration, formatPercent } from '@/utils/format'
import { closeReasonText } from '@/utils/training'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').TrainingRun>} */
  run: { type: Object, required: true }
})

const summary = computed(() => props.run.summary)

const stats = computed(() => {
  const s = summary.value
  if (!s) return []
  return [
    {
      label: 'Durée',
      value: formatDuration(s.durationMs),
      testid: 'run-duration'
    },
    { label: 'Terminés', value: String(s.itemCount), testid: 'run-items' },
    {
      label: 'Réussis',
      value: String(s.successCount),
      testid: 'run-successes'
    },
    { label: 'Échoués', value: String(s.failureCount), testid: 'run-failures' },
    {
      label: 'Précision',
      value: formatPercent(s.successRate),
      testid: 'run-accuracy'
    },
    {
      label: 'Par minute',
      value:
        s.itemsPerMinute === null
          ? '—'
          : s.itemsPerMinute.toLocaleString('fr-FR'),
      testid: 'run-per-minute'
    }
  ]
})

/** Module-specific lines (Woodpecker today). */
const details = computed(() => {
  const m = summary.value?.metrics ?? {}
  const lines = []
  if (m.averageMs)
    lines.push(`Temps moyen par puzzle : ${formatDuration(m.averageMs)}`)
  if (m.added > 0)
    lines.push(
      `Le set a grandi de ${m.added} puzzles (${m.puzzleCount} au total).`
    )
  if (m.cycle)
    lines.push(
      `Cycle ${m.cycle.number} : ${m.cycle.played} / ${m.puzzleCount} puzzles.`
    )
  return lines
})
</script>

<template>
  <q-card flat bordered data-testid="cycle-recap">
    <q-card-section>
      <div class="text-h6">
        {{
          setCompleted ? 'Set terminé !' : `Cycle ${completed.number} terminé`
        }}
      </div>
      <div class="q-mt-sm">
        <div
          >Précision : {{ percent(completed.accuracy)
          }}<span v-if="previous">
            ({{ compare(completed.accuracy, previous.accuracy, true) }})</span
          ></div
        >
        <div
          >Temps actif : {{ formatDuration(completed.activeMs)
          }}<span v-if="previous">
            ({{ compareTime(completed.activeMs, previous.activeMs) }})</span
          ></div
        >
        <div
          >Temps moyen par puzzle :
          {{ formatDuration(completed.averageMs) }}</div
        >
        <div>Durée calendaire : {{ days(completed.calendarMs) }}</div>
      </div>
    </q-card-section>
  </q-card>
</template>

<script setup>
import { formatDuration } from '@/utils/format'

defineProps({
  /** @type {import('vue').PropType<import('@/stores/woodpecker').CycleView>} */
  completed: { type: Object, required: true },
  /** @type {import('vue').PropType<import('@/stores/woodpecker').CycleView|null>} The previous cycle, for the comparison. */
  previous: { type: Object, default: null },
  setCompleted: { type: Boolean, default: false }
})

/** @param {number|null} ratio */
const percent = ratio => (ratio === null ? '—' : `${Math.round(ratio * 100)} %`)

/** @param {number|null} ms */
const days = ms =>
  ms === null ? '—' : `${(ms / 86_400_000).toFixed(1)} jour(s)`

/**
 * @param {number|null} now
 * @param {number|null} before
 */
function compare(now, before) {
  if (now === null || before === null) return 'pas de comparaison'
  const diff = Math.round((now - before) * 100)
  return `${diff >= 0 ? '+' : '−'}${Math.abs(diff)} points vs cycle précédent`
}

/**
 * @param {number} now
 * @param {number} before
 */
function compareTime(now, before) {
  if (!before) return 'pas de comparaison'
  const ratio = now / before
  return `${Math.round(ratio * 100)} % du cycle précédent`
}
</script>

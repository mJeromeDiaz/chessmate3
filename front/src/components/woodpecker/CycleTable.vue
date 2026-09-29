<template>
  <q-markup-table flat bordered dense data-testid="cycle-table">
    <thead>
      <tr>
        <th class="text-left">Cycle</th>
        <th class="text-left">Statut</th>
        <th class="text-right">Joués</th>
        <th class="text-right">Précision</th>
        <th class="text-right">Temps actif</th>
        <th class="text-right">Moyenne</th>
        <th class="text-right">Durée</th>
        <th class="text-left">Échéance</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="cycle in cycles" :key="`${cycle.number}-${cycle.run}`">
        <td
          >{{ cycle.number
          }}<span v-if="cycle.run > 1" class="text-grey">
            (essai {{ cycle.run }})</span
          ></td
        >
        <td>
          <q-badge :color="STATUS[cycle.status].color">{{
            STATUS[cycle.status].label
          }}</q-badge>
        </td>
        <td class="text-right">{{ cycle.played }} / {{ puzzleCount }}</td>
        <td class="text-right">{{ formatPercent(cycle.accuracy) }}</td>
        <td class="text-right">{{ formatDuration(cycle.activeMs) }}</td>
        <td class="text-right">{{ formatDuration(cycle.averageMs) }}</td>
        <td class="text-right">{{ formatDays(cycle.calendarMs) }}</td>
        <td>
          <span v-if="cycle.onTime === true" class="text-positive"
            >respectée</span
          >
          <span v-else-if="cycle.onTime === false" class="text-negative"
            >dépassée</span
          >
          <span v-else>{{ formatDate(cycle.deadlineAt) }}</span>
        </td>
      </tr>
    </tbody>
  </q-markup-table>
</template>

<script setup>
import { formatDate, formatDuration } from '@/utils/format'

defineProps({
  /** @type {import('vue').PropType<import('@/stores/woodpecker').CycleView[]>} */
  cycles: { type: Array, required: true },
  puzzleCount: { type: Number, required: true }
})

const STATUS = {
  resting: { label: 'Repos', color: 'grey' },
  active: { label: 'En cours', color: 'primary' },
  completed: { label: 'Terminé', color: 'positive' },
  lost: { label: 'Perdu', color: 'negative' }
}

/** @param {number|null} ratio */
function formatPercent(ratio) {
  return ratio === null ? '—' : `${Math.round(ratio * 100)} %`
}

/** @param {number|null} ms */
function formatDays(ms) {
  if (ms === null) return '—'
  const days = ms / 86_400_000
  return days < 1 ? formatDuration(ms) : `${days.toFixed(1)} j`
}
</script>

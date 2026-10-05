<template>
  <section class="cm-card heatmap" data-testid="activity-heatmap">
    <div class="heatmap__head">
      <h2 class="cm-card__title">Régularité</h2>
      <span
        v-if="bestStreak !== null"
        class="heatmap__record"
        data-testid="best-streak"
        >Record {{ bestStreak }} jour{{ bestStreak > 1 ? 's' : '' }}</span
      >
    </div>
    <div class="heatmap__grid" role="img" :aria-label="summary">
      <div
        v-for="cell in cells"
        :key="cell.date"
        class="heatmap__cell"
        :class="{
          'heatmap__cell--today': cell.today,
          'heatmap__cell--future': cell.future
        }"
        :style="{
          background: cell.future ? 'transparent' : SHADES[cell.level]
        }"
        :data-level="cell.level"
        :data-date="cell.date"
      >
        <q-tooltip v-if="!cell.future">{{ tooltip(cell) }}</q-tooltip>
      </div>
    </div>
    <div class="heatmap__foot">
      <span
        >{{ weeks }} dernières semaines · {{ activeDays }} jours actifs</span
      >
      <span class="heatmap__scale"
        >Moins<span
          v-for="(shade, i) in SHADES"
          :key="i"
          :style="{ background: shade }"
        />Plus</span
      >
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { buildHeatmap } from '@/utils/dashboard/heatmap'

const props = defineProps({
  /** Y-m-d, the user's local today (from the API). */
  today: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/dashboard/heatmap').ActivityDay[]>} */
  days: { type: Array, required: true },
  weeks: { type: Number, default: 12 },
  /** The longest streak of active days (docs/GAMIFICATION.md), null while unknown. */
  bestStreak: { type: Number, default: null }
})

const SHADES = [0, 1, 2, 3, 4].map(level => `var(--cm-heat-${level})`)

const cells = computed(() => buildHeatmap(props.today, props.days, props.weeks))
const activeDays = computed(() => cells.value.filter(c => c.count > 0).length)
const summary = computed(
  () =>
    `${activeDays.value} jours actifs sur les ${props.weeks} dernières semaines`
)

const dateFormat = new Intl.DateTimeFormat('fr-FR', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  timeZone: 'UTC'
})

/**
 * @param {import('@/utils/dashboard/heatmap').HeatCell} cell
 * @returns {string}
 */
function tooltip(cell) {
  const day = dateFormat.format(new Date(`${cell.date}T00:00:00Z`))
  if (!cell.count) return `${day} : aucun exercice`
  return `${day} : ${cell.count} exercice${cell.count > 1 ? 's' : ''}`
}
</script>

<style scoped lang="scss">
.heatmap {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.heatmap__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.heatmap__record {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
}

.heatmap__grid {
  display: grid;
  grid-template-rows: repeat(7, 1fr);
  grid-auto-flow: column;
  grid-auto-columns: minmax(0, 1fr);
  gap: 3px;
}

.heatmap__cell {
  aspect-ratio: 1;
  border-radius: 3px;

  &--today {
    box-shadow: 0 0 0 2px var(--cm-ink);
  }
}

.heatmap__foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  font-size: 11.5px;
  color: var(--cm-muted);
}

.heatmap__scale {
  display: flex;
  align-items: center;
  gap: 3px;

  span {
    width: 10px;
    height: 10px;
    border-radius: 3px;
  }
}
</style>

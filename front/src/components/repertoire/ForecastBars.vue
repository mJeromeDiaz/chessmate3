<template>
  <div class="forecast" data-testid="forecast">
    <div v-for="(day, i) in days" :key="day.date" class="forecast__day">
      <div class="forecast__count">{{ day.due }}</div>
      <div class="forecast__track">
        <div
          class="forecast__bar"
          :class="{ 'forecast__bar--today': i === 0 }"
          :style="{ height: `${(day.due / max) * 100}%` }"
        />
      </div>
      <div class="forecast__date">{{
        i === 0 ? 'Auj.' : dayName(day.date)
      }}</div>
    </div>
  </div>
</template>

<script setup>
/**
 * Cards falling due over the next local days (today includes the overdue ones), as bars.
 */
import { computed } from 'vue'

const props = defineProps({
  /** @type {import('vue').PropType<{date: string, due: number}[]>} */
  days: { type: Array, required: true }
})

const max = computed(() => Math.max(1, ...props.days.map(d => d.due)))

/** @param {string} date YYYY-MM-DD (a local day of the user) */
function dayName(date) {
  const [y, m, d] = date.split('-').map(Number)
  return new Intl.DateTimeFormat('fr-FR', { weekday: 'short' }).format(
    new Date(y, m - 1, d)
  )
}
</script>

<style scoped>
.forecast {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 8px;
  max-width: 420px;
}
.forecast__day {
  text-align: center;
  font-size: 12px;
}
.forecast__track {
  height: 64px;
  display: flex;
  align-items: flex-end;
  background: #f5f5f5;
  border-radius: 4px;
}
.forecast__bar {
  width: 100%;
  background: #90caf9;
  border-radius: 4px;
}
.forecast__bar--today {
  background: #1976d2;
}
.forecast__date {
  color: #757575;
}
</style>

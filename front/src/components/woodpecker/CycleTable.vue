<template>
  <div class="cycle-list" data-testid="cycle-table">
    <div
      v-for="cycle in cycles"
      :key="`${cycle.number}-${cycle.run}`"
      class="cycle-row"
      :class="`cycle-row--${cycle.status}`"
      data-testid="cycle-row"
    >
      <div class="cycle-row__head">
        <div class="cycle-row__name">
          Cycle {{ cycle.number
          }}<span v-if="cycle.run > 1" class="cycle-row__run">
            · essai {{ cycle.run }}</span
          >
        </div>
        <span
          class="cycle-row__status"
          :style="{
            background: STATUS[cycle.status].bg,
            color: STATUS[cycle.status].ink
          }"
          >{{ STATUS[cycle.status].label }}</span
        >
      </div>

      <div class="cycle-row__bar">
        <div
          class="cycle-row__fill"
          :style="{
            width: `${Math.min(1, cycle.played / puzzleCount) * 100}%`,
            background: STATUS[cycle.status].bar
          }"
        />
      </div>

      <div class="cycle-row__stats">
        <div class="cycle-row__stat">
          <div class="cycle-row__value"
            >{{ cycle.played }} / {{ puzzleCount }}</div
          >
          <div class="cycle-row__label">joués</div>
        </div>
        <div class="cycle-row__stat">
          <div class="cycle-row__value">{{
            formatPercent(cycle.accuracy)
          }}</div>
          <div class="cycle-row__label">précision</div>
        </div>
        <div class="cycle-row__stat">
          <div class="cycle-row__value">{{
            formatDuration(cycle.averageMs)
          }}</div>
          <div class="cycle-row__label">par puzzle</div>
        </div>
        <div class="cycle-row__stat">
          <div class="cycle-row__value">{{
            formatDuration(cycle.activeMs)
          }}</div>
          <div class="cycle-row__label">temps actif</div>
        </div>
        <div class="cycle-row__stat">
          <div class="cycle-row__value">{{ formatDays(cycle.calendarMs) }}</div>
          <div class="cycle-row__label">durée</div>
        </div>
        <div class="cycle-row__stat">
          <div
            class="cycle-row__value"
            :class="{
              'text-positive': cycle.onTime === true,
              'text-negative': cycle.onTime === false
            }"
            >{{ deadline(cycle) }}</div
          >
          <div class="cycle-row__label">échéance</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Every cycle run of a classic set (or every round of a light one), newest last: status,
 * progress bar, accuracy, average time per puzzle, active and calendar time, deadline.
 */
import { formatDuration, formatPercent } from '@/utils/format'
import { deadlineDay } from '@/utils/woodpecker'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/stores/woodpecker').CycleView[]>} */
  cycles: { type: Array, required: true },
  puzzleCount: { type: Number, required: true },
  /** The user's timezone: deadlines are local day ends. */
  timeZone: { type: String, default: 'UTC' }
})

const STATUS = {
  resting: {
    label: 'Repos',
    bg: 'var(--cm-subtle)',
    ink: 'var(--cm-ink-soft)',
    bar: 'var(--cm-faint)'
  },
  active: {
    label: 'En cours',
    bg: 'var(--cm-brand-soft)',
    ink: 'var(--cm-brand-deep)',
    bar: 'var(--cm-brand-deep)'
  },
  completed: {
    label: 'Terminé',
    bg: 'var(--cm-success-soft)',
    ink: 'var(--cm-success)',
    bar: 'var(--cm-success)'
  },
  lost: {
    label: 'Perdu',
    bg: 'var(--cm-danger-soft)',
    ink: 'var(--cm-danger)',
    bar: 'var(--cm-danger)'
  }
}

/** @param {number|null} ms */
function formatDays(ms) {
  if (ms === null) return '—'
  const days = ms / 86_400_000
  return days < 1 ? formatDuration(ms) : `${days.toFixed(1)} j`
}

/** @param {import('@/stores/woodpecker').CycleView} cycle */
function deadline(cycle) {
  if (cycle.onTime === true) return 'respectée'
  if (cycle.onTime === false) return 'dépassée'
  return cycle.deadlineAt ? deadlineDay(cycle.deadlineAt, props.timeZone) : '—'
}
</script>

<style scoped lang="scss">
.cycle-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.cycle-row {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 16px;
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);

  &--active {
    border: 2px solid var(--cm-brand);
  }
}

.cycle-row__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.cycle-row__name {
  font-size: 17px;
  font-weight: 700;
}

.cycle-row__run {
  font-weight: 500;
  color: var(--cm-muted);
}

.cycle-row__status {
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
}

.cycle-row__bar {
  height: 10px;
  border-radius: 999px;
  background: var(--cm-subtle);
  overflow: hidden;
}

.cycle-row__fill {
  height: 100%;
  border-radius: 999px;
}

.cycle-row__stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 12px;
}

.cycle-row__value {
  font-size: 16px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.cycle-row__label {
  font-size: 13px;
  color: var(--cm-muted);
}

@media (min-width: 1024px) {
  .cycle-row {
    padding: 18px 22px;
  }

  .cycle-row__name {
    font-size: 19px;
  }

  .cycle-row__bar {
    height: 14px;
  }

  .cycle-row__stats {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }

  .cycle-row__value {
    font-size: 18px;
  }

  .cycle-row__label {
    font-size: 14px;
  }
}
</style>

<template>
  <section class="cm-card time-chart" data-testid="training-time">
    <div class="time-chart__head">
      <h2 class="cm-card__title">Temps d’entraînement</h2>
      <span class="time-chart__total" data-testid="training-total">{{
        formatHours(total)
      }}</span>
    </div>

    <!-- Legend, direct-labelled with each module's total: identity never rests on colour alone. -->
    <ul class="time-chart__legend" data-testid="training-legend">
      <li
        v-for="m in CHART_MODULES"
        :key="m.id"
        :data-testid="`training-module-${m.id}`"
      >
        <i :style="{ background: `var(--cm-chart-${m.id})` }" />
        <span>{{ m.label }}</span>
        <strong>{{
          formatHours(training.totals[m.id]?.durationMs ?? 0)
        }}</strong>
      </li>
    </ul>

    <p v-if="total === 0" class="cm-muted q-my-md" data-testid="training-empty">
      Aucun exercice sur la période.
    </p>

    <template v-else-if="!table">
      <div class="time-chart__plot" @mouseleave="hovered = null">
        <div class="time-chart__grid" aria-hidden="true">
          <div
            v-for="line in chart.gridlines"
            :key="line.ms"
            class="time-chart__gridline"
            :style="{ bottom: `${line.bottom}%` }"
          >
            <span>{{ line.label }}</span>
          </div>
        </div>
        <div
          class="time-chart__bars"
          :class="{ 'time-chart__bars--dense': chart.bars.length > 20 }"
        >
          <button
            v-for="(bar, i) in chart.bars"
            :key="bar.start"
            type="button"
            class="time-chart__bar"
            :aria-label="barLabel(bar)"
            data-testid="training-bar"
            @mouseenter="hovered = i"
            @focus="hovered = i"
            @blur="hovered = null"
            @click="hovered = hovered === i ? null : i"
          >
            <span
              v-for="segment in bar.segments"
              :key="segment.module"
              class="time-chart__segment"
              :style="{
                height: `${segment.height}%`,
                background: `var(--cm-chart-${segment.module})`
              }"
            />
          </button>
        </div>

        <div
          v-if="tip"
          class="time-chart__tip"
          role="status"
          :style="{ left: `${tip.left}%` }"
          data-testid="training-tip"
        >
          <div class="time-chart__tip-title"
            >Semaine du {{ tip.bar.label }}</div
          >
          <div
            v-for="segment in [...tip.bar.segments].reverse()"
            :key="segment.module"
            class="time-chart__tip-row"
          >
            <i :style="{ background: `var(--cm-chart-${segment.module})` }" />
            <span>{{ moduleLabel(segment.module) }}</span>
            <strong>{{ formatHours(segment.ms) }}</strong>
          </div>
          <div class="time-chart__tip-row time-chart__tip-total">
            <span>Total</span>
            <strong>{{ formatHours(tip.bar.total) }}</strong>
          </div>
        </div>
      </div>
      <div
        class="time-chart__axis"
        :class="{ 'time-chart__axis--dense': chart.bars.length > 20 }"
        aria-hidden="true"
      >
        <span
          v-for="(bar, i) in chart.bars"
          :key="bar.start"
          :style="{ visibility: labelled.has(i) ? 'visible' : 'hidden' }"
          >{{ bar.label }}</span
        >
      </div>
    </template>

    <div v-else class="time-chart__table-wrap">
      <table class="time-chart__table" data-testid="training-table">
        <thead>
          <tr>
            <th scope="col">Semaine du</th>
            <th v-for="m in CHART_MODULES" :key="m.id" scope="col">{{
              m.label
            }}</th>
            <th scope="col">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="bar in chart.bars" :key="bar.start">
            <th scope="row">{{ bar.label }}</th>
            <td v-for="m in CHART_MODULES" :key="m.id">{{
              formatHours(weekMs(bar, m.id))
            }}</td>
            <td>{{ formatHours(bar.total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <q-btn
      v-if="total > 0"
      flat
      dense
      no-caps
      color="primary"
      class="q-mt-sm"
      :label="table ? 'Voir le graphique' : 'Voir le tableau'"
      data-testid="training-table-toggle"
      @click="table = !table"
    />
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import {
  CHART_MODULES,
  formatHours,
  labelledBars,
  weekChart
} from '@/utils/dashboard/stats'

/**
 * Training time by week (statistics page, docs/DASHBOARD.md): one bar per local week, stacked by
 * module in the fixed order of their colours, a tooltip per bar (hover, focus or tap), the totals
 * in the legend and a table view of the same figures.
 */
const props = defineProps({
  /** @type {import('vue').PropType<import('@/stores/dashboard').Training>} */
  training: { type: Object, required: true }
})

const table = ref(false)
/** @type {import('vue').Ref<number|null>} */
const hovered = ref(null)

const chart = computed(() => weekChart(props.training.weeks))
const labelled = computed(() => labelledBars(chart.value.bars.length))
const total = computed(() =>
  CHART_MODULES.reduce(
    (sum, m) => sum + (props.training.totals[m.id]?.durationMs ?? 0),
    0
  )
)

/** The hovered bar and where its tooltip goes (kept inside the card). */
const tip = computed(() => {
  const i = hovered.value
  const bar = i === null ? null : chart.value.bars[i]
  if (!bar || bar.total === 0) return null
  const center = ((i + 0.5) / chart.value.bars.length) * 100
  return { bar, left: Math.min(80, Math.max(20, center)) }
})

watch(
  () => props.training,
  () => (hovered.value = null)
)

/** @param {string} id */
function moduleLabel(id) {
  return CHART_MODULES.find(m => m.id === id)?.label ?? id
}

/**
 * @param {import('@/utils/dashboard/stats').Bar} bar
 * @param {string} module
 */
function weekMs(bar, module) {
  return bar.segments.find(s => s.module === module)?.ms ?? 0
}

/** @param {import('@/utils/dashboard/stats').Bar} bar */
function barLabel(bar) {
  const parts = bar.segments.map(
    s => `${moduleLabel(s.module)} ${formatHours(s.ms)}`
  )
  return `Semaine du ${bar.label} : ${bar.total ? parts.join(', ') : 'rien'}`
}
</script>

<style scoped lang="scss">
.time-chart__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.time-chart__total {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 20px;
  font-variant-numeric: tabular-nums;
}

.time-chart__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  margin: 10px 0 14px;
  padding: 0;
  list-style: none;
  font-size: 13px;
  color: var(--cm-ink-soft);

  li {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  i {
    width: 10px;
    height: 10px;
    border-radius: 3px;
  }

  strong {
    color: var(--cm-ink);
    font-variant-numeric: tabular-nums;
  }
}

.time-chart__plot {
  position: relative;
  height: 180px;
  margin-left: 44px;

  @media (min-width: $breakpoint-md-min) {
    height: 220px;
  }
}

.time-chart__grid {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.time-chart__gridline {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px solid var(--cm-line);

  span {
    position: absolute;
    right: calc(100% + 8px);
    transform: translateY(-50%);
    font-size: 11px;
    color: var(--cm-muted);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }
}

.time-chart__bars {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: stretch;
  gap: 6px;

  &--dense {
    gap: 2px;
  }
}

// The whole column is the hit target, wider and taller than its marks.
.time-chart__bar {
  display: flex;
  flex: 1;
  flex-direction: column-reverse;
  min-width: 0;
  max-width: 36px;
  margin: 0 auto;
  padding: 0;
  border: none;
  background: transparent;
  cursor: pointer;

  &:hover,
  &:focus-visible {
    background: var(--cm-subtle);
    border-radius: 6px 6px 0 0;
    outline: none;
  }
}

.time-chart__segment {
  display: block;
  width: 100%;
  min-height: 2px;
  box-sizing: border-box;

  // A 2px surface gap between stacked segments, the top one rounded.
  & + & {
    border-bottom: 2px solid var(--cm-surface);
  }

  &:last-child {
    border-radius: 4px 4px 0 0;
  }
}

.time-chart__axis {
  display: flex;
  gap: 6px;
  margin: 6px 0 0 44px;
  font-size: 11px;
  color: var(--cm-muted);

  &--dense {
    gap: 2px;
  }

  span {
    flex: 1;
    min-width: 0;
    overflow: visible;
    white-space: nowrap;
    text-align: center;
  }
}

.time-chart__tip {
  position: absolute;
  top: 0;
  z-index: 1;
  min-width: 150px;
  padding: 8px 10px;
  transform: translateX(-50%);
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);
  border-radius: 12px;
  box-shadow: 0 6px 20px rgba(27, 21, 48, 0.14);
  font-size: 12.5px;
  pointer-events: none;
}

.time-chart__tip-title {
  margin-bottom: 4px;
  font-weight: 700;
}

.time-chart__tip-row {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--cm-ink-soft);

  i {
    width: 8px;
    height: 8px;
    border-radius: 2px;
  }

  strong {
    margin-left: auto;
    color: var(--cm-ink);
    font-variant-numeric: tabular-nums;
  }
}

.time-chart__tip-total {
  margin-top: 4px;
  padding-top: 4px;
  border-top: 1px solid var(--cm-line);
}

.time-chart__table-wrap {
  max-height: 320px;
  overflow: auto;
}

.time-chart__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  font-variant-numeric: tabular-nums;

  th,
  td {
    padding: 6px 8px;
    border-bottom: 1px solid var(--cm-line);
    text-align: right;
    white-space: nowrap;
  }

  th:first-child {
    text-align: left;
  }

  thead th {
    color: var(--cm-muted);
    font-weight: 700;
  }
}
</style>

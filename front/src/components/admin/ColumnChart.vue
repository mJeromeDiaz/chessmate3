<template>
  <section class="cm-card column-chart" :data-testid="testid">
    <div class="column-chart__head">
      <h2 class="cm-card__title">{{ title }}</h2>
      <span v-if="summary" class="column-chart__summary">{{ summary }}</span>
    </div>

    <!-- Legend with each series' total: identity never rests on colour alone. -->
    <ul v-if="series.length > 1" class="column-chart__legend">
      <li
        v-for="s in series"
        :key="s.id"
        :data-testid="`${testid}-legend-${s.id}`"
      >
        <i :style="{ background: s.color }" />
        <span>{{ s.label }}</span>
        <strong>{{ formatValue(seriesTotal(s.id)) }}</strong>
      </li>
    </ul>

    <p v-if="empty" class="cm-muted q-my-md" :data-testid="`${testid}-empty`">
      {{ emptyText }}
    </p>

    <div
      v-else-if="!table"
      ref="plot"
      class="column-chart__plot"
      @mouseleave="hovered = null"
    >
      <svg
        :width="width"
        :height="HEIGHT + AXIS"
        role="img"
        :aria-label="`${title} : graphique, voir le tableau pour les valeurs`"
      >
        <g>
          <template v-for="tick in scale.ticks" :key="tick">
            <line
              :x1="GUTTER"
              :x2="width"
              :y1="tickY(tick)"
              :y2="tickY(tick)"
              class="column-chart__grid"
            />
            <text
              :x="GUTTER - 6"
              :y="tickY(tick)"
              class="column-chart__tick"
              text-anchor="end"
              dominant-baseline="middle"
            >
              {{ formatTick(tick) }}
            </text>
          </template>
        </g>
        <g :transform="`translate(${GUTTER},0)`">
          <g v-for="(col, i) in placed" :key="col.key">
            <path
              v-for="seg in col.segments"
              :key="seg.id"
              :d="barPath(col.x, seg.y, col.width, seg.height, seg.top)"
              :fill="colorOf(seg.id)"
              :opacity="hovered === null || hovered === i ? 1 : 0.45"
            />
            <text
              v-if="labelled.has(i)"
              :x="col.slotX + col.slotWidth / 2"
              :y="HEIGHT + 16"
              class="column-chart__tick"
              text-anchor="middle"
            >
              {{ columns[i].label }}
            </text>
            <!-- Hit target: the whole slot, taller and wider than the bar. -->
            <rect
              :x="col.slotX"
              y="0"
              :width="col.slotWidth"
              :height="HEIGHT"
              fill="transparent"
              :data-testid="`${testid}-column`"
              @mouseenter="hovered = i"
              @click="hovered = hovered === i ? null : i"
            />
          </g>
        </g>
      </svg>

      <div
        v-if="hovered !== null && placed[hovered]"
        class="column-chart__tooltip"
        :style="tooltipStyle"
        role="status"
      >
        <strong>{{ columns[hovered].label }}</strong>
        <template v-if="series.length > 1">
          <span v-for="s in series" :key="s.id" class="column-chart__tip-row">
            <i :style="{ background: s.color }" />{{ s.label }} :
            {{ formatValue(columns[hovered].values[s.id] ?? 0) }}
          </span>
          <span class="column-chart__tip-row"
            >Total : {{ formatValue(columnTotal(columns[hovered])) }}</span
          >
        </template>
        <span v-else>{{
          formatValue(columns[hovered].values[series[0].id] ?? 0)
        }}</span>
      </div>
    </div>

    <div v-else class="column-chart__table-wrap">
      <table class="column-chart__table" :data-testid="`${testid}-table`">
        <thead>
          <tr>
            <th scope="col">{{ keyHeader }}</th>
            <th v-for="s in series" :key="s.id" scope="col">{{ s.label }}</th>
            <th v-if="series.length > 1" scope="col">Total</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="col in columns" :key="col.key">
            <th scope="row">{{ col.label }}</th>
            <td v-for="s in series" :key="s.id">
              {{ formatValue(col.values[s.id] ?? 0) }}
            </td>
            <td v-if="series.length > 1">
              {{ formatValue(columnTotal(col)) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <q-btn
      v-if="!empty"
      flat
      dense
      no-caps
      size="sm"
      class="column-chart__toggle"
      :label="table ? 'Voir le graphique' : 'Voir le tableau'"
      :data-testid="`${testid}-toggle`"
      @click="table = !table"
    />
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { labelledBars } from '@/utils/dashboard/stats'
import { barPath, layoutColumns } from '@/utils/admin/charts'

/**
 * A column chart in SVG (docs/EARLY_ACCESS.md, admin dashboard): one bar per column, stacked by
 * series bottom first; recessive gridlines, about six dates under the axis, a tooltip on hover or
 * tap, and a table of every value one click away.
 */
const props = defineProps({
  title: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/admin/charts').Column[]>} */
  columns: { type: Array, required: true },
  /** @type {import('vue').PropType<import('@/utils/admin/charts').Series[]>} */
  series: { type: Array, required: true },
  /** @type {import('vue').PropType<{max: number, ticks: number[]}>} */
  scale: { type: Object, required: true },
  /** @type {import('vue').PropType<(value: number) => string>} */
  formatValue: { type: Function, default: v => String(v) },
  /** @type {import('vue').PropType<(value: number) => string>} */
  formatTick: { type: Function, default: v => String(v) },
  /** A figure beside the title (e.g. the period's total). */
  summary: { type: String, default: '' },
  emptyText: { type: String, default: 'Aucune donnée sur la période.' },
  /** Header of the table's first column. */
  keyHeader: { type: String, default: 'Jour' },
  testid: { type: String, required: true }
})

const HEIGHT = 180
/** Room under the plot for the dates. */
const AXIS = 24
/** Room left of the plot for the scale. */
const GUTTER = 44

const plot = ref(null)
const width = ref(0)
const hovered = ref(null)
const table = ref(false)

const empty = computed(() => props.columns.every(col => columnTotal(col) === 0))
const placed = computed(() =>
  layoutColumns(props.columns, props.series, {
    width: Math.max(0, width.value - GUTTER),
    height: HEIGHT,
    max: props.scale.max
  })
)
const labelled = computed(() => labelledBars(props.columns.length))
const tooltipStyle = computed(() => {
  const col = placed.value[hovered.value]
  const centre = GUTTER + col.slotX + col.slotWidth / 2
  // Kept inside the card: anchored left on the first half, right on the second.
  return centre < width.value / 2
    ? { left: `${centre}px` }
    : { right: `${width.value - centre}px` }
})

/** @param {number} tick */
function tickY(tick) {
  return HEIGHT - (tick / props.scale.max) * HEIGHT
}

/** @param {string} id */
function colorOf(id) {
  return props.series.find(s => s.id === id)?.color
}

/** @param {import('@/utils/admin/charts').Column} col */
function columnTotal(col) {
  return props.series.reduce((sum, s) => sum + (col.values[s.id] ?? 0), 0)
}

/** @param {string} id */
function seriesTotal(id) {
  return props.columns.reduce((sum, col) => sum + (col.values[id] ?? 0), 0)
}

let observer = null
watch(
  plot,
  el => {
    observer?.disconnect()
    observer = null
    if (!el) return
    width.value = el.clientWidth
    observer = new ResizeObserver(entries => {
      width.value = entries[0].contentRect.width
    })
    observer.observe(el)
  },
  { flush: 'post' }
)
watch(
  () => props.columns,
  () => {
    hovered.value = null
    nextTick(() => {
      if (plot.value) width.value = plot.value.clientWidth
    })
  }
)
onBeforeUnmount(() => observer?.disconnect())
</script>

<style scoped lang="scss">
.column-chart__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.column-chart__summary {
  font-weight: 700;
  color: var(--cm-ink);
}

.column-chart__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  margin: 8px 0 12px;
  padding: 0;
  list-style: none;
  font-size: 13px;
  color: var(--cm-ink-soft);

  li {
    display: inline-flex;
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
  }
}

.column-chart__plot {
  position: relative;
  width: 100%;
}

.column-chart__grid {
  stroke: var(--cm-line);
  stroke-width: 1;
}

.column-chart__tick {
  fill: var(--cm-muted);
  font-size: 11px;
}

.column-chart__tooltip {
  position: absolute;
  top: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 10px;
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);
  border-radius: 10px;
  box-shadow: 0 6px 20px rgba(27, 21, 48, 0.12);
  font-size: 12px;
  color: var(--cm-ink);
  white-space: nowrap;
  pointer-events: none;
}

.column-chart__tip-row {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--cm-ink-soft);

  i {
    width: 8px;
    height: 8px;
    border-radius: 2px;
  }
}

.column-chart__table-wrap {
  max-height: 320px;
  overflow: auto;
}

.column-chart__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;

  th,
  td {
    padding: 4px 8px;
    text-align: right;
    border-bottom: 1px solid var(--cm-line);
  }

  th:first-child {
    text-align: left;
    font-weight: 600;
  }
}

.column-chart__toggle {
  margin-top: 8px;
  color: var(--cm-ink-soft);
}
</style>

/**
 * The statistics page (docs/DASHBOARD.md § 6, lot B): periods, the weekly training-time chart and
 * the figures of each block, computed from the API's answers. Pure, so that it can be tested alone.
 *
 * @typedef {'puzzles'|'repertoire'|'woodpecker'|'free'} ChartModule
 *
 * @typedef {object} TrainingWeek
 * @property {string} start Monday (Y-m-d, local)
 * @property {Record<string, number>} durationMs by module
 *
 * @typedef {object} Segment one module's share of a bar
 * @property {ChartModule} module
 * @property {number} ms
 * @property {number} height percent of the chart's height
 *
 * @typedef {object} Bar
 * @property {string} start
 * @property {string} label e.g. "13 juil."
 * @property {number} total ms
 * @property {Segment[]} segments bottom first, empty modules left out
 *
 * @typedef {object} WeekChart
 * @property {Bar[]} bars
 * @property {{ms: number, label: string, bottom: number}[]} gridlines the scale, from 0 up
 */

/** The period choices, in local days. */
export const PERIODS = [
  { days: 7, label: '7 j' },
  { days: 30, label: '30 j' },
  { days: 90, label: '90 j' },
  { days: 365, label: '1 an' }
]
export const DEFAULT_PERIOD = 30

/**
 * The modules of the chart, stacked bottom first, in the fixed order of their colours
 * (`--cm-chart-<module>`, validated for colour vision deficiencies in this order).
 *
 * @type {{id: ChartModule, label: string}[]}
 */
export const CHART_MODULES = [
  { id: 'puzzles', label: 'Puzzles' },
  { id: 'repertoire', label: 'Répertoire' },
  { id: 'woodpecker', label: 'Woodpecker' },
  { id: 'free', label: 'Libre' }
]

const MINUTE = 60_000
const HOUR = 60 * MINUTE

/**
 * A known period, the default one otherwise (a stale or tampered stored value).
 *
 * @param {unknown} days
 * @returns {number}
 */
export function validPeriod(days) {
  const n = Number(days)
  return PERIODS.some(p => p.days === n) ? n : DEFAULT_PERIOD
}

/**
 * A training time: "45 min", "2 h", "2 h 05".
 *
 * @param {number|null|undefined} ms
 * @returns {string}
 */
export function formatHours(ms) {
  if (ms === null || ms === undefined) return '—'
  const minutes = Math.round(ms / MINUTE)
  if (minutes < 60) return `${minutes} min`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h} h ${String(m).padStart(2, '0')}` : `${h} h`
}

/**
 * A local day as "13 juil." (the date is a calendar day: no timezone shift).
 *
 * @param {string} date Y-m-d
 * @returns {string}
 */
export function dayLabel(date) {
  return new Intl.DateTimeFormat('fr-FR', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC'
  }).format(new Date(`${date}T00:00:00Z`))
}

/**
 * The top of the scale: a round value above the longest week (15 min, 30 min, 1 h, 2 h, 3 h…).
 *
 * @param {number} ms
 * @returns {number}
 */
export function scaleMax(ms) {
  const steps = [15 * MINUTE, 30 * MINUTE, HOUR]
  for (const step of steps) if (ms <= step) return step
  return Math.ceil(ms / HOUR) * HOUR
}

/**
 * The weekly stacked bars: one bar per week, one segment per module played that week, heights in
 * percent of the scale; three gridlines (0, half, top).
 *
 * @param {TrainingWeek[]} weeks
 * @returns {WeekChart}
 */
export function weekChart(weeks) {
  const totals = weeks.map(w =>
    CHART_MODULES.reduce((sum, m) => sum + (w.durationMs[m.id] ?? 0), 0)
  )
  const max = scaleMax(Math.max(0, ...totals))
  const bars = weeks.map((week, i) => ({
    start: week.start,
    label: dayLabel(week.start),
    total: totals[i],
    segments: CHART_MODULES.filter(m => (week.durationMs[m.id] ?? 0) > 0).map(
      m => ({
        module: m.id,
        ms: week.durationMs[m.id],
        height: (week.durationMs[m.id] / max) * 100
      })
    )
  }))
  const gridlines = [0, max / 2, max].map(ms => ({
    ms,
    label: ms === 0 ? '0' : formatHours(ms),
    bottom: (ms / max) * 100
  }))
  return { bars, gridlines }
}

/**
 * Which bars carry a date under the axis: about six, evenly spread, the last one always.
 *
 * @param {number} count
 * @returns {Set<number>}
 */
export function labelledBars(count) {
  const every = Math.max(1, Math.ceil(count / 6))
  const shown = new Set()
  for (let i = count - 1; i >= 0; i -= every) shown.add(i)
  return shown
}

/**
 * The sessions block's four figures.
 *
 * @param {{closed: number, completed: number, abandoned: number, expired: number, playedMs: number, averageMs: number|null}|null|undefined} s
 * @returns {{id: string, value: string, label: string}[]}
 */
export function sessionFigures(s) {
  return [
    { id: 'completed', value: String(s?.completed ?? 0), label: 'Terminées' },
    { id: 'abandoned', value: String(s?.abandoned ?? 0), label: 'Abandonnées' },
    { id: 'expired', value: String(s?.expired ?? 0), label: 'Expirées' },
    {
      id: 'average',
      value: s?.averageMs == null ? '—' : formatHours(s.averageMs),
      label: 'Temps moyen'
    }
  ]
}

/**
 * The tip of the home page: the weakest theme, null when there is none to work on.
 *
 * @param {{weak: {key: string, attempts: number, successRate: number}[]}|null|undefined} themes
 */
export function weakestTheme(themes) {
  return themes?.weak?.[0] ?? null
}

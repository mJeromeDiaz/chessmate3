import { dayLabel } from '@/utils/dashboard/stats'

/**
 * The admin dashboard's charts and figures (docs/EARLY_ACCESS.md), computed from GET /api/admin/stats.
 * Pure, so that it can be tested alone; the components only draw what this returns.
 *
 * @typedef {object} Series one stacked layer (or the only one) of a column chart
 * @property {string} id
 * @property {string} label
 * @property {string} color a CSS colour (a `var(--cm-…)` token)
 *
 * @typedef {object} Column one x position: a day, a week or a rating band
 * @property {string} key
 * @property {string} label under the axis, and in the tooltip and the table
 * @property {Record<string, number>} values by series id
 *
 * @typedef {object} Segment a drawn piece of a column
 * @property {string} id series id
 * @property {number} y top, in px from the top of the plot
 * @property {number} height px
 * @property {boolean} top the column's data end (rounded)
 *
 * @typedef {object} Placed a column, laid out
 * @property {string} key
 * @property {number} x left edge of the bar, px
 * @property {number} width of the bar, px
 * @property {number} slotX left edge of its slot (the hover target), px
 * @property {number} slotWidth px
 * @property {Segment[]} segments bottom first, empty series left out
 */

/** Sign-up methods, stacked bottom first in the fixed order of their colours (validated together). */
export const SIGNUP_METHODS = [
  { id: 'password', label: 'Mot de passe', color: 'var(--cm-admin-password)' },
  { id: 'google', label: 'Google', color: 'var(--cm-admin-google)' },
  { id: 'lichess', label: 'Lichess', color: 'var(--cm-admin-lichess)' },
  { id: 'other', label: 'Sans clé', color: 'var(--cm-admin-other)' }
]

/** The single-series charts (active players, weekly time, ratings) wear the brand colour. */
export const BRAND_SERIES = { color: 'var(--cm-brand)' }

/** Bars never get thicker than this, whatever the room: the rest of the slot is air. */
export const MAX_BAR = 24
/** Surface gap between touching marks; dropped when bars get too thin to afford it. */
export const GAP = 2

/**
 * A round top for a count axis, and its gridlines: steps of 1, 2 or 5 × 10^n, about four of them.
 *
 * @param {number} value the largest value drawn
 * @param {number} [count] gridlines wanted above zero
 * @returns {{max: number, ticks: number[]}} ticks from 0 up to max
 */
export function niceScale(value, count = 4) {
  if (!(value > 0)) return { max: count, ticks: range(count, 1) }
  const rough = value / count
  const power = 10 ** Math.floor(Math.log10(rough))
  const step =
    [1, 2, 5, 10].map(m => m * power).find(s => s >= rough) ?? 10 * power
  const whole = Math.max(1, Math.round(step))
  const max = Math.ceil(value / whole) * whole

  return { max, ticks: range(Math.round(max / whole), whole) }
}

/**
 * A round top for a duration axis (15 min, 30 min, 1 h, then whole hours), and three gridlines.
 *
 * @param {number} ms
 * @returns {{max: number, ticks: number[]}}
 */
export function durationScale(ms) {
  const minute = 60_000
  const top =
    [15, 30, 60].map(m => m * minute).find(step => ms <= step) ??
    Math.ceil(ms / (60 * minute)) * 60 * minute

  return { max: top, ticks: [0, top / 2, top] }
}

/**
 * @param {number} n
 * @param {number} step
 * @returns {number[]} 0, step, … n × step
 */
function range(n, step) {
  return Array.from({ length: n + 1 }, (_, i) => i * step)
}

/**
 * Lays the columns out in a plot of `width` × `height` px: one slot each, a bar of at most
 * MAX_BAR px centred in it, segments stacked bottom first with a GAP between them.
 *
 * @param {Column[]} columns
 * @param {Series[]} series stacking order, bottom first
 * @param {{width: number, height: number, max: number}} box
 * @returns {Placed[]}
 */
export function layoutColumns(columns, series, { width, height, max }) {
  if (columns.length === 0 || width <= 0 || max <= 0) return []
  const slotWidth = width / columns.length
  const gap = slotWidth >= 6 ? GAP : 0
  const barWidth = Math.max(1, Math.min(MAX_BAR, slotWidth - gap))

  return columns.map((column, i) => {
    const slotX = i * slotWidth
    const drawn = series.filter(s => (column.values[s.id] ?? 0) > 0)
    /** @type {Segment[]} */
    const segments = []
    let bottom = height
    drawn.forEach((s, j) => {
      const full = ((column.values[s.id] ?? 0) / max) * height
      // The gap comes out of the segment above it, so the stack keeps its total height.
      const h = Math.max(1, full - (j > 0 ? gap : 0))
      segments.push({
        id: s.id,
        y: bottom - full,
        height: h,
        top: j === drawn.length - 1
      })
      bottom -= full
    })

    return {
      key: column.key,
      x: slotX + (slotWidth - barWidth) / 2,
      width: barWidth,
      slotX,
      slotWidth,
      segments
    }
  })
}

/**
 * The SVG path of a bar segment: square at the bottom, its top corners rounded (radius 4, less
 * when the bar is thinner or shorter) when it is the column's data end.
 *
 * @param {number} x
 * @param {number} y
 * @param {number} w
 * @param {number} h
 * @param {boolean} rounded
 * @returns {string}
 */
export function barPath(x, y, w, h, rounded) {
  const r = rounded ? Math.min(4, w / 2, h) : 0
  return [
    `M${x},${y + h}`,
    `V${y + r}`,
    r ? `Q${x},${y} ${x + r},${y}` : '',
    `H${x + w - r}`,
    r ? `Q${x + w},${y} ${x + w},${y + r}` : '',
    `V${y + h}`,
    'Z'
  ]
    .filter(Boolean)
    .join(' ')
}

/**
 * Sign-ups by day, stacked by method.
 *
 * @param {{date: string, total: number, byMethod: Record<string, number>}[]} days
 * @returns {Column[]}
 */
export function signupColumns(days) {
  return days.map(d => ({
    key: d.date,
    label: dayLabel(d.date),
    values: { ...d.byMethod }
  }))
}

/**
 * Active players by day (each player's own local day).
 *
 * @param {{date: string, activePlayers: number}[]} days
 * @returns {Column[]}
 */
export function activeColumns(days) {
  return days.map(d => ({
    key: d.date,
    label: dayLabel(d.date),
    values: { value: d.activePlayers }
  }))
}

/**
 * Average training time per active player, week by week (weeks without a player count 0).
 *
 * @param {{start: string, activePlayers: number, durationMs: number, averageMs: number|null}[]} weeks
 * @returns {Column[]}
 */
export function weeklyTimeColumns(weeks) {
  return weeks.map(w => ({
    key: w.start,
    label: `Sem. du ${dayLabel(w.start)}`,
    values: { value: w.averageMs ?? 0 }
  }))
}

/**
 * The Lichess rating histogram, one column per band ("1700–1799").
 *
 * @param {{from: number, count: number}[]} bands
 * @param {number} bandWidth
 * @returns {Column[]}
 */
export function ratingColumns(bands, bandWidth) {
  return bands.map(b => ({
    key: String(b.from),
    label: `${b.from}–${b.from + bandWidth - 1}`,
    values: { value: b.count }
  }))
}

/**
 * The invitation funnel: what became of the invitations created in the period, as rows whose
 * share is of the invitations created.
 *
 * @param {{created: number, pending: number, used: number, expired: number, revoked: number, emailFailed: number}} inv
 * @returns {{id: string, label: string, count: number, share: number}[]}
 */
export function funnelRows(inv) {
  const share = n => (inv.created > 0 ? n / inv.created : 0)
  return [
    { id: 'created', label: 'Créées', count: inv.created },
    { id: 'used', label: 'Utilisées', count: inv.used },
    { id: 'pending', label: 'En attente', count: inv.pending },
    { id: 'expired', label: 'Expirées', count: inv.expired },
    { id: 'revoked', label: 'Révoquées', count: inv.revoked },
    { id: 'emailFailed', label: 'Email en échec', count: inv.emailFailed }
  ].map(row => ({ ...row, share: share(row.count) }))
}

/**
 * A count, compact past ten thousand ("12,9 k").
 *
 * @param {number|null|undefined} n
 * @returns {string}
 */
export function formatCount(n) {
  if (n === null || n === undefined) return '—'
  return new Intl.NumberFormat('fr-FR', {
    notation: Math.abs(n) >= 10_000 ? 'compact' : 'standard',
    maximumFractionDigits: 1
  }).format(n)
}

/**
 * A duration in seconds as the largest sensible unit: "45 min", "5 h", "3 j".
 *
 * @param {number|null|undefined} seconds
 * @returns {string}
 */
export function formatWait(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))} min`
  if (seconds < 2 * 86_400) return `${Math.round(seconds / 3600)} h`
  return `${Math.round(seconds / 86_400)} j`
}

/**
 * A count and its noun: "1 inactif", "3 inactifs" (0 takes the singular, as in French).
 *
 * @param {number} n
 * @param {string} one
 * @param {string} many
 * @returns {string}
 */
export function plural(n, one, many) {
  return `${formatCount(n)} ${n > 1 ? many : one}`
}

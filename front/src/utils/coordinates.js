/**
 * The coordinates series (docs/COORDINATES.md), what the pages show. Pure. The thresholds are never
 * written here: they come from the API (`rules`).
 *
 * @typedef {object} CoordinateRules
 * @property {number} seriesSeconds
 * @property {number} minAnswers
 * @property {number} minSuccessRate 0..1
 *
 * @typedef {object} CoordinateItem an answer of the run review
 * @property {number} index 1-based
 * @property {'ok'|'fail'} status
 * @property {number|null} durationMs
 * @property {{index: number, target: string, clicked: string}} data
 *
 * @typedef {object} RibbonCell
 * @property {number} number 1-based
 * @property {boolean} correct
 * @property {string} target
 * @property {string} clicked
 * @property {string} title the tooltip: "#3 · e4 → d4 · 1,2 s"
 */

/** The orientations, as the API names them. */
export const ORIENTATIONS = {
  white: 'Blancs',
  black: 'Noirs'
}

/**
 * A short time: "0,8 s", "12 s", "1 min 05".
 *
 * @param {number|null|undefined} ms
 * @returns {string}
 */
export function formatAnswerTime(ms) {
  if (ms === null || ms === undefined) return '—'
  if (ms < 10_000) return `${(ms / 1000).toFixed(1).replace('.', ',')} s`
  const seconds = Math.round(ms / 1000)
  if (seconds < 60) return `${seconds} s`
  return `${Math.floor(seconds / 60)} min ${String(seconds % 60).padStart(2, '0')}`
}

/**
 * The ribbon of a series: one cell per answer, in the order played.
 *
 * @param {CoordinateItem[]} items
 * @returns {RibbonCell[]}
 */
export function ribbonCells(items) {
  return items.map(item => {
    const correct = item.status === 'ok'
    const { target, clicked } = item.data
    const what = correct ? target : `${target} → ${clicked}`
    return {
      number: item.index,
      correct,
      target,
      clicked,
      title: `#${item.index} · ${what} · ${formatAnswerTime(item.durationMs)}`
    }
  })
}

/**
 * The totals under the ribbon.
 *
 * @param {CoordinateItem[]} items
 * @returns {{count: number, correct: number, wrong: number, rate: number|null, averageMs: number|null}}
 */
export function ribbonTotals(items) {
  const count = items.length
  const correct = items.filter(i => i.status === 'ok').length
  const timed = items.filter(i => i.durationMs !== null)
  return {
    count,
    correct,
    wrong: count - correct,
    rate: count ? correct / count : null,
    averageMs: timed.length
      ? Math.round(
          timed.reduce((sum, i) => sum + (i.durationMs ?? 0), 0) / timed.length
        )
      : null
  }
}

/**
 * The squares missed most often (target squares), the most missed first.
 *
 * @param {CoordinateItem[]} items
 * @param {number} [limit]
 * @returns {{square: string, count: number}[]}
 */
export function missedSquares(items, limit = 5) {
  /** @type {Map<string, number>} */
  const counts = new Map()
  for (const item of items) {
    if (item.status === 'ok') continue
    counts.set(item.data.target, (counts.get(item.data.target) ?? 0) + 1)
  }
  return [...counts]
    .map(([square, count]) => ({ square, count }))
    .sort((a, b) => b.count - a.count || a.square.localeCompare(b.square))
    .slice(0, limit)
}

/**
 * Why a closed series validates its orientation or not, for the user.
 *
 * @param {{closeReason: string|null, summary: {itemCount: number, successCount: number, metrics: Record<string, any>}|null}} run
 * @returns {string}
 */
export function validationText(run) {
  const s = run.summary
  const m = s?.metrics ?? {}
  const orientation = ORIENTATIONS[m.orientation] ?? ''
  if (m.validated) return `${orientation} validés !`
  /** @type {CoordinateRules|undefined} */
  const rules = m.rules
  if (!s || !rules) return 'Série non validante.'
  if (run.closeReason === 'stopped')
    return 'Série arrêtée avant la fin : elle ne valide pas.'
  if (s.itemCount < rules.minAnswers)
    return `${s.itemCount} réponses : il en faut ${rules.minAnswers} pour valider.`
  const pct = Math.floor((s.successCount / s.itemCount) * 100)
  return `${pct} % de réussite : il faut ${Math.round(rules.minSuccessRate * 100)} % pour valider.`
}

/**
 * The validation threshold as a sentence: "50 réponses et 95 % de réussite en 5 min".
 *
 * @param {CoordinateRules} rules
 * @returns {string}
 */
export function rulesText(rules) {
  const minutes = Math.round(rules.seriesSeconds / 60)
  return `au moins ${rules.minAnswers} réponses et ${Math.round(rules.minSuccessRate * 100)} % de réussite en ${minutes} min, sans arrêter la série`
}

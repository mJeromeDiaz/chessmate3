import { intlLocale, t } from '@/i18n'

/**
 * The coordinates series (docs/COORDINATES.md), what the pages show. Pure. The thresholds are never
 * written here: they come from the API (`rules`).
 *
 * @typedef {object} CoordinateRules the rules before a series (GET /coordinates)
 * @property {number[]} seriesMinutes the lengths a series may last
 * @property {number} defaultMinutes the one offered first
 * @property {number} minAnswersPerMinute answers needed to validate, per minute of the series
 * @property {number} minSuccessRate 0..1
 *
 * @typedef {object} SeriesRules the rules of one series (its item, its summary)
 * @property {number} seriesSeconds
 * @property {number} minAnswers
 * @property {number} minSuccessRate 0..1
 *
 * @typedef {object} CoordinateItem an answer of the run review
 * @property {number} index 1-based
 * @property {'ok'|'fail'|'hint'} status hint: the wrong answer the store's pardon took away
 * @property {number|null} durationMs
 * @property {{index: number, target: string, clicked: string, pardoned?: boolean}} data
 *
 * @typedef {object} RibbonCell
 * @property {number} number 1-based
 * @property {boolean} correct
 * @property {boolean} pardoned the store's pardon took it away: neither an answer nor an error
 * @property {string} target
 * @property {string} clicked
 * @property {string} title the tooltip: "#3 · e4 → d4 · 1,2 s"
 */

/** The orientations, as the API names them. */
export const ORIENTATIONS = Object.defineProperties(
  {},
  Object.fromEntries(
    ['white', 'black'].map(side => [
      side,
      { get: () => t(`coordinates.orientations.${side}`), enumerable: true }
    ])
  )
)

/**
 * A short time: "0,8 s", "12 s", "1 min 05".
 *
 * @param {number|null|undefined} ms
 * @returns {string}
 */
export function formatAnswerTime(ms) {
  if (ms === null || ms === undefined) return '—'
  if (ms < 10_000)
    return t('coordinates.time.tenths', {
      n: (ms / 1000).toLocaleString(intlLocale(), {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1
      })
    })
  const seconds = Math.round(ms / 1000)
  if (seconds < 60) return t('coordinates.time.seconds', { n: seconds })
  return t('coordinates.time.minutes', {
    m: Math.floor(seconds / 60),
    s: String(seconds % 60).padStart(2, '0')
  })
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
    const pardoned = !!item.data.pardoned
    const { target, clicked } = item.data
    const what = correct ? target : `${target} → ${clicked}`
    return {
      number: item.index,
      correct,
      pardoned,
      target,
      clicked,
      title:
        t('coordinates.cell', {
          n: item.index,
          what,
          time: formatAnswerTime(item.durationMs)
        }) + (pardoned ? t('coordinates.pardoned') : '')
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
  // The pardoned answer counts neither as an answer nor as an error.
  items = items.filter(i => !i.data.pardoned)
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
  if (m.validated)
    return t(
      `coordinates.validation.${m.orientation === 'black' ? 'black' : 'white'}`
    )
  /** @type {SeriesRules|undefined} */
  const rules = m.rules
  if (!s || !rules) return t('coordinates.validation.none')
  if (run.closeReason === 'stopped') return t('coordinates.validation.stopped')
  if (s.itemCount < rules.minAnswers)
    return t('coordinates.validation.tooFew', {
      n: s.itemCount,
      min: rules.minAnswers
    })
  const pct = Math.floor((s.successCount / s.itemCount) * 100)
  return t('coordinates.validation.rate', {
    pct,
    min: Math.round(rules.minSuccessRate * 100)
  })
}

/**
 * The validation threshold as a sentence: "au moins 10 réponses par minute et 95 % de réussite…".
 *
 * @param {CoordinateRules} rules
 * @returns {string}
 */
export function rulesText(rules) {
  return t('coordinates.rules', {
    n: rules.minAnswersPerMinute,
    pct: Math.round(rules.minSuccessRate * 100)
  })
}

/**
 * The lengths of a series as words: "1, 2, 5 ou 10 min".
 *
 * @param {CoordinateRules} rules
 * @returns {string}
 */
export function durationsText(rules) {
  const minutes = rules.seriesMinutes
  if (minutes.length < 2)
    return t('coordinates.lengths.one', { n: minutes[0] ?? '?' })
  return t('coordinates.lengths.many', {
    list: minutes.slice(0, -1).join(', '),
    last: minutes[minutes.length - 1]
  })
}

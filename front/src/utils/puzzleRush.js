import { intlLocale, t } from '@/i18n'

/**
 * The puzzle rush (docs/PUZZLE_RUSH.md): as many puzzles as possible in 2, 3 or 5 minutes, a miss
 * wipes out puzzles solved. The server computes the score and the rewards (frozen in the run's
 * summary); these helpers only show them, and the accuracy while playing. Pure.
 *
 * @typedef {object} RushRules what GET /puzzle-rush says (App\PuzzleRush\RushRules)
 * @property {number[]} budgets seconds
 * @property {number} defaultBudget seconds
 * @property {number} errorWeight puzzles solved a miss wipes out
 * @property {number} successAccuracy from this accuracy, the run is a success
 * @property {number} startBelow
 * @property {number} startMin
 * @property {number} step
 * @property {number} maxThemes
 *
 * @typedef {object} RushRecord
 * @property {number} budgetSeconds
 * @property {number|null} best
 * @property {number} runs
 * @property {string|null} at
 */

/** The API's rules when they are not known yet (the same values). */
export const RUSH_DEFAULTS = {
  budgets: [120, 180, 300],
  defaultBudget: 180,
  errorWeight: 2
}

/**
 * Puzzles solved among those played, 0 to 1; null when none was played (no accuracy, not 0).
 *
 * @param {number} solved
 * @param {number} failed
 * @returns {number|null}
 */
export function rushAccuracy(solved, failed) {
  const played = solved + failed
  return played > 0 ? Math.round((solved / played) * 100) / 100 : null
}

/**
 * The score as the server counts it: solved minus `errorWeight` per miss, never below 0.
 *
 * @param {number} solved
 * @param {number} failed
 * @param {number} [errorWeight]
 */
export function rushScore(
  solved,
  failed,
  errorWeight = RUSH_DEFAULTS.errorWeight
) {
  return Math.max(0, solved - errorWeight * failed)
}

/**
 * An accuracy in whole percent ("83 %"), "—" without any.
 *
 * @param {number|null|undefined} accuracy 0 to 1
 */
export function accuracyText(accuracy) {
  return accuracy === null || accuracy === undefined
    ? '—'
    : t('puzzleRush.percent', { n: Math.round(accuracy * 100) })
}

/**
 * The counters shown while playing: solved, failed and the accuracy so far; never the score.
 *
 * @param {number} solved
 * @param {number} failed
 */
export function rushProgress(solved, failed) {
  return t('puzzleRush.progress', {
    solved,
    failed,
    accuracy: accuracyText(rushAccuracy(solved, failed))
  })
}

/**
 * Puzzles per minute, in the current language ("1,67").
 *
 * @param {number|null|undefined} perMinute
 */
export function speedText(perMinute) {
  return perMinute === null || perMinute === undefined
    ? '—'
    : perMinute.toLocaleString(intlLocale(), { maximumFractionDigits: 2 })
}

/**
 * How full the result's ring is: the accuracy, in percent.
 *
 * @param {{summary: {metrics?: Record<string, any>}|null}} run
 * @returns {number}
 */
export function rushRing(run) {
  const accuracy = run.summary?.metrics?.accuracy
  return typeof accuracy === 'number' ? Math.round(accuracy * 100) : 0
}

/**
 * The record line of a rush: beaten, to beat, or not ranked (stopped early, or with themes).
 *
 * @param {Record<string, any>} metrics
 */
export function rushRecordNote(metrics) {
  if (!metrics.ranked) return t('puzzleRush.result.unranked')
  const before = metrics.recordBefore ?? null
  if (metrics.record)
    return before === null
      ? t('puzzleRush.result.firstRecord')
      : t('puzzleRush.result.newRecord', { n: before })
  return before === null ? '' : t('puzzleRush.result.record', { n: before })
}

/**
 * The lines under a rush's score: speed and accuracy together, how the score adds up, the record,
 * then the XP gained (the review's, once known).
 *
 * @param {{summary: {metrics?: Record<string, any>}|null}} run
 * @param {number|null} xp the XP the run gained (nothing said for none, or while unknown)
 * @returns {string[]}
 */
export function rushLines(run, xp) {
  const m = run.summary?.metrics ?? {}
  const solved = m.solved ?? 0
  const failed = m.failed ?? 0
  return [
    t('puzzleRush.result.speedAccuracy', {
      speed: speedText(m.puzzlesPerMinute),
      accuracy: accuracyText(m.accuracy)
    }),
    t('puzzleRush.result.scoreDetail', {
      solved,
      failed,
      weight: m.errorWeight ?? RUSH_DEFAULTS.errorWeight,
      score: m.score ?? rushScore(solved, failed, m.errorWeight)
    }),
    rushRecordNote(m),
    xp ? t('puzzleRush.result.xp', { n: xp }) : ''
  ].filter(Boolean)
}

/**
 * The lengths offered, in minutes, and the one chosen first.
 *
 * @param {Pick<RushRules, 'budgets'|'defaultBudget'>|null} rules
 */
export function rushMinutes(rules) {
  const r = rules ?? RUSH_DEFAULTS
  return {
    minutes: r.budgets.map(s => s / 60),
    default: r.defaultBudget / 60
  }
}

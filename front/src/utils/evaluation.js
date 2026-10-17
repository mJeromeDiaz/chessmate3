/**
 * The position evaluation's screen (design "Évaluation", docs/EVALUATION.md): the five answers,
 * the gauge "you / the engine", what the sheet and Aaron say. Pure, so that it can be tested alone.
 *
 * @typedef {'exact'|'close'|'miss'|'timeout'} EvaluationStatus
 *
 * @typedef {object} EvaluationResult the submission's `result.data`
 * @property {EvaluationStatus} status
 * @property {number|null} guess
 * @property {number} category
 * @property {number} evalCp
 * @property {string|null} plan
 * @property {string|null} chosenPlan
 * @property {boolean|null} planOk
 * @property {string[]} ideas
 * @property {string|null} source
 * @property {number|null} durationMs
 */

import { formatRate } from '@/utils/format'
import { intlLocale, t } from '@/i18n'

/** The answers, White's point of view, from White winning to Black winning. */
export const EVAL_CHOICES = [
  [2, '+−'],
  [1, '±'],
  [0, '='],
  [-1, '∓'],
  [-2, '−+']
].map(([value, sym]) => ({
  value,
  sym,
  get label() {
    return t(`evaluation.choices.${value}.label`)
  },
  get short() {
    return t(`evaluation.choices.${value}.short`)
  }
}))

/** Titles of an exact answer, in turn (evaluation.winTitles). */
const WIN_TITLES = 3

/** The verdict in the correction card. */
export const VERDICTS = Object.defineProperties(
  {},
  Object.fromEntries(
    ['exact', 'close', 'miss', 'timeout'].map(status => [
      status,
      { get: () => t(`evaluation.verdicts.${status}`), enumerable: true }
    ])
  )
)

/**
 * "1:05"
 *
 * @param {number} ms
 */
export function clock(ms) {
  const s = Math.max(0, Math.ceil(ms / 1000))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

/**
 * Where an evaluation sits on the gauge, from Black winning (left) to White winning (right):
 * ±3 pawns fill it.
 *
 * @param {number} cp
 */
export function gaugeX(cp) {
  return `${Math.max(3, Math.min(97, 50 + (cp / 300) * 47))}%`
}

/**
 * Where an answer sits on the gauge (a category drawn as 1.5 pawns a step).
 *
 * @param {number} guess -2 to 2
 */
export function guessX(guess) {
  return gaugeX(guess * 150)
}

/**
 * @param {number|null} guess
 */
export function choiceLabel(guess) {
  return EVAL_CHOICES.find(c => c.value === guess)?.label ?? '—'
}

/**
 * The sheet's look and words, and Aaron's, for a verdict. A close answer is a miss in yellow
 * ("?!"), time up a miss with a clock.
 *
 * @param {EvaluationResult} result
 * @param {number} index the position's number in the run (1 first)
 */
export function evaluationFeedback(result, index) {
  if (result.status === 'exact') {
    return {
      kind: /** @type {'win'} */ ('win'),
      palette: null,
      badge: '?',
      title: t(`evaluation.winTitles.${(index - 1) % WIN_TITLES}`),
      sub: result.planOk
        ? t('evaluation.feedback.exactPlan')
        : t('evaluation.feedback.exact', {
            time: clock(result.durationMs ?? 0)
          }),
      kicker: t('evaluation.feedback.kicker'),
      bubble: t('evaluation.feedback.exactBubble')
    }
  }
  const lookAgain = t('evaluation.feedback.lookAgain')
  if (result.status === 'close') {
    return {
      kind: /** @type {'miss'} */ ('miss'),
      palette: /** @type {'help'} */ ('help'),
      badge: '?!',
      title: t('evaluation.verdicts.close'),
      sub: t('evaluation.feedback.closeSub', { engine: engineLabel(result.evalCp) }),
      kicker: t('evaluation.feedback.kicker'),
      bubble: lookAgain
    }
  }
  return {
    kind: /** @type {'miss'} */ ('miss'),
    palette: null,
    badge: result.status === 'timeout' ? '⏱︎' : '?',
    title: VERDICTS[result.status],
    sub: t('evaluation.feedback.missSub', { engine: engineLabel(result.evalCp) }),
    kicker: t('evaluation.feedback.kicker'),
    bubble: lookAgain
  }
}

/**
 * The plan's line under the correction, '' when the position asks none.
 *
 * @param {EvaluationResult} result
 * @param {{value: string, label: string}[]} plans
 */
export function planResult(result) {
  if (!result.plan) return ''
  if (!result.chosenPlan) return t('evaluation.plan.none')
  if (result.planOk) return t('evaluation.plan.ok')
  return t('evaluation.plan.chosen', { plan: planLabel(result.chosenPlan) })
}

/**
 * A plan's name, from its value sent by the API (App\Enum\Evaluation\Plan).
 *
 * @param {string} value e.g. "king_attack"
 */
export function planLabel(value) {
  return t(`evaluation.plans.${value}`)
}

/**
 * A position's tag, from its value sent by the API (App\Enum\Evaluation\PositionTag).
 *
 * @param {string} value e.g. "middlegame"
 */
export function tagLabel(value) {
  return t(`evaluation.tags.${value}`)
}

/** The side to move asked, as the run's `side` names it. */
export const SIDE_OPTIONS = ['both', 'white', 'black'].map(value => ({
  value,
  get label() {
    return t(`evaluation.sides.${value}`)
  }
}))

/**
 * Positions available for a side to move.
 *
 * @param {{white: number, black: number}} positions GET /evaluation's `positions`
 * @param {string} side white, black or both
 */
export function positionsFor(positions, side) {
  if (side === 'white') return positions.white
  if (side === 'black') return positions.black
  return positions.white + positions.black
}

/**
 * "12 positions · 7 exactes (58 %) · 3 à un cran", what GET /evaluation's `results` sum up.
 *
 * @param {{played: number, exact: number, close: number, miss: number, timeout: number, planOk: number}} results
 */
export function resultsText(results) {
  const r = 'evaluation.results.'
  if (!results.played) return t(`${r}none`)
  const parts = [
    t(`${r}played`, { n: results.played }, results.played),
    t(
      `${r}exact`,
      { n: results.exact, rate: formatRate(results.exact / results.played) },
      results.exact
    )
  ]
  if (results.close) parts.push(t(`${r}close`, { n: results.close }))
  if (results.planOk)
    parts.push(t(`${r}plans`, { n: results.planOk }, results.planOk))
  return parts.join(' · ')
}

/**
 * The thresholds in pawns, White's point of view: "0,7" and "2".
 *
 * @param {number} cp
 */
export function pawns(cp) {
  return (cp / 100).toLocaleString(intlLocale(), { maximumFractionDigits: 2 })
}

/** From this many centipawns, the engine says won: "+−" or "−+" (App\Evaluation\EvaluationRules::WON_CP). */
const WON_CP = 10_000

/**
 * The engine's evaluation, White's point of view: "+1,4", "−0,3", "0,0", "+−" (won).
 *
 * @param {number} cp
 */
export function engineLabel(cp) {
  if (Math.abs(cp) >= WON_CP) return cp > 0 ? '+−' : '−+'
  const value = (Math.abs(cp) / 100).toLocaleString(intlLocale(), {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1
  })
  return (cp > 0 ? '+' : cp < 0 ? '−' : '') + value
}

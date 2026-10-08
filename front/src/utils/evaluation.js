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
 * @property {string} engine
 * @property {string|null} plan
 * @property {string|null} planLabel
 * @property {string|null} chosenPlan
 * @property {boolean|null} planOk
 * @property {string[]} ideas
 * @property {string|null} source
 * @property {number|null} durationMs
 * @property {boolean} fast
 */

import { formatRate } from '@/utils/format'

/** The answers, White's point of view, from White winning to Black winning. */
export const EVAL_CHOICES = [
  { value: 2, sym: '+−', label: 'Blancs gagnent', short: 'Blancs ++' },
  { value: 1, sym: '±', label: 'Avantage Blancs', short: 'Blancs +' },
  { value: 0, sym: '=', label: 'Équilibré', short: 'Égal' },
  { value: -1, sym: '∓', label: 'Avantage Noirs', short: 'Noirs +' },
  { value: -2, sym: '−+', label: 'Noirs gagnent', short: 'Noirs ++' }
]

/** Titles of an exact answer, in turn. */
const WIN_TITLES = ['Promotion !', 'Analyse royale !', 'Œil de grand maître !']

/** The verdict in the correction card. */
export const VERDICTS = {
  exact: 'Bien vu !',
  close: 'Presque !',
  miss: 'Pas cette fois',
  timeout: 'Temps écoulé'
}

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
      title: WIN_TITLES[(index - 1) % WIN_TITLES.length],
      sub: result.planOk
        ? 'Évaluation et plan exacts'
        : `Évaluation exacte · ${clock(result.durationMs ?? 0)}`,
      kicker: 'CORRECTION',
      bubble: 'Exactement mon analyse. Tu as l’œil !'
    }
  }
  const lookAgain =
    'Regarde les idées clés : ce sont elles qui font pencher la balance.'
  if (result.status === 'close') {
    return {
      kind: /** @type {'miss'} */ ('miss'),
      palette: /** @type {'help'} */ ('help'),
      badge: '?!',
      title: 'Presque !',
      sub: `À un cran du moteur (${result.engine})`,
      kicker: 'CORRECTION',
      bubble: lookAgain
    }
  }
  return {
    kind: /** @type {'miss'} */ ('miss'),
    palette: null,
    badge: result.status === 'timeout' ? '⏱︎' : '?',
    title: VERDICTS[result.status],
    sub: `Le moteur dit ${result.engine} · relis les idées clés`,
    kicker: 'CORRECTION',
    bubble: lookAgain
  }
}

/**
 * The plan's line under the correction, '' when the position asks none.
 *
 * @param {EvaluationResult} result
 * @param {{value: string, label: string}[]} plans
 */
export function planResult(result, plans) {
  if (!result.plan) return ''
  if (!result.chosenPlan) return 'aucun plan choisi'
  if (result.planOk) return '✓ bien trouvé (+5 XP)'
  const chosen =
    plans.find(p => p.value === result.chosenPlan)?.label ?? result.chosenPlan
  return `tu avais choisi « ${chosen} »`
}

/** The side to move asked, as the run's `side` names it. */
export const SIDE_OPTIONS = [
  { value: 'both', label: 'Les deux' },
  { value: 'white', label: 'Blancs' },
  { value: 'black', label: 'Noirs' }
]

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
  if (!results.played) return 'Aucune position jouée.'
  const s = (/** @type {number} */ n) => (n > 1 ? 's' : '')
  const parts = [
    `${results.played} position${s(results.played)}`,
    `${results.exact} exacte${s(results.exact)} (${formatRate(results.exact / results.played)})`
  ]
  if (results.close) parts.push(`${results.close} à un cran`)
  if (results.planOk)
    parts.push(`${results.planOk} plan${s(results.planOk)} trouvé${s(results.planOk)}`)
  return parts.join(' · ')
}

/**
 * The thresholds in pawns, White's point of view: "0,7" and "2".
 *
 * @param {number} cp
 */
export function pawns(cp) {
  return String(cp / 100).replace('.', ',')
}

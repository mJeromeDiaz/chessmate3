import { describe, expect, it } from 'vitest'
import {
  choiceLabel,
  clock,
  evaluationFeedback,
  gaugeX,
  guessX,
  pawns,
  planResult,
  positionsFor,
  resultsText
} from '@/utils/evaluation'

/** @returns {import('@/utils/evaluation').EvaluationResult} */
const result = (/** @type {object} */ over = {}) => ({
  status: 'exact',
  guess: 1,
  category: 1,
  evalCp: 120,
  engine: '+1,2',
  plan: 'king_attack',
  planLabel: 'Attaque sur le roi',
  chosenPlan: null,
  planOk: null,
  ideas: ['Le roi noir est exposé.'],
  source: null,
  durationMs: 65_000,
  fast: false,
  ...over
})

const PLANS = [
  { value: 'king_attack', label: 'Attaque sur le roi' },
  { value: 'simplify', label: 'Simplifier en finale' }
]

describe('position evaluation', () => {
  it('shows the clock rounded up, never below zero', () => {
    expect(clock(65_000)).toBe('1:05')
    expect(clock(64_001)).toBe('1:05')
    expect(clock(9_000)).toBe('0:09')
    expect(clock(-500)).toBe('0:00')
  })

  it('places evaluations and answers on the gauge, within its ends', () => {
    expect(gaugeX(0)).toBe('50%')
    expect(gaugeX(300)).toBe('97%')
    expect(gaugeX(-10_000)).toBe('3%')
    expect(guessX(0)).toBe('50%')
    expect(guessX(2)).toBe('97%')
    expect(guessX(-1)).toBe(gaugeX(-150))
  })

  it('names an answer, none when time ran out', () => {
    expect(choiceLabel(-2)).toBe('Noirs gagnent')
    expect(choiceLabel(null)).toBe('—')
  })

  it('gives an exact answer the win sheet, titles in turn', () => {
    const first = evaluationFeedback(result(), 1)
    expect(first).toMatchObject({ kind: 'win', palette: null, badge: '?' })
    expect(first.title).toBe('Promotion !')
    expect(first.sub).toBe('Évaluation exacte · 1:05')
    expect(evaluationFeedback(result(), 4).title).toBe('Promotion !')
    expect(evaluationFeedback(result({ planOk: true }), 2)).toMatchObject({
      title: 'Analyse royale !',
      sub: 'Évaluation et plan exacts'
    })
  })

  it('draws a close answer in yellow, a timeout with a clock', () => {
    expect(
      evaluationFeedback(result({ status: 'close', guess: 0 }), 1)
    ).toMatchObject({
      kind: 'miss',
      palette: 'help',
      badge: '?!',
      sub: 'À un cran du moteur (+1,2)'
    })
    expect(
      evaluationFeedback(result({ status: 'timeout', guess: null }), 1)
    ).toMatchObject({
      kind: 'miss',
      palette: null,
      badge: '⏱︎',
      title: 'Temps écoulé'
    })
    expect(
      evaluationFeedback(result({ status: 'miss', guess: -1 }), 1)
    ).toMatchObject({ badge: '?', title: 'Pas cette fois' })
  })

  it('tells the plan’s outcome, nothing when the position asks none', () => {
    expect(planResult(result({ plan: null }), PLANS)).toBe('')
    expect(planResult(result(), PLANS)).toBe('aucun plan choisi')
    expect(
      planResult(result({ chosenPlan: 'king_attack', planOk: true }), PLANS)
    ).toBe('✓ bien trouvé (+5 XP)')
    expect(
      planResult(result({ chosenPlan: 'simplify', planOk: false }), PLANS)
    ).toBe('tu avais choisi « Simplifier en finale »')
  })

  it('counts the positions of a side to move', () => {
    const positions = { white: 3, black: 1 }
    expect(positionsFor(positions, 'white')).toBe(3)
    expect(positionsFor(positions, 'black')).toBe(1)
    expect(positionsFor(positions, 'both')).toBe(4)
  })

  it('sums up the results, rate rounded down', () => {
    const none = {
      played: 0,
      exact: 0,
      close: 0,
      miss: 0,
      timeout: 0,
      planOk: 0
    }
    expect(resultsText(none)).toBe('Aucune position jouée.')
    expect(
      resultsText({ ...none, played: 3, exact: 2, close: 1, planOk: 1 })
    ).toBe('3 positions · 2 exactes (66 %) · 1 à un cran · 1 plan trouvé')
    expect(resultsText({ ...none, played: 1, timeout: 1 })).toBe(
      '1 position · 0 exacte (0 %)'
    )
  })

  it('writes the thresholds in pawns', () => {
    expect(pawns(70)).toBe('0,7')
    expect(pawns(200)).toBe('2')
  })
})

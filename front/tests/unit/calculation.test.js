import { describe, expect, it } from 'vitest'
import {
  after,
  leftMs,
  levelText,
  lineOver,
  matePlies,
  metronomeMood,
  questionText,
  questionVerdict,
  streakMood,
  verdictText
} from '@/utils/calculation'

// A sample mate in 2 (GlnnF), once c5c6 is played: black mates by Rxh4+ gxh4 Qg2#.
const FEN = '7r/p3kp2/1p3p2/2Pqp1r1/7P/P3Q1P1/5P1K/2R1R3 w - - 0 36'
const start = after(FEN, ['c5c6'])

describe('calculation', () => {
  it('ends a line at the mate, or at the mate’s length', () => {
    expect(matePlies(1)).toBe(1)
    expect(matePlies(3)).toBe(5)
    expect(lineOver(start, [], 2)).toBe(false)
    expect(lineOver(start, ['h8h4'], 2)).toBe(false)
    expect(lineOver(start, ['h8h4', 'g3h4', 'd5g2'], 2)).toBe(true)
    expect(lineOver(start, ['h8h4', 'h2g1', 'd5d4'], 2)).toBe(true)
    // Its length hidden: up to a mate in 4, unless it mates.
    expect(lineOver(start, ['h8h4', 'h2g1', 'd5d4'], null)).toBe(false)
    expect(lineOver(start, ['h8h4', 'g3h4', 'd5g2'], null)).toBe(true)
  })

  it('names a line’s level, never the Métronome’s', () => {
    expect(levelText({ mate: 3, moves: 3, ending: 'mate' })).toBe('Mat en 3')
    expect(levelText({ mate: null, moves: 3, ending: 'material' })).toBe(
      'Gain en 3 coups'
    )
    expect(levelText({ mate: null, moves: 4, ending: null })).toBe('4 coups')
    expect(levelText({ mate: null, moves: null, ending: null })).toBe('')
  })

  it('counts the time left', () => {
    expect(leftMs(1000, 2, 2500)).toBe(500)
    expect(leftMs(1000, 2, 9000)).toBe(0)
  })

  it('gives the solution, and the player’s line when it differs', () => {
    const expected = [{ san: 'Rxh4+' }, { san: 'gxh4' }, { san: 'Qg2#' }]
    expect(verdictText({ status: 'right', expected }, start)).toBe(
      '36… Txh4+ 37. gxh4 Dg2#'
    )
    expect(
      verdictText(
        {
          status: 'wrong',
          expected,
          answer: [{ san: 'Rxh4+' }, { san: 'Kg1' }],
          mistakeAt: 1
        },
        start
      )
    ).toBe(
      'Écart au demi-coup 2 · Solution : 36… Txh4+ 37. gxh4 Dg2# · Ta ligne : 36… Txh4+ 37. Rg1'
    )
    expect(verdictText({ status: 'late', expected, answer: [] }, start)).toBe(
      'Le temps de jouer était écoulé. · Solution : 36… Txh4+ 37. gxh4 Dg2#'
    )
  })
})

describe('the Métronome', () => {
  it('shows its mood by the streak, the clock and the verdict', () => {
    const base = {
      phase: 'think',
      streak: 0,
      thinkLeftRatio: 1,
      thoughtRatio: null
    }
    expect(metronomeMood(base)).toBe('neutral')
    expect(metronomeMood({ ...base, thinkLeftRatio: 0.4 })).toBe('doubt')
    expect(metronomeMood({ ...base, phase: 'answer', streak: 6 })).toBe('anger')
    expect(streakMood(3)).toBe('sadness')
    expect(streakMood(9)).toBe('focus')
    expect(streakMood(11)).toBe('anxiety')
    expect(metronomeMood({ ...base, result: { status: 'wrong' } })).toBe('joy')
    expect(metronomeMood({ ...base, result: { status: 'late' } })).toBe('pride')
    expect(
      metronomeMood({
        ...base,
        thoughtRatio: 0.2,
        result: { status: 'right', streak: 1 }
      })
    ).toBe('surprise')
    expect(
      metronomeMood({
        ...base,
        thoughtRatio: 0.8,
        result: { status: 'right', streak: 9 }
      })
    ).toBe('focus')
  })
})

describe('the question at the end of a line', () => {
  it('asks it in plain words, and gives the answer once wrong', () => {
    expect(questionText({ ask: 'where', target: 'q' })).toBe(
      'Au bout de la ligne, où est la dame noire ?'
    )
    expect(questionText({ ask: 'what', target: 'h4' })).toBe(
      'Au bout de la ligne, qu’y a-t-il en h4 ?'
    )
    expect(
      questionVerdict(
        { ask: 'where', target: 'q' },
        { status: 'wrong', expected: ['g2'] }
      )
    ).toBe('La réponse : g2.')
    expect(
      questionVerdict(
        { ask: 'what', target: 'h4' },
        { status: 'wrong', expected: ['P'] }
      )
    ).toBe('En h4 : un pion blanc.')
    expect(
      questionVerdict(
        { ask: 'what', target: 'h8' },
        { status: 'wrong', expected: [] }
      )
    ).toBe('La case h8 était vide.')
    expect(
      questionVerdict(
        { ask: 'what', target: 'h8' },
        { status: 'right', expected: [] }
      )
    ).toBe('')
  })
})

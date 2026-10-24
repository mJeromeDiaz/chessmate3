import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import {
  advocateMood,
  advocateNote,
  after,
  finalScore,
  forcingMoves,
  holds,
  judgeLine,
  leftMs,
  levelText,
  lineOver,
  matePlies,
  metronomeMood,
  offSolution,
  pickDecoy,
  pickDecoys,
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

describe('chapter 3, judged by the engine', () => {
  it('takes a half-move within the tolerance, a mate kept as fast, any defence when mated', () => {
    // Scores from the side to move: 100 before, the opponent at −80 after (a loss of 20).
    expect(holds({ cp: 100 }, { cp: -80 }, 50)).toBe(true)
    expect(holds({ cp: 100 }, { cp: -30 }, 50)).toBe(false)
    expect(holds({ cp: 100 }, { cp: -300 }, 50)).toBe(true)
    // Mate in 2: mated in 1 after it, or mated already after a mate in 1.
    expect(holds({ mate: 2 }, { mate: -1 }, 50)).toBe(true)
    expect(holds({ mate: 1 }, { mate: 0 }, 50)).toBe(true)
    expect(holds({ mate: 2 }, { mate: -2 }, 50)).toBe(false)
    expect(holds({ mate: 2 }, { cp: -900 }, 50)).toBe(false)
    // Mated in 2 either way: any defence holds.
    expect(holds({ mate: -2 }, { mate: 1 }, 50)).toBe(true)
    expect(holds({ cp: 0 }, { mate: 3 }, 50)).toBe(false)
  })

  it('judges a line half-move by half-move, both sides', () => {
    expect(
      judgeLine([{ cp: 100 }, { cp: -90 }, { cp: 95 }, { cp: -400 }], 50)
    ).toEqual({ right: true, mistakeAt: null })
    // The defence imagined is 200 worse than the best one: the line goes off there.
    expect(
      judgeLine([{ cp: 100 }, { cp: -90 }, { cp: 290 }, { cp: -300 }], 50)
    ).toEqual({ right: false, mistakeAt: 1 })
    expect(judgeLine([{ mate: 1 }, { mate: 0 }], 50)).toEqual({
      right: true,
      mistakeAt: null
    })
  })

  it('scores a mate and a stalemate without the engine', () => {
    expect(finalScore(after(start, ['h8h4', 'g3h4', 'd5g2']))).toEqual({
      mate: 0
    })
    expect(finalScore('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1')).toEqual({ cp: 0 })
    expect(finalScore(start)).toBeNull()
  })

  it('finds where a line leaves the solution', () => {
    expect(offSolution(['a', 'b'], ['a', 'b'])).toBeNull()
    expect(offSolution(['a', 'c'], ['a', 'b'])).toBe(1)
    expect(offSolution(['a'], ['a', 'b'])).toBe(1)
  })

  it('draws a decoy: natural and bad first, else the least worse bad, else the weakest', () => {
    const line = (move, cp) => ({ move, score: { cp }, pv: [move], depth: 12 })
    // Rxg3 takes a pawn: natural; Qd4 is quiet.
    const lines = [
      line('h8h4', 900),
      line('d5d4', 300),
      line('g5g3', 100),
      line('a7a5', -200)
    ]
    expect(pickDecoy(start, 'h8h4', lines, 150)).toBe('g5g3')
    expect(
      pickDecoy(
        start,
        'h8h4',
        [line('h8h4', 900), line('d5d4', 300), line('a7a5', 200)],
        150
      )
    ).toBe('d5d4')
    expect(
      pickDecoy(start, 'h8h4', [line('h8h4', 900), line('d5d4', 850)], 150)
    ).toBe('d5d4')
    // The engine down: a capture or a check, never the solution's reply.
    const drawn = pickDecoy(start, 'h8h4', null, 150, () => 0)
    expect(drawn).not.toBe('h8h4')
    const move = new Chess(start)
      .moves({ verbose: true })
      .find(m => m.lan === drawn)
    expect(!!move?.captured || /[+#]/.test(move?.san ?? '')).toBe(true)
  })

  it('names a mistake in the second line of 3.2', () => {
    const expected = [{ san: 'Rxh4+' }, { san: 'gxh4' }, { san: 'Qg2#' }]
    expect(
      verdictText(
        {
          status: 'wrong',
          expected,
          answer: [{ san: 'Rxh4+' }, { san: 'gxh4' }, { san: 'Qg2#' }],
          second: [{ san: 'Rxh4+' }, { san: 'Kg1' }, { san: 'Qd4' }],
          mistakeAt: 5
        },
        start
      )
    ).toBe(
      'Écart au demi-coup 3 de la seconde ligne · Solution : 36… Txh4+ 37. gxh4 Dg2# · Ta ligne : 36… Txh4+ 37. gxh4 Dg2# · Ta seconde ligne : 36… Txh4+ 37. Rg1 Dd4'
    )
  })
})

describe('the mini-boss of chapter 3', () => {
  // After a7a6: Rd8+ (the solution), Bxf7+ and Bxa6.
  const COMBO = after('6k1/p4pp1/7p/4r3/2B5/8/5PPP/3R2K1 b - - 0 1', ['a7a6'])
  const line = (move, cp) => ({ move, score: { cp }, pv: [move], depth: 12 })

  it('offers the forcing moves: checks and captures', () => {
    expect(forcingMoves(COMBO).sort()).toEqual(['c4a6', 'c4f7', 'd1d8'])
  })

  it('draws the refuted ones first, the least worse first, then the weakest', () => {
    const forcing = ['d1d8', 'c4f7', 'c4a6']
    expect(
      pickDecoys(
        forcing,
        'd1d8',
        [line('d1d8', 300), line('c4a6', 100), line('c4f7', -200)],
        150,
        2
      )
    ).toEqual(['c4a6', 'c4f7'])
    // Bxa6 holds as well as Rd8+: Bxf7+ first, then the weakest other.
    expect(
      pickDecoys(
        forcing,
        'd1d8',
        [line('c4a6', 320), line('d1d8', 300), line('c4f7', -200)],
        150,
        2
      )
    ).toEqual(['c4f7', 'c4a6'])
    // The engine down: drawn among the others.
    expect(pickDecoys(forcing, 'd1d8', null, 150, 2, () => 0)).toEqual([
      'c4f7',
      'c4a6'
    ])
  })
})

describe('the Avocat du diable', () => {
  it('tells whether the idea held, and its refutation was found', () => {
    expect(advocateNote({ status: 'wrong', mistakeAt: 0 }, true)).toBe(
      'Ton idée ne tenait pas, mais tu as trouvé sa réfutation.'
    )
    expect(advocateNote({ status: 'wrong', mistakeAt: 0 }, false)).toContain(
      'l’adversaire avait mieux'
    )
    expect(advocateNote({ status: 'wrong', mistakeAt: 0 }, null)).toBe(
      'Ton idée ne tenait pas.'
    )
    expect(advocateNote({ status: 'wrong', mistakeAt: 1 }, null)).toContain(
      'avocat trop indulgent'
    )
    expect(advocateNote({ status: 'wrong', mistakeAt: 3 }, null)).toBe('')
    expect(advocateNote({ status: 'right' }, null)).toBe('')
    expect(advocateNote({ status: 'late', mistakeAt: 0 }, true)).toBe('')
  })

  it('shows his face: focused, doubtful, then delighted or sad', () => {
    expect(advocateMood({ phase: 'think', thinkLeftRatio: 0.8 })).toBe('focus')
    expect(advocateMood({ phase: 'think', thinkLeftRatio: 0.3 })).toBe('doubt')
    expect(advocateMood({ phase: 'answer', thinkLeftRatio: 1 })).toBe('neutral')
    const state = { phase: 'complete', thinkLeftRatio: 1 }
    expect(advocateMood({ ...state, result: { status: 'wrong' } })).toBe('joy')
    expect(advocateMood({ ...state, result: { status: 'late' } })).toBe('pride')
    expect(advocateMood({ ...state, result: { status: 'right' } })).toBe(
      'sadness'
    )
  })
})

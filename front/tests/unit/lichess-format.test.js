import { describe, expect, it } from 'vitest'
import {
  evalSide,
  formatEval,
  formatGames,
  numberedLine,
  resultShares
} from '@/utils/chess/lichess'

describe('Lichess panel formatting', () => {
  it('writes evaluations from White’s point of view', () => {
    expect(formatEval({ cp: 25, mate: null })).toBe('+0.25')
    expect(formatEval({ cp: -130, mate: null })).toBe('−1.30')
    expect(formatEval({ cp: 0, mate: null })).toBe('0.00')
    expect(formatEval({ cp: null, mate: 3 })).toBe('#3')
    expect(formatEval({ cp: null, mate: -2 })).toBe('#−2')
    expect(evalSide({ cp: 20, mate: null })).toBe('equal')
    expect(evalSide({ cp: -80, mate: null })).toBe('black')
    expect(evalSide({ cp: null, mate: 1 })).toBe('white')
  })

  it('splits results in percents adding up to 100', () => {
    expect(resultShares({ white: 1, draws: 1, black: 1 })).toEqual({
      white: 34,
      draws: 33,
      black: 33
    })
    expect(resultShares({ white: 12, draws: 10, black: 8 })).toEqual({
      white: 40,
      draws: 33,
      black: 27
    })
    expect(resultShares({ white: 0, draws: 0, black: 0 })).toBeNull()
  })

  it('shortens game counts', () => {
    expect(formatGames(950)).toBe('950')
    expect(formatGames(9500)).toMatch(/^9\s?500$/)
    expect(formatGames(12_345)).toBe('12k')
    expect(formatGames(1_234_567)).toBe('1,2M')
  })

  it('numbers an engine line from its position', () => {
    expect(numberedLine(['e5', 'Nf3', 'Nc6'], { turn: 'b', depth: 1 })).toBe(
      '1…e5 2.Nf3 Nc6'
    )
    expect(numberedLine(['Nf3', 'c5'], { turn: 'w', depth: 6 })).toBe(
      '4.Nf3 c5'
    )
  })
})

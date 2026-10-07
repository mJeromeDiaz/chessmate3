import { describe, expect, it } from 'vitest'
import { lengthText, levelText, puzzleCountsText } from '@/utils/blindfold'

describe('blindfold puzzles', () => {
  it('names a level with its ratings and a length, the longest one open-ended', () => {
    expect(levelText({ key: 'medium', min: 1000, max: 1400 })).toBe(
      'Moyen · 1000–1400'
    )
    expect(lengthText(2, [2, 3, 4])).toBe('2 coups')
    expect(lengthText(4, [2, 3, 4])).toBe('4 coups et +')
    expect(lengthText(4)).toBe('4 coups')
  })

  it('sums up the results, rate rounded down', () => {
    expect(
      puzzleCountsText({ played: 0, solved: 0, helped: 0, failed: 0 })
    ).toBe('Aucun puzzle joué.')
    expect(
      puzzleCountsText({ played: 3, solved: 2, helped: 1, failed: 0 })
    ).toBe('2 résolus sur 3 (66 %) · 1 avec coup d’œil')
    expect(
      puzzleCountsText({ played: 2, solved: 1, helped: 0, failed: 1 })
    ).toBe('1 résolu sur 2 (50 %)')
  })
})

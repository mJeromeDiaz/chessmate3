import { describe, expect, it } from 'vitest'
import {
  RUSH_DEFAULTS,
  accuracyText,
  rushAccuracy,
  rushLines,
  rushMinutes,
  rushProgress,
  rushRecordNote,
  rushRing,
  rushScore,
  speedText
} from '@/utils/puzzleRush'
import { resultKind, resultProf, resultScore } from '@/utils/runResult'

/** A closed rush: its normalized summary and the metrics the API freezes. */
const rush = (solved, failed, metrics = {}) => ({
  module: 'puzzle_rush',
  summary: {
    itemCount: solved + failed,
    successCount: solved,
    durationMs: 180_000,
    metrics: {
      solved,
      failed,
      accuracy: rushAccuracy(solved, failed),
      puzzlesPerMinute: Math.round((solved / 3) * 100) / 100,
      score: rushScore(solved, failed),
      errorWeight: 2,
      ranked: true,
      recordBefore: null,
      record: false,
      ...metrics
    }
  }
})

describe('puzzle rush', () => {
  it('wipes out two puzzles solved per miss, never below 0', () => {
    expect(RUSH_DEFAULTS.errorWeight).toBe(2)
    // Fast and sloppy against slower and accurate: the accurate one wins.
    expect(rushScore(40, 15)).toBe(10)
    expect(rushScore(25, 2)).toBe(21)
    expect(rushScore(3, 5)).toBe(0)
    expect(rushScore(0, 0)).toBe(0)
    expect(rushScore(10, 2, 3)).toBe(4)
  })

  it('has no accuracy before a puzzle is played, rounded to 1 % after', () => {
    expect(rushAccuracy(0, 0)).toBeNull()
    expect(rushAccuracy(0, 3)).toBe(0)
    expect(rushAccuracy(5, 1)).toBe(0.83)
    expect(rushAccuracy(10, 1)).toBe(0.91)
    expect(accuracyText(null)).toBe('—')
    expect(accuracyText(0.83)).toBe('83 %')
  })

  it('shows the counts and the accuracy while playing, never the score', () => {
    expect(rushProgress(0, 0)).toBe('✓ 0 · ✗ 0 · précision —')
    expect(rushProgress(5, 1)).toBe('✓ 5 · ✗ 1 · précision 83 %')
    // 4 solved, 1 failed: score 2, a digit the line never shows.
    expect(rushProgress(4, 1)).toBe('✓ 4 · ✗ 1 · précision 80 %')
    expect(rushProgress(4, 1)).not.toContain('2')
  })

  it('judges the run on its accuracy but scores it in points', () => {
    expect(resultKind(rush(10, 0))).toBe('perfect')
    expect(resultKind(rush(5, 1))).toBe('close')
    expect(resultKind(rush(40, 15))).toBe('fail')
    expect(resultKind(rush(0, 0))).toBeNull()
    expect(resultScore(rush(5, 1))).toBe(3)
    expect(resultScore(rush(40, 15))).toBe(10)
    expect(rushRing(rush(5, 1))).toBe(83)
    expect(rushRing(rush(0, 0))).toBe(0)
    expect(resultProf('puzzle_rush', 'perfect').name).toBe('Albert Stein')
  })

  it('says speed and accuracy, the score, the record and the XP', () => {
    expect(rushLines(rush(5, 1, { record: true }), 6)).toEqual([
      '1,67 puzzles/min · 83 % de précision',
      'Score : 5 réussis − 2 × 1 = 3 points',
      'Ton premier record !',
      '+6 XP'
    ])
    // Not ranked, no XP yet: no record line, no XP line.
    const unranked = rush(2, 1, { ranked: false, record: false })
    expect(rushLines(unranked, null)).toEqual([
      '0,67 puzzles/min · 67 % de précision',
      'Score : 2 réussis − 2 × 1 = 0 points',
      'Hors records (arrêté avant la fin, ou avec des thèmes)'
    ])
    expect(speedText(null)).toBe('—')
  })

  it('names the record: beaten, to beat or out of the records', () => {
    const note = (recordBefore, record) =>
      rushRecordNote({ ranked: true, recordBefore, record })
    expect(note(12, false)).toBe('Ton record : 12')
    expect(note(12, true)).toBe('Nouveau record ! Ancien : 12')
    expect(note(null, true)).toBe('Ton premier record !')
    expect(note(null, false)).toBe('')
    expect(rushRecordNote({ ranked: false })).toContain('Hors records')
  })

  it('offers the API lengths in minutes, 3 first', () => {
    expect(rushMinutes(null)).toEqual({ minutes: [2, 3, 5], default: 3 })
    expect(rushMinutes({ budgets: [120, 300], defaultBudget: 300 })).toEqual({
      minutes: [2, 5],
      default: 5
    })
  })
})

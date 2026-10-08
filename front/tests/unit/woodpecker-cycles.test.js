import { describe, expect, it } from 'vitest'
import {
  cycleDays,
  cycleSchedule,
  deadlineDay,
  explainCycles
} from '@/utils/woodpecker'

const CLASSIC = {
  mode: 'classic',
  puzzleCount: 50,
  cycleCount: 5,
  firstCycleDays: 28,
  reductionFactor: 0.5,
  minCycleDays: 1,
  restDays: 1,
  shuffle: false
}

describe('cycle lengths (mirror of DeadlineCalculator::cycleDays)', () => {
  it('halves each cycle, rounded up', () => {
    expect(cycleSchedule(CLASSIC)).toEqual([28, 14, 7, 4, 2])
  })

  it('never goes below the minimum', () => {
    expect(cycleDays({ ...CLASSIC, minCycleDays: 3 }, 5)).toBe(3)
    expect(cycleDays({ ...CLASSIC, minCycleDays: 0 }, 9)).toBe(1)
  })

  it('has no schedule in light mode', () => {
    expect(
      cycleSchedule({
        cycleCount: null,
        firstCycleDays: null,
        reductionFactor: null,
        minCycleDays: null
      })
    ).toEqual([])
  })
})

describe('deadlineDay', () => {
  it('names the last local day before the deadline', () => {
    // End of 2026-10-21 in Paris (summer time): 22:00 UTC.
    expect(deadlineDay('2026-10-21T22:00:00+00:00', 'Europe/Paris')).toBe(
      'mercredi 21 octobre'
    )
  })
})

describe('explainCycles', () => {
  it('words the classic method from the set settings', () => {
    const text = explainCycles(CLASSIC)
      .map(p => p.text)
      .join(' ')
    expect(text).toContain('les 50 puzzles du set')
    expect(text).toContain('28 j → 14 j → 7 j → 4 j → 2 j')
    expect(text).toContain('1 jour de repos')
    expect(text).toContain('toujours dans le même ordre')
  })

  it('mentions the shuffle and the absence of rest', () => {
    const text = explainCycles({ ...CLASSIC, shuffle: true, restDays: 0 })
      .map(p => p.text)
      .join(' ')
    expect(text).toContain('mélangé')
    expect(text).toContain('aucun repos')
  })

  it('explains the light mode without deadlines', () => {
    const points = explainCycles({
      ...CLASSIC,
      mode: 'light',
      puzzleCount: 30
    })
    expect(points.map(p => p.title)).toContain('Un set qui grandit')
    expect(points.map(p => p.text).join(' ')).not.toContain('échéance')
  })
})

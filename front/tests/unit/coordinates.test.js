import { describe, expect, it } from 'vitest'
import {
  formatAnswerTime,
  missedSquares,
  ribbonCells,
  ribbonTotals,
  rulesText,
  validationText
} from '@/utils/coordinates'
import { formatRate } from '@/utils/format'

const RULES = { seriesSeconds: 300, minAnswers: 50, minSuccessRate: 0.95 }

/**
 * @param {number} index
 * @param {string} target
 * @param {string} clicked
 * @param {number|null} [durationMs]
 */
const answer = (
  index,
  target,
  clicked,
  durationMs = 1_000
) => /** @type {import('@/utils/coordinates').CoordinateItem} */ ({
  index,
  status: target === clicked ? 'ok' : 'fail',
  durationMs,
  data: { index: index - 1, target, clicked }
})

/**
 * @param {number} itemCount
 * @param {number} successCount
 * @param {Record<string, any>} [metrics]
 * @param {string} [closeReason]
 */
const run = (
  itemCount,
  successCount,
  metrics = {},
  closeReason = 'time_up'
) => ({
  closeReason,
  summary: {
    itemCount,
    successCount,
    metrics: {
      orientation: 'white',
      validated: false,
      rules: RULES,
      ...metrics
    }
  }
})

describe('the ribbon', () => {
  const items = [
    answer(1, 'e4', 'e4', 800),
    answer(2, 'd5', 'd4', 2_400),
    answer(3, 'a1', 'a1', null),
    answer(4, 'd5', 'e5', 1_000)
  ]

  it('has one cell per answer, the wrong ones telling the square clicked', () => {
    expect(ribbonCells(items).map(c => [c.correct, c.title])).toEqual([
      [true, '#1 · e4 · 0,8 s'],
      [false, '#2 · d5 → d4 · 2,4 s'],
      [true, '#3 · a1 · —'],
      [false, '#4 · d5 → e5 · 1,0 s']
    ])
  })

  it('totals the answers and averages the timed ones', () => {
    expect(ribbonTotals(items)).toEqual({
      count: 4,
      correct: 2,
      wrong: 2,
      rate: 0.5,
      averageMs: 1_400
    })
    expect(ribbonTotals([])).toEqual({
      count: 0,
      correct: 0,
      wrong: 0,
      rate: null,
      averageMs: null
    })
  })

  it('finds the squares missed most often', () => {
    expect(missedSquares(items)).toEqual([{ square: 'd5', count: 2 }])
    expect(missedSquares([answer(1, 'e4', 'e4')])).toEqual([])
  })
})

describe('validationText', () => {
  it('says what a series lacked to validate', () => {
    expect(validationText(run(60, 60, { validated: true }))).toBe(
      'Blancs validés !'
    )
    expect(validationText(run(60, 60, {}, 'stopped'))).toBe(
      'Série arrêtée avant la fin : elle ne valide pas.'
    )
    expect(validationText(run(49, 49))).toBe(
      '49 réponses : il en faut 50 pour valider.'
    )
    expect(validationText(run(100, 94))).toBe(
      '94 % de réussite : il faut 95 % pour valider.'
    )
  })
})

describe('formats', () => {
  it('shows short times in tenths of a second', () => {
    expect(formatAnswerTime(1_234)).toBe('1,2 s')
    expect(formatAnswerTime(12_400)).toBe('12 s')
    expect(formatAnswerTime(65_000)).toBe('1 min 05')
    expect(formatAnswerTime(null)).toBe('—')
  })

  it('never rounds a rate up to the threshold', () => {
    expect(formatRate(0.9496)).toBe('94 %')
    expect(formatRate(0.95)).toBe('95 %')
    expect(formatRate(null)).toBe('—')
  })

  it('states the rules from the API', () => {
    expect(rulesText(RULES)).toBe(
      'au moins 50 réponses et 95 % de réussite en 5 min, sans arrêter la série'
    )
  })
})

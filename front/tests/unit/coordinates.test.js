import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import {
  answerText,
  colorKey,
  colorKeysHint,
  colorOf,
  durationsText,
  expectedAnswer,
  formatAnswerTime,
  ribbonCells,
  ribbonTotals,
  rulesText,
  validationText
} from '@/utils/coordinates'
import { formatRate } from '@/utils/format'

const RULES = { seriesSeconds: 300, minAnswers: 50, minSuccessRate: 0.95 }
/** The rules before a series (GET /coordinates). */
const OVERVIEW_RULES = {
  seriesMinutes: [1, 2, 5, 10],
  defaultMinutes: 2,
  minAnswersPerMinute: 10,
  minSuccessRate: 0.95
}

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

  it('leaves the pardoned answer out of the totals, ice blue on the ribbon', () => {
    const pardoned = {
      ...answer(2, 'd5', 'd4', 2_400),
      status: /** @type {'hint'} */ ('hint'),
      data: { index: 1, target: 'd5', clicked: 'd4', pardoned: true }
    }
    const withPardon = [items[0], pardoned, items[2], items[3]]
    expect(
      ribbonCells(withPardon).map(c => [c.correct, c.pardoned, c.title])
    ).toEqual([
      [true, false, '#1 · e4 · 0,8 s'],
      [false, true, '#2 · d5 → d4 · 2,4 s · pardonnée'],
      [true, false, '#3 · a1 · —'],
      [false, false, '#4 · d5 → e5 · 1,0 s']
    ])
    expect(ribbonTotals(withPardon)).toEqual({
      count: 3,
      correct: 2,
      wrong: 1,
      rate: 2 / 3,
      averageMs: 900
    })
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

describe('modes', () => {
  it('knows the colour of each square and what each mode expects', () => {
    expect(['a1', 'h1', 'a8', 'h8', 'e4', 'd4'].map(colorOf)).toEqual([
      'dark',
      'light',
      'light',
      'dark',
      'light',
      'dark'
    ])
    expect(expectedAnswer('find', 'e4')).toBe('e4')
    expect(expectedAnswer('name', 'e4')).toBe('e4')
    expect(expectedAnswer('color', 'e4')).toBe('light')
    expect(answerText('dark')).toBe('foncée')
    expect(answerText('f5')).toBe('f5')
  })

  it('names a colour answer in the ribbon, and never speaks of validation for these modes', () => {
    const [cell] = ribbonCells([
      {
        index: 1,
        status: 'fail',
        durationMs: 800,
        data: { index: 0, target: 'e4', clicked: 'dark', mode: 'color' }
      }
    ])
    expect(cell.title).toContain('e4 → foncée')
    expect(validationText(run(60, 60, { mode: 'color' }))).toBe(
      'Série « Couleur de la case » : pas de validation d’orientation dans ce mode.'
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
    expect(rulesText(OVERVIEW_RULES)).toBe(
      'au moins 10 réponses par minute et 95 % de réussite, sans arrêter la série'
    )
    expect(durationsText(OVERVIEW_RULES)).toBe('1, 2, 5 ou 10 min')
    expect(durationsText({ ...OVERVIEW_RULES, seriesMinutes: [5] })).toBe(
      '5 min'
    )
  })
})

describe('colour keys', () => {
  afterEach(() => {
    i18n.global.locale.value = 'fr'
  })

  it('answers with the initials of the UI language, or the arrows', () => {
    expect(colorKey('c')).toBe('light')
    expect(colorKey('F')).toBe('dark')
    expect(colorKey('l')).toBeNull()
    expect(colorKey('ArrowLeft')).toBe('light')
    expect(colorKey('ArrowRight')).toBe('dark')
    expect(colorKeysHint()).toBe(
      'Clavier : C ou ← pour claire, F ou → pour foncée.'
    )

    i18n.global.locale.value = 'en'
    expect(colorKey('l')).toBe('light')
    expect(colorKey('D')).toBe('dark')
    expect(colorKey('c')).toBeNull()
    expect(colorKey('f')).toBeNull()
    expect(colorKey('ArrowLeft')).toBe('light')
    expect(colorKeysHint()).toBe('Keyboard: L or ← for light, D or → for dark.')
  })
})

import { describe, expect, it } from 'vitest'
import {
  WON_CP,
  bodyFrom,
  category,
  evalLabel,
  formFrom,
  nearBorder,
  parseEval,
  turnOf
} from '@/utils/admin/evaluation'

describe('admin position form', () => {
  it('reads an evaluation as chess players write it', () => {
    expect(['+1,4', '1.4', '-0,3', '−0.3', '0', ' +2 '].map(parseEval)).toEqual(
      [140, 140, -30, -30, 0, 200]
    )
    expect(['gagné', '+−', '1-0', 'perdu', '−+', '0-1'].map(parseEval)).toEqual(
      [WON_CP, WON_CP, WON_CP, -WON_CP, -WON_CP, -WON_CP]
    )
    expect(['', 'bien', '1,4,2'].map(parseEval)).toEqual([null, null, null])
    expect(parseEval('150')).toBe(WON_CP)
  })

  it('labels, categories and borders like the server', () => {
    expect([140, -30, 0, WON_CP, -WON_CP].map(evalLabel)).toEqual([
      '+1,4',
      '−0,3',
      '0,0',
      '+−',
      '−+'
    ])
    expect([0, 69, 70, 199, 200, -70, -200].map(category)).toEqual([
      0, 0, 1, 1, 2, -1, -2
    ])
    expect([190, 60, 120, WON_CP].map(nearBorder)).toEqual([
      true,
      true,
      false,
      false
    ])
    expect(turnOf('8/8/8/8/8/8/8/K6k b - - 0 1')).toBe('black')
    expect(turnOf('nonsense')).toBeNull()
  })

  it('builds the body, or says what is missing', () => {
    const form = formFrom()
    expect(bodyFrom(form).error).toBe('La FEN est obligatoire.')
    form.fen = ' 8/8/8/8/8/8/8/K6k w - - 0 1 '
    form.eval = 'bof'
    expect(bodyFrom(form).error).toMatch('Évaluation illisible')
    form.eval = '+1,4'
    expect(bodyFrom(form).error).toBe('Au moins un libellé est obligatoire.')
    form.ideas[1] = '  Le fou c8 est bloqué.  '
    expect(bodyFrom(form).body).toEqual({
      fen: '8/8/8/8/8/8/8/K6k w - - 0 1',
      evalCp: 140,
      ideas: ['Le fou c8 est bloqué.'],
      plan: null,
      tip: null,
      tag: null,
      rating: 1500,
      source: null,
      active: true
    })

    const filled = formFrom({
      fen: 'f',
      evalCp: -30,
      ideas: ['Un.'],
      plan: 'simplify',
      tip: null,
      tag: 'endgame',
      rating: 1800,
      source: null,
      active: false
    })
    expect([filled.eval, filled.ideas, filled.plan, filled.active]).toEqual([
      '-0,3',
      ['Un.', '', ''],
      'simplify',
      false
    ])
  })
})

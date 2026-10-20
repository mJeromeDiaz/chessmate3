import { describe, expect, it } from 'vitest'
import {
  correctionHighlights,
  questionText,
  toggleSquare,
  verdictText
} from '@/utils/vision'

describe('vision', () => {
  it('asks its questions in plain words', () => {
    expect(questionText({ ask: 'where', target: 'k', side: null })).toBe(
      'Où est le roi noir ?'
    )
    expect(questionText({ ask: 'what', target: 'e5', side: null })).toBe(
      'Qu’y a-t-il en e5 ?'
    )
    expect(questionText({ ask: 'en_prise', target: null, side: null })).toBe(
      'Quelle pièce est en prise ?'
    )
    expect(
      questionText({ ask: 'attackers', target: 'f2', side: 'black' })
    ).toBe('Quelles pièces noires attaquent f2 ?')
  })

  it('says where the right answer was', () => {
    expect(
      verdictText({ ask: 'where', target: 'R' }, { expected: ['a1'] })
    ).toBe('Une tour blanche était en a1.')
    expect(verdictText({ ask: 'what', target: 'e5' }, { expected: [''] })).toBe(
      'En e5, il n’y avait rien.'
    )
    expect(
      verdictText({ ask: 'what', target: 'e5' }, { expected: ['n'] })
    ).toBe('En e5, il y avait un cavalier noir.')
    expect(
      verdictText(
        { ask: 'attackers', target: 'f2' },
        { expected: ['c5', 'd4'] }
      )
    ).toBe('Les attaquants : c5, d4.')
    expect(
      verdictText({ ask: 'attackers', target: 'f2' }, { expected: [] })
    ).toBe('Les attaquants : aucun.')
  })

  it('colours the correction: the right squares green, the wrong clicks red', () => {
    expect(
      correctionHighlights(
        { ask: 'attackers', target: 'f2' },
        { status: 'wrong', expected: ['c5'], answer: ['c5', 'e2'] }
      )
    ).toEqual([
      { square: 'c5', type: 'success' },
      { square: 'e2', type: 'error' }
    ])
    expect(
      correctionHighlights(
        { ask: 'what', target: 'e5' },
        { status: 'wrong', expected: ['n'], answer: [''] }
      )
    ).toEqual([{ square: 'e5', type: 'error' }])
  })

  it('chooses and unchooses an attacker', () => {
    expect(toggleSquare(['c5'], 'e2')).toEqual(['c5', 'e2'])
    expect(toggleSquare(['c5', 'e2'], 'c5')).toEqual(['e2'])
  })
})

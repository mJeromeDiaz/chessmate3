import { describe, expect, it } from 'vitest'
import {
  choiceText,
  correctionHighlights,
  gargoyleMood,
  movesText,
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

  it('asks about a piece’s path, written as it moves', () => {
    expect(
      questionText({ ask: 'arrival', target: null, side: null, piece: 'R' })
    ).toBe('Où est la tour maintenant ?')
    expect(
      questionText({ ask: 'captured', target: 'd7', side: null, piece: 'B' })
    ).toBe('Qu’a pris le fou en d7 ?')
    expect(
      questionText({ ask: 'passed', target: 'e4', side: null, piece: 'N' })
    ).toBe('Le cavalier s’est-il arrêté en e4 ?')
    expect(
      questionText({ ask: 'stops', target: null, side: null, piece: 'Q' })
    ).toBe('Sur quelles cases la dame s’est-elle arrêtée ?')
    expect(
      questionText({ ask: 'knight_jumps', target: 'b1', side: null, to: 'f7' })
    ).toBe('Combien de sauts, au minimum, pour un cavalier de b1 à f7 ?')
    const path = {
      announcedStyle: 'path',
      announced: [{ san: 'Rd1' }, { san: 'Rd5' }, { san: 'Rxd7' }]
    }
    expect(movesText(path, () => 'numbered')).toBe('Td1, Td5, Txd7')
    expect(movesText(path, () => 'numbered', 2)).toBe('Td1, Td5')
    expect(movesText({ announced: [{ san: 'Nf3' }] }, () => 'numbered')).toBe(
      'numbered'
    )
  })

  it('says the right answer to a choice', () => {
    expect([choiceText('yes'), choiceText('no'), choiceText('3')]).toEqual([
      'Oui',
      'Non',
      '3'
    ])
    expect(
      verdictText({ ask: 'knight_jumps', target: 'b1' }, { expected: ['1'] })
    ).toBe('Il fallait 1 saut.')
    expect(
      verdictText({ ask: 'knight_jumps', target: 'b1' }, { expected: ['4'] })
    ).toBe('Il fallait 4 sauts.')
    expect(
      verdictText({ ask: 'passed', target: 'e4' }, { expected: ['no'] })
    ).toBe('La réponse était « Non ».')
    expect(
      verdictText(
        { ask: 'captured', target: 'd7', piece: 'R' },
        { expected: ['n'] }
      )
    ).toBe('En d7, la tour a pris un cavalier noir.')
    expect(
      correctionHighlights(
        { ask: 'knight_jumps', target: 'b1' },
        { status: 'wrong', expected: ['4'], answer: ['3'] }
      )
    ).toEqual([])
  })

  it('asks about a position updated by moves', () => {
    expect(questionText({ ask: 'missing', target: null, side: null })).toBe(
      'Quelle pièce a disparu ?'
    )
    expect(questionText({ ask: 'captures', target: null, side: null })).toBe(
      'Où étaient les pièces prises ?'
    )
    expect(
      questionText({ ask: 'reach', target: 'c4', side: null, piece: 'b' })
    ).toBe('Le fou noir peut-il aller en c4 en un coup ?')
    expect(
      questionText({ ask: 'reach', target: 'c4', side: null, piece: 'Q' })
    ).toBe('La dame blanche peut-elle aller en c4 en un coup ?')
    expect(
      verdictText({ ask: 'missing', target: null }, { expected: ['N'] })
    ).toBe('C’était un cavalier blanc.')
    expect(
      verdictText({ ask: 'reach', target: 'c4' }, { expected: ['yes'] })
    ).toBe('La réponse était « Oui ».')
  })

  it('asks for every piece in a relation', () => {
    expect(questionText({ ask: 'attacked', target: null, side: 'black' })).toBe(
      'Quelles pièces noires sont attaquées ?'
    )
    expect(
      questionText({ ask: 'undefended', target: null, side: 'white' })
    ).toBe('Quelles pièces blanches sont sans défense ?')
    expect(questionText({ ask: 'aligned', target: 'r', side: null })).toBe(
      'Quelles pièces sont alignées avec la tour noire ?'
    )
    expect(questionText({ ask: 'winning', target: null, side: 'white' })).toBe(
      'Quelles pièces blanches peut-on prendre avec gain ?'
    )
    expect(
      verdictText({ ask: 'winning', target: null }, { expected: ['c3', 'h2'] })
    ).toBe('Les prises gagnantes : c3, h2.')
  })
})

describe('the Gargouille', () => {
  const base = {
    phase: 'show',
    position: 1,
    count: 10,
    streak: 0,
    leftRatio: 1,
    answeredRatio: null
  }

  it('reacts to the fight before the verdict', () => {
    expect(gargoyleMood(base)).toBe('neutral')
    expect(gargoyleMood({ ...base, phase: 'announce' })).toBe('focus')
    expect(gargoyleMood({ ...base, phase: 'ask', leftRatio: 0.4 })).toBe(
      'doubt'
    )
    expect(gargoyleMood({ ...base, phase: 'ask', streak: 3 })).toBe('sadness')
    expect(gargoyleMood({ ...base, position: 6 })).toBe('anger')
    expect(gargoyleMood({ ...base, position: 7 })).toBe('neutral')
    expect(gargoyleMood({ ...base, phase: 'ask', position: 9 })).toBe('anxiety')
  })

  it('reacts to the verdict', () => {
    expect(gargoyleMood({ ...base, result: { status: 'wrong' } })).toBe('joy')
    expect(gargoyleMood({ ...base, result: { status: 'late' } })).toBe('pride')
    expect(
      gargoyleMood({ ...base, answeredRatio: 0.2, result: { status: 'right' } })
    ).toBe('surprise')
    expect(
      gargoyleMood({
        ...base,
        answeredRatio: 0.8,
        streak: 2,
        result: { status: 'right' }
      })
    ).toBe('sadness')
  })
})

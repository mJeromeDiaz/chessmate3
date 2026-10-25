import { describe, expect, it } from 'vitest'
import {
  brilliancyFeedback,
  choiceForKey,
  lineSteps,
  recapLines,
  resultsText,
  reviewTitle,
  uciSquares,
  verdictGlyph
} from '@/utils/brilliancy'

/** @returns {import('@/utils/brilliancy').BrilliancyResult} */
const result = (/** @type {object} */ over = {}) => ({
  status: 'right',
  kind: 'brilliant',
  answer: 'brilliant',
  line: [],
  puzzle: { id: 'GlnnF', rating: 1820, gameUrl: null },
  durationMs: 4_000,
  ...over
})

describe('brilliancy', () => {
  it('answers with B / N and 1 / 2, nothing else', () => {
    expect(choiceForKey('b')).toBe('brilliant')
    expect(choiceForKey('B')).toBe('brilliant')
    expect(choiceForKey('n')).toBe('blunder')
    expect(choiceForKey('1')).toBe('brilliant')
    expect(choiceForKey('2')).toBe('blunder')
    expect(choiceForKey('x')).toBeNull()
    expect(choiceForKey('Enter')).toBeNull()
  })

  it('puts the badge of the kind on the arrival square', () => {
    expect(verdictGlyph('brilliant', 'h7')).toEqual({
      square: 'h7',
      text: '!!',
      tone: 'brilliant'
    })
    expect(verdictGlyph('blunder', 'f7')).toEqual({
      square: 'f7',
      text: '??',
      tone: 'blunder'
    })
    expect(uciSquares('e7e8q')).toEqual({ from: 'e7', to: 'e8' })
  })

  it('numbers the line from its position, Black first included', () => {
    const steps = lineSteps(
      'rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 1 2',
      [
        { uci: 'b8c6', san: 'Nc6' },
        { uci: 'f1b5', san: 'Bb5' },
        { uci: 'a7a6', san: 'a6' }
      ]
    )
    expect(steps.map(s => s.label)).toEqual(['2…Nc6', '3.Bb5', 'a6'])
    expect(steps[1]).toMatchObject({ from: 'f1', to: 'b5' })
    expect(steps[2].fen).toBe(
      'r1bqkbnr/1ppp1ppp/p1n5/1B2p3/4P3/5N2/PPPP1PPP/RNBQK2R w KQkq - 0 4'
    )
  })

  it('stops the line at a move that does not play, and on a broken FEN', () => {
    const fen =
      'r1bqkb1r/pppp1ppp/2n2n2/4p2Q/2B1P3/8/PPPP1PPP/RNB1K1NR w KQkq - 4 4'
    expect(
      lineSteps(fen, [
        { uci: 'h5f7', san: 'Qxf7#' },
        { uci: 'e8f7', san: 'Kxf7' }
      ]).map(s => s.label)
    ).toEqual(['4.Qxf7#'])
    expect(lineSteps('not a fen', [{ uci: 'e2e4', san: 'e4' }])).toEqual([])
  })

  it('says what the move was, right or wrong', () => {
    expect(brilliancyFeedback(result(), 1)).toMatchObject({
      kind: 'win',
      badge: '!!',
      title: 'Bien vu !',
      sub: 'Un vrai sacrifice : la combinaison gagne.'
    })
    expect(brilliancyFeedback(result(), 2).title).toBe('Exact !')
    expect(
      brilliancyFeedback(
        result({ status: 'wrong', kind: 'blunder', answer: 'brilliant' }),
        1
      )
    ).toMatchObject({
      kind: 'miss',
      badge: '??',
      title: 'Raté',
      bubble: 'Méfie-toi des coups trop beaux : celui-ci se fait punir.'
    })
  })

  it('sums up the results by kind, those met only', () => {
    const none = { played: 0, right: 0 }
    expect(
      resultsText({ played: 0, right: 0, brilliant: none, blunder: none })
    ).toBe('Aucun coup jugé pour l’instant.')
    expect(
      resultsText({
        played: 10,
        right: 7,
        brilliant: { played: 6, right: 5 },
        blunder: { played: 4, right: 2 }
      })
    ).toBe(
      '10 coups jugés · 7 justes (70 %) · brillants : 83 % · blunders : 50 %'
    )
    expect(
      resultsText({
        played: 1,
        right: 1,
        brilliant: { played: 1, right: 1 },
        blunder: none
      })
    ).toBe('1 coup jugé · 1 juste (100 %) · brillants : 100 %')
  })

  it('writes the recap and the review title', () => {
    expect(
      recapLines({
        right: 7,
        wrong: 3,
        brilliant: { played: 6, right: 5 },
        blunder: { played: 4, right: 2 }
      })
    ).toEqual([
      'Justes : 7 · faux : 3.',
      'Brillants : 5 sur 6 · blunders : 2 sur 4.'
    ])
    expect(
      reviewTitle({
        position: {
          fen: '',
          color: 'white',
          move: { uci: 'd3h7', san: 'Bxh7+' }
        },
        kind: 'brilliant'
      })
    ).toBe('Bxh7+ · Brillant')
  })
})

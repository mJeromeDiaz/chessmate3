import { describe, expect, it } from 'vitest'
import {
  correctionBoard,
  correctionRows,
  englishSan,
  localSan,
  numberedLine,
  pieceLetters,
  writtenMistake,
  writtenTokens
} from '@/utils/notation'

describe('notation', () => {
  it('shows the piece letters of the language', () => {
    expect(pieceLetters('fr')).toEqual(['R', 'D', 'T', 'F', 'C'])
    expect(pieceLetters('en')).toEqual(['K', 'Q', 'R', 'B', 'N'])
    expect(localSan('Nf3', 'fr')).toBe('Cf3')
    expect(localSan('Kxe2', 'fr')).toBe('Rxe2')
    expect(localSan('Rae1', 'fr')).toBe('Tae1')
    expect(localSan('exd8=Q+', 'fr')).toBe('exd8=D+')
    expect(localSan('O-O-O', 'fr')).toBe('O-O-O')
    expect(localSan('Nf3', 'en')).toBe('Nf3')
  })

  it('reads what the player typed back in English letters', () => {
    expect(englishSan('1. Cf3 Rxe2 2. Tae1 Fb5 Dd1', 'fr')).toBe(
      '1. Nf3 Kxe2 2. Rae1 Bb5 Qd1'
    )
    // A lowercase letter is a file, not a piece.
    expect(englishSan('cxd5 fxe6', 'fr')).toBe('cxd5 fxe6')
    expect(englishSan('0-0 0-0-0 e8=D', 'fr')).toBe('O-O O-O-O e8=Q')
    expect(englishSan('Rf1 Kg2', 'en')).toBe('Rf1 Kg2')
  })

  it('numbers a line from its position', () => {
    const start = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'
    expect(numberedLine(['e4', 'e5', 'Nf3'], start, 'fr')).toBe(
      '1. e4 e5 2. Cf3'
    )
    expect(
      numberedLine(['Bxe4', 'Qd1'], '8/8/8/8/8/8/8/k6K b - - 0 12', 'fr')
    ).toBe('12… Fxe4 13. Dd1')
    expect(numberedLine([], start, 'fr')).toBe('')
  })

  it('says why a written line is wrong', () => {
    expect(writtenMistake('illegal', 1)).toBe(
      'Le demi-coup 2 n’est pas un coup légal.'
    )
    expect(writtenMistake(null, null)).toBe('')
  })
})

describe('the correction of a written line', () => {
  // White mates in 2: Qg4+ Kf8 (or Kh8) Qg7#.
  const FEN = '4r1k1/5p1p/5P2/8/8/8/8/3Q2K1 w - - 0 1'
  const EXPECTED = [
    { uci: 'd1g4', san: 'Qg4+' },
    { uci: 'g8f8', san: 'Kf8' },
    { uci: 'g4g7', san: 'Qg7#' }
  ]

  it('reads the half-moves as the server does', () => {
    expect(writtenTokens(['1. Qg4+ Kf8 2.Qg7#'])).toEqual([
      'Qg4+',
      'Kf8',
      'Qg7#'
    ])
    expect(writtenTokens(['12... Bxe4  13. Qd1', ' '])).toEqual(['Bxe4', 'Qd1'])
  })

  it('puts each half-move written beside the solution', () => {
    const c = correctionRows(
      FEN,
      EXPECTED,
      ['1. Qg4+ Kh8 2. Qg5'],
      1,
      'wrong',
      'fr'
    )
    expect(c.rows).toEqual([
      { number: '1.', yours: 'Dg4+', right: 'Dg4+', state: 'ok' },
      { number: '1…', yours: 'Rh8', right: 'Rf8', state: 'wrong' },
      { number: '2.', yours: 'Dg5', right: 'Dg7#', state: 'after' }
    ])
    expect(c.wrongUci).toBe('g8h8')
    expect(c.fens).toHaveLength(4)
    expect(c.fens[0]).toBe(FEN)
  })

  it('marks the missing half-moves, one too many, an illegal one', () => {
    const short = correctionRows(FEN, EXPECTED, ['Qg4+'], 1, 'short', 'fr')
    expect(short.rows.map(r => [r.yours, r.state])).toEqual([
      ['Dg4+', 'ok'],
      [null, 'missing'],
      [null, 'after']
    ])
    const long = correctionRows(
      FEN,
      EXPECTED,
      ['Qg4+ Kf8 Qg7# Kf7'],
      3,
      'long',
      'en'
    )
    expect(long.rows[3]).toEqual({
      number: '2…',
      yours: 'Kf7',
      right: null,
      state: 'extra'
    })
    const illegal = correctionRows(FEN, EXPECTED, ['Kg9'], 0, 'illegal', 'en')
    expect([illegal.rows[0].state, illegal.wrongUci]).toEqual(['wrong', null])
  })

  it('shows the solution on the board, the wrong move beside the right one', () => {
    const c = correctionRows(FEN, EXPECTED, ['1. Qg4+ Kh8'], 1, 'wrong', 'fr')
    expect(correctionBoard(c, EXPECTED, 0)).toEqual({
      fen: FEN,
      highlights: [],
      arrows: []
    })
    const mistake = correctionBoard(c, EXPECTED, 1)
    expect(mistake.fen).toBe(c.fens[1])
    expect(mistake.highlights).toEqual([
      { square: 'd1', type: 'lastMove' },
      { square: 'g4', type: 'lastMove' },
      { square: 'g8', type: 'error' },
      { square: 'h8', type: 'error' }
    ])
    expect(mistake.arrows).toEqual([{ from: 'g8', to: 'f8', type: 'solution' }])
    const end = correctionBoard(c, EXPECTED, 9)
    expect([end.fen, end.arrows]).toEqual([c.fens[3], []])
  })
})

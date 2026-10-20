import { describe, expect, it } from 'vitest'
import {
  englishSan,
  localSan,
  numberedLine,
  pieceLetters,
  writtenMistake
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

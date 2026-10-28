import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import { checkedKingSquare } from '@/utils/chess/check'

describe('checkedKingSquare', () => {
  it('finds the king in check of the side to move', () => {
    // 1. e4 f5 2. Qh5+: the black king on e8.
    const chess = new Chess(
      'rnbqkbnr/ppppp1pp/8/5p1Q/4P3/8/PPPP1PPP/RNB1KBNR b KQkq - 1 2'
    )
    expect(checkedKingSquare(chess)).toBe('e8')
  })

  it('finds a checkmated king too', () => {
    // Fool's mate: the white king on e1.
    const chess = new Chess(
      'rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3'
    )
    expect(checkedKingSquare(chess)).toBe('e1')
  })

  it('gives null without a check, or without a king', () => {
    expect(checkedKingSquare(new Chess())).toBeNull()
    expect(
      checkedKingSquare(
        new Chess('8/8/8/8/8/8/8/8 w - - 0 1', { skipValidation: true })
      )
    ).toBeNull()
  })
})

import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import { materialOf } from '@/utils/chess/material'

describe('materialOf', () => {
  it('shows nothing at the start, nor after an even trade', () => {
    const none = { pieces: [], score: 0 }
    expect(materialOf(new Chess().fen())).toEqual({ white: none, black: none })
    // A knight each taken (b1 and b8 empty): nothing to show.
    expect(
      materialOf('r1bqkb1r/pppppppp/5n2/8/8/5N2/PPPPPPPP/R1BQKB1R w KQkq - 0 4')
    ).toEqual({ white: none, black: none })
  })

  it('shows the pieces a side is up, trades cancelled, and its lead', () => {
    // White took a knight and a pawn, Black took a bishop: N+P vs B, +1 for White.
    const material = materialOf(
      'r1bqkbnr/ppp1pppp/8/8/8/8/PPPPPPPP/RN1QKBNR w KQkq - 0 1'
    )
    expect(material.white).toEqual({ pieces: ['p', 'n'], score: 1 })
    expect(material.black).toEqual({ pieces: ['b'], score: 0 })
  })

  it('counts a promotion: an extra queen for a pawn is +8', () => {
    const material = materialOf('Q3k3/8/8/8/8/8/1PPPPPPP/RNBQKBNR b KQ - 0 1')
    // Black's whole army is gone but its king; White has 7 pawns and two queens.
    expect(material.white.score).toBe(1 * 7 + 3 * 4 + 5 * 2 + 9 * 2)
    expect(material.white.pieces.filter(p => p === 'q')).toEqual(['q', 'q'])
    const promoted = materialOf('4k3/8/8/8/8/8/8/Q3K3 w - - 0 1')
    expect(promoted.white).toEqual({ pieces: ['q'], score: 9 })
    const pawnUp = materialOf('3qk3/8/8/8/8/8/P7/Q3K3 w - - 0 1')
    expect(pawnUp.white).toEqual({ pieces: ['p'], score: 1 })
    const traded = materialOf('3qk3/p7/8/8/8/8/8/QQ2K3 w - - 0 1')
    // A queen for a pawn: the queen on White's side, the pawn on Black's, +8.
    expect(traded.white).toEqual({ pieces: ['q'], score: 8 })
    expect(traded.black).toEqual({ pieces: ['p'], score: 0 })
  })

  it('gives the lead to Black when Black is ahead', () => {
    const material = materialOf('4k3/8/8/8/8/8/8/r3K3 w - - 0 1')
    expect(material.black).toEqual({ pieces: ['r'], score: 5 })
    expect(material.white).toEqual({ pieces: [], score: 0 })
  })
})

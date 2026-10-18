import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import { premoveTargets, premoveToMove } from '@/utils/chess/premove'

const START = new Chess().fen()

describe('premoveTargets', () => {
  it('gives a piece its moves on an empty board, blockers ignored', () => {
    expect(premoveTargets(START, 'g1').sort()).toEqual(['e2', 'f3', 'h3'])
    // The bishop's diagonals through its own pawns.
    expect(premoveTargets(START, 'c1')).toEqual(
      expect.arrayContaining(['d2', 'e3', 'h6', 'b2', 'a3'])
    )
    expect(premoveTargets(START, 'e4')).toEqual([])
  })

  it('lets a pawn premove forward, two squares from its start, and on its diagonals', () => {
    expect(premoveTargets(START, 'e2').sort()).toEqual(['d3', 'e3', 'e4', 'f3'])
    expect(premoveTargets(START, 'a7').sort()).toEqual(['a5', 'a6', 'b6'])
  })

  it('lets a king on its square premove castling', () => {
    expect(premoveTargets(START, 'e1')).toEqual(
      expect.arrayContaining(['c1', 'g1', 'd1', 'f1'])
    )
  })
})

describe('premoveToMove', () => {
  it('turns a premove into the legal move of the position reached', () => {
    const rules = new Chess()
    rules.move('e4')
    rules.move('e5')
    expect(premoveToMove(rules, { from: 'g1', to: 'f3' })?.san).toBe('Nf3')
    // Bc4 is legal now; Bh6, still blocked by the d2 pawn, is dropped.
    expect(premoveToMove(rules, { from: 'f1', to: 'c4' })?.san).toBe('Bc4')
    expect(premoveToMove(rules, { from: 'c1', to: 'h6' })).toBeNull()
  })

  it('promotes to a queen', () => {
    const rules = new Chess('8/P6k/8/8/8/8/8/6K1 w - - 0 1')
    expect(premoveToMove(rules, { from: 'a7', to: 'a8' })?.promotion).toBe('q')
  })
})

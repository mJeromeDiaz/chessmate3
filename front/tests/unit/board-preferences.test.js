import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import {
  BOARD_THEMES,
  boardTheme,
  previewSquares
} from '@/utils/chess/boardThemes'
import { moveKind } from '@/utils/chess/moveSounds'

const START = new Chess().fen()

/** @param {string[]} sans */
const after = (sans, from = START) => {
  const game = new Chess(from)
  sans.forEach(san => game.move(san))
  return game.fen()
}

describe('boardTheme', () => {
  it('finds a theme by its API value, the wood one by default', () => {
    expect(boardTheme('slate').label).toBe('Ardoise')
    expect(boardTheme(null).value).toBe('wood')
    expect(boardTheme('marble').value).toBe('wood')
    expect(BOARD_THEMES.map(t => t.value)).toEqual([
      'wood',
      'glass',
      'pastel',
      'tournament',
      'slate'
    ])
  })

  it('previews four rows of alternating squares, light first', () => {
    const squares = previewSquares({ light: 'L', dark: 'D' })
    expect(squares).toHaveLength(16)
    expect(squares.slice(0, 8).join('')).toBe('LDLDDLDL')
  })
})

describe('moveKind', () => {
  it('recognises a quiet move, a capture and a check', () => {
    expect(moveKind(START, after(['e4']))).toBe('move')

    const beforeCapture = after(['e4', 'd5'])
    expect(moveKind(beforeCapture, after(['exd5'], beforeCapture))).toBe(
      'capture'
    )

    const beforeCheck = after(['e4', 'f5'])
    expect(moveKind(beforeCheck, after(['Qh5+'], beforeCheck))).toBe('check')
  })

  it('stays silent when the new position is not one move away', () => {
    expect(moveKind(START, START)).toBeNull()
    expect(moveKind(START, after(['e4', 'e5']))).toBeNull()
    // A takeback.
    expect(moveKind(after(['e4']), START)).toBeNull()
    expect(moveKind('not a fen', START)).toBeNull()
  })
})

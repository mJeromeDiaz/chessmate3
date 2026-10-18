import { Chess } from 'chess.js'

const KNIGHT = [
  [1, 2],
  [2, 1],
  [2, -1],
  [1, -2],
  [-1, -2],
  [-2, -1],
  [-2, 1],
  [-1, 2]
]
const KING = [
  [1, 0],
  [1, 1],
  [0, 1],
  [-1, 1],
  [-1, 0],
  [-1, -1],
  [0, -1],
  [1, -1]
]
const ROOK = [
  [1, 0],
  [0, 1],
  [-1, 0],
  [0, -1]
]
const BISHOP = [
  [1, 1],
  [-1, 1],
  [-1, -1],
  [1, -1]
]

/** @param {number} file 0-7 @param {number} rank 0-7 */
const name = (file, rank) => String.fromCharCode(97 + file) + (rank + 1)

/**
 * The squares a piece may be premoved to (docs/PUZZLES.md, "Pré-coup"), as on Lichess: its moves on
 * an empty board, blockers ignored (they may be gone by then), a pawn's diagonals whatever stands
 * there, a king's castling squares from its own square. Whether the move is legal is only known
 * when it is played.
 *
 * @param {string} fen
 * @param {string} square
 * @returns {string[]}
 */
export function premoveTargets(fen, square) {
  let piece
  try {
    piece = new Chess(fen, { skipValidation: true }).get(square)
  } catch {
    return []
  }
  if (!piece) return []
  const file = square.charCodeAt(0) - 97
  const rank = Number(square[1]) - 1
  const targets = []
  const add = (f, r) => {
    if (f >= 0 && f < 8 && r >= 0 && r < 8) targets.push(name(f, r))
  }
  const slide = directions => {
    for (const [df, dr] of directions) {
      for (let i = 1; i < 8; i++) add(file + df * i, rank + dr * i)
    }
  }
  switch (piece.type) {
    case 'p': {
      const forward = piece.color === 'w' ? 1 : -1
      add(file, rank + forward)
      if (rank === (piece.color === 'w' ? 1 : 6)) add(file, rank + 2 * forward)
      add(file - 1, rank + forward)
      add(file + 1, rank + forward)
      break
    }
    case 'n':
      for (const [df, dr] of KNIGHT) add(file + df, rank + dr)
      break
    case 'b':
      slide(BISHOP)
      break
    case 'r':
      slide(ROOK)
      break
    case 'q':
      slide([...ROOK, ...BISHOP])
      break
    case 'k':
      for (const [df, dr] of KING) add(file + df, rank + dr)
      if (square === (piece.color === 'w' ? 'e1' : 'e8')) {
        add(2, rank)
        add(6, rank)
      }
      break
  }
  return targets
}

/**
 * The legal move a premove becomes in the position reached, a promotion to a queen; null when it
 * is not legal there (the premove is then dropped).
 *
 * @param {import('chess.js').Chess} rules the position reached, the premoving side to move
 * @param {{from: string, to: string}} premove
 */
export function premoveToMove(rules, { from, to }) {
  const candidates = rules
    .moves({ square: from, verbose: true })
    .filter(m => m.to === to)
  return (
    candidates.find(m => !m.promotion || m.promotion === 'q') ?? null
  )
}

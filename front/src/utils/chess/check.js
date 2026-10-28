/**
 * The square of the king in check in a position (the board's red glow, docs/PUZZLES.md), or
 * null: no check, or no king to find (an empty board, a position being rebuilt).
 *
 * @param {import('chess.js').Chess} chess
 * @returns {string|null}
 */
export function checkedKingSquare(chess) {
  try {
    if (!chess.inCheck()) return null
    const turn = chess.turn()
    for (const row of chess.board()) {
      for (const cell of row) {
        if (cell?.type === 'k' && cell.color === turn) return cell.square
      }
    }
  } catch {
    // A position chess.js reads without validating: no check to show.
  }
  return null
}

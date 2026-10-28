/** Piece values (P 1, N B 3, R 5, Q 9), the order the captured pieces are shown in. */
const VALUES = { p: 1, n: 3, b: 3, r: 5, q: 9 }
const TYPES = /** @type {const} */ (['p', 'n', 'b', 'r', 'q'])

/**
 * @typedef {{pieces: string[], score: number}} SideMaterial `pieces`: the enemy pieces this side
 *   is up (letters, pawns first); `score`: its lead in points, 0 when it does not lead
 * @typedef {{white: SideMaterial, black: SideMaterial}} Material
 */

/**
 * The material balance of a position, as on Lichess (docs/ARENA.md): piece type by piece type,
 * the side with more of them shows the surplus as the pieces it took, the trades cancelled
 * (a knight each: nothing shown); the side ahead in points shows its lead. Read from the board
 * itself, so a promotion counts: an extra queen for a pawn is +8.
 *
 * @param {string} fen
 * @returns {Material}
 */
export function materialOf(fen) {
  /** @type {Record<string, number>} white's count minus black's, by type */
  const surplus = { p: 0, n: 0, b: 0, r: 0, q: 0 }
  for (const char of fen.split(' ')[0]) {
    const type = char.toLowerCase()
    if (type in surplus) surplus[type] += char === type ? -1 : 1
  }
  /** @type {Material} */
  const material = {
    white: { pieces: [], score: 0 },
    black: { pieces: [], score: 0 }
  }
  let score = 0
  for (const type of TYPES) {
    const n = surplus[type]
    const side = n > 0 ? material.white : material.black
    for (let i = 0; i < Math.abs(n); i++) side.pieces.push(type)
    score += n * VALUES[type]
  }
  if (score > 0) material.white.score = score
  if (score < 0) material.black.score = -score
  return material
}

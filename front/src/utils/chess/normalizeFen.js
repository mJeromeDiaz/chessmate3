import { Chess } from 'chess.js'

/**
 * Normalized FEN of a position, the same rule as the API (App\Chess\Rules::normalizedFen, see
 * docs/REPERTOIRE.md): placement, side to move, castling rights still possible, en passant square
 * only when an en passant capture is legal, no move counters. Two move orders reaching the same
 * position give the same value (transpositions). Both implementations are tested on
 * api/tests/Fixtures/Chess/normalization.json.
 *
 * The API stays the authority: the front uses this for optimistic updates only.
 */

const CASTLING = {
  K: { e1: 'K', h1: 'R' },
  Q: { e1: 'K', a1: 'R' },
  k: { e8: 'k', h8: 'r' },
  q: { e8: 'k', a8: 'r' }
}

/**
 * @param {string} fen a 6-field FEN, or 4 fields without the move counters
 * @returns {string}
 * @throws {Error} when the FEN is malformed or the position impossible
 */
export function normalizeFen(fen) {
  const chess = new Chess(clean(fen))
  const opponent = chess.turn() === 'w' ? 'b' : 'w'
  const [king] = chess.findPiece({ type: 'k', color: opponent })
  if (king && chess.isAttacked(king, chess.turn())) {
    throw new Error('Invalid FEN: the side not to move is in check')
  }
  // chess.js prints the en passant square only when the capture is legal.
  return chess.fen().split(' ').slice(0, 4).join(' ')
}

/**
 * Normalized FEN after playing UCI moves from a position.
 *
 * @param {string} fen
 * @param {string[]} moves UCI ("e2e4", "e7e8q")
 * @returns {string}
 * @throws {Error} on an illegal move
 */
export function normalizeAfter(fen, moves) {
  const chess = new Chess(clean(fen))
  for (const uci of moves) {
    chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
  }
  return normalizeFen(chess.fen())
}

/**
 * Drops the castling rights whose king or rook left its square and an en passant square without
 * the pawn that just moved (as the API does before loading a position).
 *
 * @param {string} fen
 * @returns {string}
 */
function clean(fen) {
  const fields = fen.trim().split(/\s+/)
  if (fields.length === 4) fields.push('0', '1')
  if (fields.length !== 6) {
    throw new Error('Invalid FEN: must contain six space-delimited fields')
  }
  const board = squares(fields[0])
  const rights = Object.entries(CASTLING)
    .filter(
      ([right, pieces]) =>
        fields[2].includes(right) &&
        Object.entries(pieces).every(
          ([square, piece]) => board[square] === piece
        )
    )
    .map(([right]) => right)
    .join('')
  fields[2] = rights || '-'

  const ep = /^([a-h])([36])$/.exec(fields[3])
  if (ep) {
    const [pawn, pawnRank, fromRank, expected] =
      ep[2] === '6' ? ['p', '5', '7', 'w'] : ['P', '4', '2', 'b']
    const valid =
      fields[1] === expected &&
      board[ep[1] + pawnRank] === pawn &&
      !board[fields[3]] &&
      !board[ep[1] + fromRank]
    if (!valid) fields[3] = '-'
  }
  return fields.join(' ')
}

/**
 * @param {string} placement first FEN field
 * @returns {Record<string, string>} square => piece letter (malformed input is left to chess.js)
 */
function squares(placement) {
  /** @type {Record<string, string>} */
  const board = {}
  placement.split('/').forEach((rank, index) => {
    let file = 0
    for (const char of rank) {
      if (/\d/.test(char)) {
        file += Number(char)
      } else {
        board[String.fromCharCode(97 + file) + (8 - index)] = char
        file += 1
      }
    }
  })
  return board
}

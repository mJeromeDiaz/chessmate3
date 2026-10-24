import { Chess } from 'chess.js'

/**
 * Maia-3's inputs and outputs (docs/ENGINE.md, § Maia), as the upstream code builds them
 * (CSSLab/maia3): pure, no model here.
 *
 * - The board is seen by the side to move: when Black is to move it is mirrored (ranks flipped,
 *   colours swapped), so the side to move always plays « up » as White.
 * - Tokens: 64 squares (a1 = 0, b1 = 1 … h8 = 63) × 12 channels, one-hot: pawn, knight, bishop,
 *   rook, queen, king of the side to move (0–5), then the opponent's (6–11).
 * - Moves: 4352 entries, every from × to pair (4096, from-major, from == to included) then the 256
 *   promotions from the 7th to the 8th rank (from file × to file × q, r, b, n), in the mirrored
 *   frame when Black is to move.
 *
 * @typedef {{uci: string, p: number}} MoveChance a legal move and Maia's probability of playing it
 * @typedef {{win: number, draw: number, loss: number}} Outlook for the side to move
 */

const FILES = 'abcdefgh'
const PIECES = 'pnbrqk'
const PROMOTIONS = 'qrbn'

/** @param {number} square 0 (a1) … 63 (h8) */
const squareName = square =>
  FILES[square % 8] + String(Math.floor(square / 8) + 1)

/** The 4352 moves of Maia's policy, in its order. */
export const MOVES = Object.freeze(
  (() => {
    const moves = []
    for (let from = 0; from < 64; from++) {
      for (let to = 0; to < 64; to++)
        moves.push(squareName(from) + squareName(to))
    }
    for (const fromFile of FILES) {
      for (const toFile of FILES) {
        for (const piece of PROMOTIONS)
          moves.push(`${fromFile}7${toFile}8${piece}`)
      }
    }
    return moves
  })()
)

const INDEX = new Map(MOVES.map((move, index) => [move, index]))

/**
 * A move seen from the other side of the board (rank r → 9 − r), a promotion keeping its piece.
 *
 * @param {string} uci
 */
export function mirrorMove(uci) {
  const rank = (/** @type {string} */ digit) => String(9 - Number(digit))
  return uci[0] + rank(uci[1]) + uci[2] + rank(uci[3]) + uci.slice(4)
}

/**
 * Where a legal move sits in Maia's policy, -1 if nowhere (never for a legal move).
 *
 * @param {string} uci the move as played on the real board
 * @param {boolean} blackToMove
 */
export function moveIndex(uci, blackToMove) {
  return INDEX.get(blackToMove ? mirrorMove(uci) : uci) ?? -1
}

/**
 * The board of a FEN as Maia's tokens: 64 × 12 floats, seen by the side to move. Castling rights,
 * the en passant square and the clocks are not part of them.
 *
 * @param {string} fen
 * @returns {Float32Array}
 */
export function encodeBoard(fen) {
  const [placement, turn] = fen.trim().split(/\s+/)
  const blackToMove = turn === 'b'
  const tokens = new Float32Array(64 * 12)
  const ranks = placement.split('/')
  for (let row = 0; row < 8; row++) {
    let file = 0
    for (const char of ranks[row]) {
      if (/\d/.test(char)) {
        file += Number(char)
        continue
      }
      const white = char === char.toUpperCase()
      let square = (7 - row) * 8 + file
      if (blackToMove) square ^= 56
      const own = white !== blackToMove
      const channel = PIECES.indexOf(char.toLowerCase()) + (own ? 0 : 6)
      tokens[square * 12 + channel] = 1
      file++
    }
  }
  return tokens
}

/**
 * The legal moves of a FEN with Maia's probabilities, the likeliest first: the policy's logits
 * masked to the legal moves, then a softmax.
 *
 * @param {string} fen
 * @param {ArrayLike<number>} logits the 4352 `logits_move` of this position
 * @returns {MoveChance[]}
 */
export function legalChances(fen, logits) {
  const blackToMove = fen.trim().split(/\s+/)[1] === 'b'
  const moves = new Chess(fen)
    .moves({ verbose: true })
    .map(move => move.from + move.to + (move.promotion ?? ''))
  const scored = moves.map(uci => ({
    uci,
    logit: logits[moveIndex(uci, blackToMove)]
  }))
  if (scored.length === 0) return []
  const top = Math.max(...scored.map(move => move.logit))
  const weights = scored.map(move => Math.exp(move.logit - top))
  const total = weights.reduce((sum, weight) => sum + weight, 0)
  return scored
    .map((move, i) => ({ uci: move.uci, p: weights[i] / total }))
    .sort((a, b) => b.p - a.p)
}

/**
 * The value head's [loss, draw, win] logits as probabilities, for the side to move.
 *
 * @param {ArrayLike<number>} logits
 * @returns {Outlook}
 */
export function outlook(logits) {
  const top = Math.max(logits[0], logits[1], logits[2])
  const [loss, draw, win] = [0, 1, 2].map(i => Math.exp(logits[i] - top))
  const total = loss + draw + win
  return { win: win / total, draw: draw / total, loss: loss / total }
}

/** Maia's moves below this probability are never played: a human slip, not an absurd move. */
export const MIN_CHANCE = 0.05

/**
 * Draws a move as Maia would play it: weighted by its probabilities, among the moves of at least
 * `minChance` (the likeliest when none is). With `among`, only those moves are drawn (the best
 * defence's ties), their probabilities taken again among themselves.
 *
 * @param {MoveChance[]} chances the likeliest first
 * @param {{among?: string[], minChance?: number, random?: () => number}} [options]
 * @returns {string|null} null without a legal move (or none of `among`)
 */
export function drawMove(
  chances,
  { among, minChance = MIN_CHANCE, random = Math.random } = {}
) {
  let pool = chances
  if (among) {
    const allowed = new Set(among)
    pool = pool.filter(move => allowed.has(move.uci))
    const total = pool.reduce((sum, move) => sum + move.p, 0)
    if (total > 0)
      pool = pool.map(move => ({ uci: move.uci, p: move.p / total }))
  }
  if (pool.length === 0) return null
  const kept = pool.filter(move => move.p >= minChance)
  if (kept.length === 0) return pool[0].uci
  const total = kept.reduce((sum, move) => sum + move.p, 0)
  let left = random() * total
  for (const move of kept) {
    left -= move.p
    if (left < 0) return move.uci
  }
  return kept[kept.length - 1].uci
}

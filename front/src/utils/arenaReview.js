import { Chess } from 'chess.js'
import { scoreValue } from '@/utils/engine/uci'

/**
 * The review of a game of the arena once over (docs/ARENA.md, § 6 quinquies), pure: each position's
 * score as Stockfish gives it (from the side to move), each move's loss and its verdict, the
 * mistakes counted by side, the evaluation curve, and the game in PGN.
 *
 * @typedef {import('@/utils/engine/uci').Score} Score
 * @typedef {{score: Score, bestMove: string|null}} PositionEval a position's score from the side to move, its best move
 * @typedef {'best'|'good'|'inaccuracy'|'mistake'|'blunder'} Verdict
 * @typedef {{ply: number, uci: string, san: string, side: 'white'|'black', loss: number, verdict: Verdict, bestMove: string|null}} ReviewedMove
 */

/** A move losing this much (centipawns, for its side) is an inaccuracy, a mistake, a blunder. */
export const THRESHOLDS = { inaccuracy: 50, mistake: 100, blunder: 200 }

/** Scores beyond this count as won or lost (a quicker mate is no better a move). */
export const CLAMP_CP = 1000

/** Stockfish's look at each position of the game (ms): about 20 s for a 40-move game. */
export const REVIEW_MOVETIME = 250

/** The annotation of each verdict, after the move. */
export const VERDICT_GLYPHS = /** @type {Record<Verdict, string>} */ ({
  best: '',
  good: '',
  inaccuracy: '?!',
  mistake: '?',
  blunder: '??'
})

/**
 * The positions of the game, from the start to the last move (moves + 1 of them).
 *
 * @param {string[]} moves UCI
 * @returns {{fen: string, san: string|null, uci: string|null}[]} the position, and the move that reached it
 */
export function positionsOf(moves) {
  const chess = new Chess()
  /** @type {{fen: string, san: string|null, uci: string|null}[]} */
  const positions = [{ fen: chess.fen(), san: null, uci: null }]
  for (const uci of moves) {
    try {
      const move = chess.move({
        from: uci.slice(0, 2),
        to: uci.slice(2, 4),
        promotion: uci.length > 4 ? uci[4] : undefined
      })
      positions.push({ fen: chess.fen(), san: move.san, uci })
    } catch {
      break
    }
  }
  return positions
}

/**
 * The score of a position where the game is over, without Stockfish: mated (the side to move
 * lost) or drawn; null when it goes on.
 *
 * @param {string} fen
 * @returns {Score|null}
 */
export function finalScore(fen) {
  const chess = new Chess(fen)
  if (chess.isCheckmate()) return { mate: 0 }
  if (chess.isDraw() || chess.isStalemate()) return { cp: 0 }
  return null
}

/** @param {Score} score its value, kept within ±{@link CLAMP_CP} */
export function clamped(score) {
  return Math.max(-CLAMP_CP, Math.min(CLAMP_CP, scoreValue(score)))
}

/**
 * A score from White's side, kept within the clamp: the curve's points.
 *
 * @param {Score} score from the side to move
 * @param {string} fen
 */
export function whiteValue(score, fen) {
  const value = clamped(score)
  return fen.split(' ')[1] === 'w' ? value : -value
}

/**
 * A move's verdict by what it lost for its side.
 *
 * @param {number} loss centipawns
 * @param {boolean} best it is Stockfish's move
 * @returns {Verdict}
 */
export function verdictOf(loss, best) {
  if (loss >= THRESHOLDS.blunder) return 'blunder'
  if (loss >= THRESHOLDS.mistake) return 'mistake'
  if (loss >= THRESHOLDS.inaccuracy) return 'inaccuracy'
  return best ? 'best' : 'good'
}

/**
 * Each move judged: what the position was worth for its side before (its best move), minus what
 * the position reached is worth for it.
 *
 * @param {{fen: string, san: string|null, uci: string|null}[]} positions
 * @param {PositionEval[]} evals one per position, as far as known
 * @returns {ReviewedMove[]}
 */
export function reviewMoves(positions, evals) {
  /** @type {ReviewedMove[]} */
  const reviewed = []
  for (let ply = 0; ply + 1 < positions.length; ply++) {
    const before = evals[ply]
    const after = evals[ply + 1]
    const reached = positions[ply + 1]
    if (!before || !after || !reached.uci || !reached.san) break
    // The opposite of the score reached (`flipScore` would turn « mated already » into « mated »).
    const loss = Math.max(0, clamped(before.score) + clamped(after.score))
    reviewed.push({
      ply,
      uci: reached.uci,
      san: reached.san,
      side: ply % 2 === 0 ? 'white' : 'black',
      loss,
      verdict: verdictOf(loss, reached.uci === before.bestMove),
      bestMove: before.bestMove
    })
  }
  return reviewed
}

/**
 * The inaccuracies, mistakes and blunders of one side.
 *
 * @param {ReviewedMove[]} moves
 * @param {'white'|'black'} side
 */
export function mistakesOf(moves, side) {
  const counts = { inaccuracy: 0, mistake: 0, blunder: 0 }
  for (const move of moves)
    if (move.side === side && move.verdict in counts)
      counts[/** @type {keyof typeof counts} */ (move.verdict)]++
  return counts
}

/**
 * The game's result as PGN writes it, from the player's status and side.
 *
 * @param {'active'|'won'|'lost'|'drawn'|'aborted'} status
 * @param {'white'|'black'} color the player's
 */
export function pgnResult(status, color) {
  if (status === 'drawn') return '1/2-1/2'
  if (status === 'won') return color === 'white' ? '1-0' : '0-1'
  if (status === 'lost') return color === 'white' ? '0-1' : '1-0'
  return '*'
}

/**
 * The game in PGN: its headers (the arena, the date, both sides and the boss's Elo, the result,
 * the time control, how it ended), then its moves.
 *
 * @param {{game: import('@/utils/arenaBoss').ArenaGame, sans: string[], player: string, boss: string, site?: string, termination?: string}} input
 *   `site`: the app's address (the page's origin)
 */
export function pgnOf({ game, sans, player, boss, site = '?', termination }) {
  const [minutes, increment] = game.cadence.split('+').map(Number)
  const date = game.startedAt.slice(0, 10).replaceAll('-', '.')
  const result = pgnResult(game.status, game.color)
  const white = game.color === 'white'
  const headers = [
    ['Event', "Don't Stay Rooky · Arène"],
    ['Site', site],
    ['Date', date],
    ['Round', '-'],
    ['White', white ? player : boss],
    ['Black', white ? boss : player],
    ['Result', result],
    [white ? 'BlackElo' : 'WhiteElo', String(game.elo)],
    ['TimeControl', `${(minutes || 0) * 60}+${increment || 0}`],
    ...(termination ? [['Termination', termination]] : [])
  ]
  const tags = headers
    .map(
      ([name, value]) =>
        `[${name} "${String(value).replaceAll('\\', '\\\\').replaceAll('"', '\\"')}"]`
    )
    .join('\n')
  /** @type {string[]} */
  const tokens = []
  sans.forEach((san, i) => {
    if (i % 2 === 0) tokens.push(`${i / 2 + 1}.`)
    tokens.push(san)
  })
  tokens.push(result)
  /** @type {string[]} */
  const lines = []
  let line = ''
  for (const token of tokens) {
    if (line && line.length + token.length + 1 > 80) {
      lines.push(line)
      line = token
    } else line = line ? `${line} ${token}` : token
  }
  if (line) lines.push(line)
  return `${tags}\n\n${lines.join('\n')}\n`
}

/**
 * The file's name: the boss and the day.
 *
 * @param {import('@/utils/arenaBoss').ArenaGame} game
 */
export function pgnFileName(game) {
  return `dontstayrooky-arene-${game.boss}-${game.startedAt.slice(0, 10)}.pgn`
}

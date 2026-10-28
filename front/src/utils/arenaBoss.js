import { Chess } from 'chess.js'

/**
 * A boss of the arena as an opponent (docs/ARENA.md, § 2 and § 6 ter), pure: the game replayed
 * from its moves, the boss's strength by the game's phase, its styles weighing Maia's moves, its
 * thinking time by its speed, its reactions to the moves. The browser plays the boss with these
 * (`useArenaGame`); the server only checks the moves and keeps the clocks.
 *
 * @typedef {{uci: string, p: number}} Chance
 * @typedef {{id: string, boss: string, color: 'white'|'black', elo: number, openingElo?: number|null, endgameElo: number|null, styles: string[], guarded: boolean, cadence: string, incrementMs: number, moves: string[], playerMs: number, bossMs: number, turn: 'player'|'boss', clocksRunning: boolean, status: 'active'|'won'|'lost'|'drawn'|'aborted', end: string|null, crystals: number, xp: number, startedAt: string, endedAt: string|null}} ArenaGame
 * @typedef {{fen: string, sans: string[], lastMove: {from: string, to: string}|null, check: boolean}} Replayed
 * @typedef {'start'|'thinking'|'quiet'|'boss_captures'|'player_captures'|'player_takes_pawn'|'boss_checks'|'player_checks'|'won'|'lost'|'drawn'} ReactionKey
 * @typedef {import('@/utils/mindMapPath').Mood} Mood
 */

/** The non-pawn material of both sides (N, B 3; R 5; Q 9) at or under which it is an endgame. */
export const ENDGAME_MATERIAL = 26

/** Material values, for the styles and the endgame. */
const VALUES = /** @type {Record<string, number>} */ ({
  p: 1,
  n: 3,
  b: 3,
  r: 5,
  q: 9,
  k: 0
})

/**
 * How much each style multiplies the chance of the moves it likes. `temperature`: the exponent
 * of every chance before they are weighed again (above 1, the boss keeps to its likeliest moves;
 * under 1, it varies).
 *
 * @type {Record<string, {weigh?: (move: import('chess.js').Move, king: string|null) => number, temperature?: number}>}
 */
const STYLES = {
  varied: { temperature: 0.7 },
  solid: { temperature: 1.3 },
  aggressive: {
    weigh: move => (move.captured ? 1.2 : 1) * (crossesHalf(move) ? 1.25 : 1)
  },
  trades: {
    weigh: move =>
      move.captured && VALUES[move.captured] === VALUES[move.piece] ? 1.5 : 1
  },
  forcing: {
    weigh: move => (move.captured || move.san.includes('+') ? 1.4 : 1)
  },
  king_hunter: {
    weigh: (move, king) =>
      (move.san.includes('+') ? 1.5 : 1) *
      (king && distance(move.to, king) <= 2 ? 1.3 : 1)
  },
  pawns: { weigh: move => (move.piece === 'p' ? 1.35 : 1) },
  queen_keeper: {
    weigh: move => (move.piece === 'q' && move.captured === 'q' ? 0.4 : 1)
  },
  checks: { weigh: move => (move.san.includes('+') ? 1.6 : 1) },
  rooks: { weigh: move => (move.piece === 'r' ? 1.35 : 1) },
  bishops: { weigh: move => (move.piece === 'b' ? 1.35 : 1) },
  knights: { weigh: move => (move.piece === 'n' ? 1.35 : 1) },
  mate_finder: { weigh: move => (move.san.includes('#') ? 20 : 1) },
  captures: { weigh: move => (move.captured ? 1.6 : 1) }
}

/** The boss's thinking time in a 5+3 game, by its speed (1 to 5), before the random part. */
const THINK_MS = /** @type {Record<number, number>} */ ({
  1: 4000,
  2: 3000,
  3: 2000,
  4: 1300,
  5: 800
})

/** The first move of each side is free (the clocks start after it): the boss plays it at once. */
export const OPENING_THINK_MS = 600

/** The boss never answers faster than this: an instant reply feels like a machine. */
export const MIN_THINK_MS = 400

/** The mood of the boss's face for each reaction (its pictures, `moodImage`). */
export const REACTION_MOODS = /** @type {Record<ReactionKey, Mood>} */ ({
  start: 'neutral',
  thinking: 'focus',
  quiet: 'focus',
  boss_captures: 'joy',
  player_captures: 'anxiety',
  player_takes_pawn: 'doubt',
  boss_checks: 'pride',
  player_checks: 'surprise',
  won: 'sadness',
  lost: 'pride',
  drawn: 'doubt'
})

/**
 * The game from the starting position: the position reached, the moves in SAN, the last move
 * and whether the side to move is in check. Stops at the first move that does not follow (the
 * server refuses them).
 *
 * @param {string[]} moves UCI
 * @returns {Replayed}
 */
export function replay(moves) {
  const chess = new Chess()
  /** @type {string[]} */
  const sans = []
  /** @type {{from: string, to: string}|null} */
  let lastMove = null
  for (const uci of moves) {
    const move = playUci(chess, uci)
    if (!move) break
    sans.push(move.san)
    lastMove = { from: move.from, to: move.to }
  }
  return { fen: chess.fen(), sans, lastMove, check: chess.inCheck() }
}

/**
 * Whether the position is an endgame: little material left besides the pawns.
 *
 * @param {string} fen
 */
export function isEndgame(fen) {
  let material = 0
  for (const char of fen.split(' ')[0]) {
    const piece = char.toLowerCase()
    if (piece !== 'p' && piece in VALUES) material += VALUES[piece]
  }
  return material <= ENDGAME_MATERIAL
}

/** A boss's opening: its first plies (its `openingElo`; api ArenaRules::OPENING_PLIES). */
export const OPENING_PLIES = 20

/**
 * The boss's strength in this position: its opening Elo in the first plies, its endgame Elo once
 * in an endgame, else its Elo.
 *
 * @param {Pick<ArenaGame, 'elo'|'endgameElo'|'openingElo'>} game
 * @param {string} fen
 * @param {number} [ply] the half-moves already played
 */
export function eloFor(game, fen, ply = Infinity) {
  if (game.openingElo && ply < OPENING_PLIES) return game.openingElo
  return game.endgameElo && isEndgame(fen) ? game.endgameElo : game.elo
}

/**
 * Maia's chances weighed by the boss's styles, then normalized again: the moves it likes become
 * likelier, never a move Maia did not see.
 *
 * @param {string} fen
 * @param {Chance[]} chances
 * @param {string[]} styles
 * @returns {Chance[]} sorted, likeliest first
 */
export function reweigh(fen, chances, styles) {
  const known = styles.map(style => STYLES[style]).filter(Boolean)
  if (known.length === 0 || chances.length === 0) return chances
  const chess = new Chess(fen)
  const moves = new Map(
    chess
      .moves({ verbose: true })
      .map(move => [move.from + move.to + (move.promotion ?? ''), move])
  )
  const king = enemyKing(chess)
  const temperature = known.reduce(
    (t, style) => t * (style.temperature ?? 1),
    1
  )
  const weighed = chances.map(({ uci, p }) => {
    const move = moves.get(uci)
    let weight = p ** temperature
    if (move)
      for (const style of known)
        if (style.weigh) weight *= style.weigh(move, king)
    return { uci, p: weight }
  })
  const total = weighed.reduce((sum, move) => sum + move.p, 0)
  if (!(total > 0)) return chances
  return weighed
    .map(move => ({ uci: move.uci, p: move.p / total }))
    .sort((a, b) => b.p - a.p)
}

/**
 * How long the boss thinks before its move: by its speed, longer in slower games, at random
 * between 60 % and 140 %, never more than a twentieth of its clock. Its first move: at once.
 *
 * @param {number} speed 1 to 5
 * @param {Pick<ArenaGame, 'cadence'|'bossMs'|'clocksRunning'>} game
 * @param {() => number} [random]
 */
export function thinkMs(speed, game, random = Math.random) {
  if (!game.clocksRunning) return OPENING_THINK_MS
  const minutes = Number.parseInt(game.cadence, 10) || 5
  const base = (THINK_MS[speed] ?? THINK_MS[3]) * Math.sqrt(minutes / 5)
  const wanted = base * (0.6 + 0.8 * random())
  return Math.round(Math.max(MIN_THINK_MS, Math.min(wanted, game.bossMs / 20)))
}

/**
 * The boss's reaction to the move just played, null for a quiet move (it keeps its face).
 *
 * @param {string[]} moves UCI, the move just played last
 * @param {boolean} byBoss
 * @returns {ReactionKey|null}
 */
export function reactionTo(moves, byBoss) {
  if (moves.length === 0) return null
  const chess = new Chess()
  /** @type {import('chess.js').Move|null} */
  let last = null
  for (const uci of moves) {
    last = playUci(chess, uci)
    if (!last) return null
  }
  if (!last) return null
  if (last.san.includes('+') || last.san.includes('#'))
    return byBoss ? 'boss_checks' : 'player_checks'
  if (last.captured) {
    if (byBoss) return 'boss_captures'
    return last.captured === 'p' ? 'player_takes_pawn' : 'player_captures'
  }
  return null
}

/**
 * The reaction to a finished game, for the boss.
 *
 * @param {ArenaGame['status']} status the player's
 * @returns {ReactionKey}
 */
export function endReaction(status) {
  if (status === 'won') return 'won'
  if (status === 'lost') return 'lost'
  return 'drawn'
}

/**
 * @param {Chess} chess
 * @param {string} uci
 */
function playUci(chess, uci) {
  try {
    return chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci.length > 4 ? uci[4] : undefined
    })
  } catch {
    return null
  }
}

/** @param {import('chess.js').Move} move it lands in the opponent's half */
function crossesHalf(move) {
  const rank = Number(move.to[1])
  return move.color === 'w' ? rank >= 5 : rank <= 4
}

/**
 * @param {string} a
 * @param {string} b
 */
function distance(a, b) {
  return Math.max(
    Math.abs(a.charCodeAt(0) - b.charCodeAt(0)),
    Math.abs(Number(a[1]) - Number(b[1]))
  )
}

/** @param {Chess} chess the square of the king of the side not to move */
function enemyKing(chess) {
  const enemy = chess.turn() === 'w' ? 'b' : 'w'
  for (const row of chess.board())
    for (const square of row)
      if (square && square.type === 'k' && square.color === enemy)
        return square.square
  return null
}

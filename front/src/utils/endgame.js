import { Chess } from 'chess.js'
import { flipScore } from '@/utils/engine/uci'
import { t } from '@/i18n'

/**
 * The rules of the endgames module played in the browser (docs/ENDGAMES.md, § 8), pure: the twin
 * of the server's judge (`App\Endgame\Judge\GameJudge`), which judges the game again at the end.
 *
 * @typedef {'win'|'draw'|'loss'} Result
 * @typedef {'mate'|'promote'|'convert'|'hold'} Goal
 * @typedef {{uci: string, result: Result|null, dtm: number|null, dtz: number|null, zeroing: boolean}} TablebaseMove
 * @typedef {{fen: string, known: boolean, result: Result|null, dtm: number|null, dtz: number|null, checkmate: boolean, stalemate: boolean, insufficientMaterial: boolean, moves: TablebaseMove[], defence: string[]}} TablebaseAnswer
 *   GET /endgames/tablebase; a move's `result` is for the side playing it
 * @typedef {'won'|'held'|'spoiled'|'overtime'|'resigned'} Outcome
 */

const RANK = { win: 2, draw: 1, loss: 0 }

/**
 * Whether `result` is as good as `wanted` or better: a win keeps a draw to hold.
 *
 * @param {Result|null|undefined} result null: uncertain, keeps nothing
 * @param {Result} wanted
 */
export function atLeast(result, wanted) {
  return result != null && RANK[result] >= RANK[wanted]
}

/**
 * The player's moves giving at least the lesson's result, best first (the tablebase's order).
 *
 * @param {TablebaseAnswer} answer
 * @param {Result} wanted
 * @returns {string[]}
 */
export function keepingMoves(answer, wanted) {
  return answer.moves
    .filter(move => atLeast(move.result, wanted))
    .map(move => move.uci)
}

/**
 * When the tablebase cannot judge, Stockfish does (validated 2026-10-23): after the player's move,
 * a mate announced or at least +4 pawns keeps a win, no worse than −2 pawns keeps a draw.
 */
export const ENGINE_WIN_CP = 400
export const ENGINE_HOLD_CP = -200

/**
 * Whether a move keeps the result, by the engine's score of the position it reaches (from the
 * side to move there, the opponent, as UCI gives it).
 *
 * @param {import('@/utils/engine/uci').Score} score
 * @param {Result} wanted
 */
export function engineKeeps(score, wanted) {
  const mine = flipScore(score)
  if ('mate' in mine) return mine.mate > 0
  return mine.cp >= (wanted === 'win' ? ENGINE_WIN_CP : ENGINE_HOLD_CP)
}

/**
 * The side to move has its king only.
 *
 * @param {string} fen
 */
export function bareKing(fen) {
  const [placement, turn] = fen.split(' ')
  const pieces = placement.replace(turn === 'w' ? /[^A-Z]/g : /[^a-z]/g, '')
  return pieces.length === 1
}

/**
 * A move in UCI played on a chess.js game, null when illegal.
 *
 * @param {Chess} game
 * @param {string} uci
 * @returns {import('chess.js').Move|null}
 */
export function playUci(game, uci) {
  try {
    return game.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci.length > 4 ? uci[4] : undefined
    })
  } catch {
    return null
  }
}

/**
 * The goal of the lesson, reached by the move just played (null: not yet), as the server judges
 * it: a mate, a promotion or a bare king after the player's move; a draw held after its moves, or
 * dead (stalemate, insufficient material) after any move.
 *
 * @param {{goal: Goal, holdMoves: number|null}} lesson
 * @param {Chess} game after the move
 * @param {import('chess.js').Move} move
 * @param {boolean} mine played by the player
 * @param {number} playerMoves the player's moves so far, this one included
 * @returns {'won'|'held'|null}
 */
export function reached(lesson, game, move, mine, playerMoves) {
  if (lesson.goal === 'hold') {
    const dead = game.isStalemate() || game.isInsufficientMaterial()
    return dead || (mine && playerMoves >= (lesson.holdMoves ?? 0))
      ? 'held'
      : null
  }
  if (!mine) return null
  if (lesson.goal === 'mate') return game.isCheckmate() ? 'won' : null
  if (lesson.goal === 'promote') return move.promotion ? 'won' : null
  return bareKing(game.fen()) ? 'won' : null
}

/**
 * The counter under the board: the player's moves played out of those allowed (to win) or to
 * hold.
 *
 * @param {{goal: Goal, budgetMoves: number|null, holdMoves: number|null}} item
 * @param {number} playerMoves
 * @returns {{kind: 'budget'|'hold', done: number, total: number}}
 */
export function counter(item, playerMoves) {
  return item.goal === 'hold'
    ? { kind: 'hold', done: playerMoves, total: item.holdMoves ?? 0 }
    : { kind: 'budget', done: playerMoves, total: item.budgetMoves ?? 0 }
}

/**
 * The position after some moves, from a FEN.
 *
 * @param {string} fen
 * @param {string[]} moves UCI
 */
export function gameFrom(fen, moves = []) {
  const game = new Chess(fen)
  for (const uci of moves) {
    if (!playUci(game, uci)) throw new Error(`Illegal move ${uci} in ${fen}.`)
  }
  return game
}

/** The themes in the page's order (`App\Enum\Endgame\Family`). */
export const FAMILIES = [
  'basicMates',
  'pawns',
  'queens',
  'rooks',
  'bishops',
  'knights'
]

/** @param {string} key a lesson of the catalogue */
export function lessonTitle(key) {
  return t(`endgame.lessons.${key}.title`)
}

/** Lizy's explanation of a lesson (learn mode). @param {string} key */
export function lessonText(key) {
  return t(`endgame.lessons.${key}.text`)
}

/**
 * The position's goal in words: « Promeus ton pion … en 18 coups au plus », « Tiens la nulle 12
 * coups ».
 *
 * @param {{goal: Goal, budgetMoves: number|null, holdMoves: number|null}} item
 */
export function goalText(item) {
  const n = item.goal === 'hold' ? item.holdMoves : item.budgetMoves
  return t(`endgame.goals.${item.goal}`, { n: n ?? 0 })
}

/** @param {{elo: number|null, perfect: boolean}} opponent */
export function opponentLabel(opponent) {
  return opponent.perfect
    ? t('endgame.player.perfect')
    : t('endgame.player.opponent', { elo: opponent.elo })
}

/**
 * A move in algebraic notation, played after the first `ply` moves of a game ('' when it cannot
 * be read).
 *
 * @param {string} fen the position served
 * @param {string[]} moves the game, UCI
 * @param {number} ply
 * @param {string|null} uci
 */
export function sanAt(fen, moves, ply, uci) {
  if (!uci) return ''
  try {
    const game = gameFrom(fen, moves.slice(0, ply))
    return playUci(game, uci)?.san ?? ''
  } catch {
    return ''
  }
}

/**
 * The result sheet and Lizy's words once the server judged the position.
 *
 * @param {{status: Outcome, moves: string[], mistakeAt: number|null, bestMove: string|null, playerMoves: number|null, budgetMoves: number|null, holdMoves: number|null}} result
 * @param {string} fen the position served
 * @param {string|null} [shownMove] the right move found here when the server gives none (« Passer »)
 */
export function endgameFeedback(result, fen, shownMove = null) {
  const status = result.status
  const success = status === 'won' || status === 'held'
  const n = result.playerMoves ?? 0
  const ply = result.mistakeAt ?? result.moves.length
  const move = sanAt(fen, result.moves, ply, result.bestMove ?? shownMove)
  /** @type {Record<Outcome, string>} */
  const subs = {
    won: t('endgame.feedback.subs.won', { n }, n),
    held: t('endgame.feedback.subs.held', { n }, n),
    spoiled: move
      ? t('endgame.feedback.subs.spoiled', { move })
      : t('endgame.feedback.subs.spoiledNoMove'),
    overtime: t('endgame.feedback.subs.overtime', {
      n: result.budgetMoves ?? 0
    }),
    resigned: move
      ? t('endgame.feedback.subs.resigned', { move })
      : t('endgame.feedback.subs.resignedNoMove')
  }
  return {
    kind: /** @type {'win'|'miss'} */ (success ? 'win' : 'miss'),
    // The win kept, too slowly: the yellow sheet.
    palette: /** @type {'help'|null} */ (status === 'overtime' ? 'help' : null),
    badge: success ? '!' : status === 'overtime' ? '⏱︎' : '?',
    title: t(`endgame.feedback.titles.${status}`),
    sub: subs[status],
    kicker: t('endgame.feedback.kicker'),
    bubble: t(`endgame.feedback.bubbles.${status}`)
  }
}

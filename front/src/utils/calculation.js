import { Chess } from 'chess.js'
import { t } from '@/i18n'
import { numberedLine } from '@/utils/notation'
import { scoreValue } from '@/utils/engine/uci'

/**
 * The calculation module (docs/CALCULATION.md): a mate thought over on a frozen board, then its
 * whole line played at once, each move of the player within `plySeconds`, the opponent's replies
 * played by themselves (since 2026-10-28; both sides, each half-move within `plySeconds`, in the
 * Avocat du diable and the mini-boss's replay from memory). Pure, so that it can be tested alone.
 */

/** The longest mate drawn: a line never has more half-moves than one in 4. */
export const MAX_MATE = 4
/** Each reply played by itself, on the server's clock (CalculationRules::REPLY_SECONDS). */
export const REPLY_SECONDS = 1

/**
 * The time to play a line of `plies` half-moves from « Je réponds », as the server counts it:
 * `plySeconds` per move of the player and REPLY_SECONDS per reply played by itself, or
 * `plySeconds` per half-move when the player plays both sides.
 *
 * @param {number} plies
 * @param {boolean} autoReplies
 * @param {number} plySeconds
 */
export function answerSeconds(plies, autoReplies, plySeconds) {
  if (!autoReplies) return plySeconds * plies
  return (
    plySeconds * Math.ceil(plies / 2) + REPLY_SECONDS * Math.floor(plies / 2)
  )
}

/**
 * What follows a move of the player when the opponent's replies are played by themselves:
 * `over` (a mate, or the solution's length reached), `reply` (the solution's reply, the line on
 * it), `engine` (chapter 3, off the solution: the engine says whether the move holds and replies)
 * or `wrong` (off the solution without a mate: the line stops there).
 *
 * @param {string} fen where the line starts
 * @param {string[]} line UCI, ending on the player's move
 * @param {string[]} solution UCI, the player's move first
 * @param {boolean} engine judged by the engine (chapter 3)
 * @returns {{type: 'over'|'engine'|'wrong'}|{type: 'reply', uci: string}}
 */
export function nextStep(fen, line, solution, engine) {
  if (new Chess(after(fen, line)).isCheckmate()) return { type: 'over' }
  if (line.length >= solution.length) return { type: 'over' }
  const onSolution = line.every((uci, k) => uci === solution[k])
  if (onSolution) return { type: 'reply', uci: solution[line.length] }
  return { type: engine ? 'engine' : 'wrong' }
}

/**
 * The half-moves of a mate in `mate` (the player's and the defences between).
 *
 * @param {number} mate
 */
export function matePlies(mate) {
  return 2 * mate - 1
}

/**
 * The position after a line of UCI moves.
 *
 * @param {string} fen
 * @param {string[]} moves
 */
export function after(fen, moves) {
  const chess = new Chess(fen)
  for (const uci of moves)
    chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
  return chess.fen()
}

/**
 * Whether the line played is over and goes to the server: a mate, or as many half-moves as the
 * line has (its `moves` known), or as a mate in MAX_MATE has (its length hidden).
 *
 * @param {string} fen where the line starts
 * @param {string[]} played UCI
 * @param {number|null} moves the player's moves of the line
 */
export function lineOver(fen, played, moves) {
  if (played.length === 0) return false
  if (new Chess(after(fen, played)).isCheckmate()) return true
  return played.length >= matePlies(moves ?? MAX_MATE)
}

/**
 * The chip of a line's level: « Mat en 3 », « Gain en 3 coups », « 3 coups » (its ending not
 * told), '' when hidden (the Métronome).
 *
 * @param {{mate?: number|null, moves?: number|null, ending?: string|null}} data
 */
export function levelText(data) {
  if (data.mate) return t('calculation.player.mate', { n: data.mate })
  if (!data.moves) return ''
  return data.ending === 'material'
    ? t('calculation.player.gain', { n: data.moves })
    : t('calculation.player.moves', { n: data.moves })
}

/**
 * Time left, in ms, from a start (ms, the client's clock) and a length in seconds.
 *
 * @param {number} startMs
 * @param {number} seconds
 * @param {number} now
 */
export function leftMs(startMs, seconds, now) {
  return Math.max(0, startMs + seconds * 1000 - now)
}

/**
 * The verdict's lines: the solution, numbered in the player's letters, and theirs when it differs.
 *
 * 3.2: the second line too, its mistake counted after the first line's half-moves.
 *
 * @param {{status: string, expected?: {san: string}[], answer?: {san: string}[], second?: {san: string}[]|null, mistakeAt?: number|null}} result
 * @param {string} fen
 */
export function verdictText(result, fen) {
  const solution = numberedLine(
    (result.expected ?? []).map(m => m.san),
    fen
  )
  if (result.status === 'right') return solution
  const answer = result.answer ?? []
  const yours = numberedLine(
    answer.map(m => m.san),
    fen
  )
  const second = result.second
    ? numberedLine(
        result.second.map(m => m.san),
        fen
      )
    : ''
  const mistakeAt = result.mistakeAt ?? 0
  return [
    result.status === 'late'
      ? t('calculation.player.lateText')
      : result.second && mistakeAt >= answer.length
        ? t('calculation.player.mistakeSecond', {
            n: mistakeAt - answer.length + 1
          })
        : t('calculation.player.mistakeAt', { n: mistakeAt + 1 }),
    t('calculation.player.solution', { line: solution }),
    yours ? t('calculation.player.yours', { line: yours }) : '',
    second ? t('calculation.player.yoursSecond', { line: second }) : ''
  ]
    .filter(Boolean)
    .join(' · ')
}

/**
 * The Métronome's mood for a streak (docs/CALCULATION.md, § 6): neutral at first, sad at 3 in a
 * row, angry at 6, focused at 9, anxious at 11.
 *
 * @param {number} streak
 * @returns {import('@/utils/mindMapPath').Mood}
 */
export function streakMood(streak) {
  if (streak >= 11) return 'anxiety'
  if (streak >= 9) return 'focus'
  if (streak >= 6) return 'anger'
  if (streak >= 3) return 'sadness'
  return 'neutral'
}

/**
 * The Métronome's face in its fight: a line missed delights it, one too late makes it proud; one
 * right in less than a third of the thinking time surprises it; past half the thinking time it
 * doubts; otherwise the streak sets its mood.
 *
 * @param {{phase: string, streak: number, result?: {status: string, streak?: number}|null, thinkLeftRatio: number, thoughtRatio: number|null}} state
 *   `thoughtRatio`: the share of the thinking time used before « Je réponds »
 * @returns {import('@/utils/mindMapPath').Mood}
 */
export function metronomeMood({
  phase,
  streak,
  result = null,
  thinkLeftRatio,
  thoughtRatio
}) {
  if (result) {
    if (result.status === 'late') return 'pride'
    if (result.status !== 'right') return 'joy'
    if (
      thoughtRatio !== null &&
      thoughtRatio < 1 / 3 &&
      (result.streak ?? 0) < 3
    )
      return 'surprise'
    return streakMood(result.streak ?? streak)
  }
  if (phase === 'think' && thinkLeftRatio < 0.5) return 'doubt'
  return streakMood(streak)
}

/**
 * A question on the position a line reached (docs/CALCULATION.md, § 2 bis), in plain words.
 *
 * @param {{ask?: string, target?: string}} data
 */
export function questionText(data) {
  return data.ask === 'where'
    ? t('calculation.question.where', {
        piece: t(`vision.pieces.${data.target}`)
      })
    : t('calculation.question.what', { square: data.target ?? '' })
}

/**
 * The right answer, once wrong: where the piece stood, what stood on the square.
 *
 * @param {{ask?: string, target?: string}} data
 * @param {{status: string, expected?: string[]}} result
 */
export function questionVerdict(data, result) {
  if (result.status === 'right') return ''
  const expected = result.expected ?? []
  if (data.ask === 'where')
    return t('calculation.question.wasOn', { squares: expected.join(', ') })
  return expected.length
    ? t('calculation.question.wasPiece', {
        square: data.target ?? '',
        piece: t(`vision.pieces.${expected[0]}`)
      })
    : t('calculation.question.wasEmpty', { square: data.target ?? '' })
}

/**
 * Whether a half-move holds, from the engine's scores of the positions before and after it, each
 * from the side to move there (docs/CALCULATION.md, § 2 quater): when the mover had a forced mate,
 * the move keeps it as fast; otherwise its value (the opposite of the score after) is within
 * `toleranceCp` of the best (the score before).
 *
 * @param {import('@/utils/engine/uci').Score} before
 * @param {import('@/utils/engine/uci').Score} afterMove
 * @param {number} toleranceCp
 */
export function holds(before, afterMove, toleranceCp) {
  if ('mate' in before && before.mate > 0) {
    // Mate in N: the opponent mated in N − 1 at most once it is played (mate 0: mated already).
    return (
      'mate' in afterMove &&
      afterMove.mate <= 0 &&
      -afterMove.mate <= before.mate - 1
    )
  }
  return scoreValue(before) + scoreValue(afterMove) <= toleranceCp
}

/**
 * The engine's verdict on a line, from the scores of every position along it (`scores[i]` before
 * the half-move i, the last one after the line), each from the side to move there: right when
 * every half-move holds, the player's and the opponent's alike (a defence imagined too weak spoils
 * the line); `mistakeAt` the first that does not.
 *
 * @param {import('@/utils/engine/uci').Score[]} scores one more than the line's half-moves
 * @param {number} toleranceCp
 * @returns {{right: boolean, mistakeAt: number|null}}
 */
export function judgeLine(scores, toleranceCp) {
  for (let i = 0; i + 1 < scores.length; i++) {
    if (!holds(scores[i], scores[i + 1], toleranceCp))
      return { right: false, mistakeAt: i }
  }
  return { right: true, mistakeAt: null }
}

/**
 * The score of a position the engine has no move in: mated (`mate 0`), or a stalemate's draw.
 * Null when there is a move to search.
 *
 * @param {string} fen
 * @returns {import('@/utils/engine/uci').Score|null}
 */
export function finalScore(fen) {
  const chess = new Chess(fen)
  if (chess.isCheckmate()) return { mate: 0 }
  if (chess.isStalemate()) return { cp: 0 }
  return null
}

/**
 * The first half-move where a line leaves the solution (its length when it follows it all the
 * way); null when it is the solution itself.
 *
 * @param {string[]} line UCI
 * @param {string[]} solution UCI
 * @returns {number|null}
 */
export function offSolution(line, solution) {
  const i = line.findIndex((uci, k) => uci !== solution[k])
  if (i >= 0) return i
  return line.length === solution.length ? null : line.length
}

/**
 * The decoys of the mini-boss of chapter 3 (docs/CALCULATION.md, § 2 quater): `count` forcing moves
 * of the player's beside the solution's first one, refuted (at least `gapCp` worse than the best
 * forcing move the engine saw), the least worse first (the most tempting); short of them, the
 * weakest of the others, then those it did not see. Without an analysis (the engine failed): drawn
 * at random.
 *
 * @param {string[]} forcing the position's forcing moves (UCI)
 * @param {string} first the solution's first move
 * @param {import('@/utils/engine/uci').EngineLine[]|null} lines the engine's lines on them, best first
 * @param {number} gapCp
 * @param {number} count
 * @param {() => number} [random]
 * @returns {string[]}
 */
export function pickDecoys(
  forcing,
  first,
  lines,
  gapCp,
  count,
  random = Math.random
) {
  const others = forcing.filter(uci => uci !== first)
  const seen = (lines ?? []).filter(l => others.includes(l.move))
  if (!lines || lines.length === 0 || seen.length === 0) {
    const pool = [...others]
    const drawn = []
    while (drawn.length < count && pool.length > 0)
      drawn.push(pool.splice(Math.floor(random() * pool.length), 1)[0])
    return drawn
  }
  const best = scoreValue(lines[0].score)
  const refuted = seen.filter(l => best - scoreValue(l.score) >= gapCp)
  const weakest = [...seen].reverse().filter(l => !refuted.includes(l))
  const unseen = others.filter(uci => !seen.some(l => l.move === uci))
  return [
    ...refuted.map(l => l.move),
    ...weakest.map(l => l.move),
    ...unseen
  ].slice(0, count)
}

/**
 * The forcing moves of a position: its checks and captures (UCI).
 *
 * @param {string} fen
 * @returns {string[]}
 */
export function forcingMoves(fen) {
  return new Chess(fen)
    .moves({ verbose: true })
    .filter(m => m.captured || m.san.includes('+') || m.san.includes('#'))
    .map(m => m.lan)
}

/**
 * The decoy of a reply to choose (3.1, docs/CALCULATION.md, § 2 quater): among the opponent's
 * replies the engine looked at, those at least `gapCp` worse than the best; a natural one first (a
 * capture or a check), the most tempting of them (the least worse). None that bad: the weakest
 * reply seen. Without an analysis (the engine failed): a natural reply, else any. Never the
 * solution's reply; null when there is no other.
 *
 * @param {string} fen the position after the player's first move, the opponent to move
 * @param {string} reply the solution's reply (UCI)
 * @param {import('@/utils/engine/uci').EngineLine[]|null} lines the engine's lines there, best first
 * @param {number} gapCp
 * @param {() => number} [random]
 * @returns {string|null}
 */
export function pickDecoy(fen, reply, lines, gapCp, random = Math.random) {
  const moves = new Chess(fen).moves({ verbose: true })
  const natural = new Set(
    moves
      .filter(m => m.captured || m.san.includes('+') || m.san.includes('#'))
      .map(m => m.lan)
  )
  const others = moves.map(m => m.lan).filter(uci => uci !== reply)
  if (others.length === 0) return null
  const seen = (lines ?? []).filter(
    l => l.move !== reply && others.includes(l.move)
  )
  if (lines && lines.length > 0 && seen.length > 0) {
    const best = scoreValue(lines[0].score)
    const bad = seen.filter(l => best - scoreValue(l.score) >= gapCp)
    // The lines come best first: the first bad one is the least worse.
    const decoy =
      bad.find(l => natural.has(l.move)) ?? bad[0] ?? seen[seen.length - 1]
    return decoy.move
  }
  const pool = others.filter(uci => natural.has(uci))
  const from = pool.length > 0 ? pool : others
  return from[Math.floor(random() * from.length)]
}

/**
 * The Avocat du diable's note on a line (docs/CALCULATION.md, § 2 quater): the player's idea did
 * not hold (`mistakeAt` 0), its refutation found or not; it held, the opponent's best reply
 * missed (`mistakeAt` 1). '' otherwise.
 *
 * @param {{status: string, mistakeAt?: number|null}} result
 * @param {boolean|null} refuted whether the reply played refuted the idea (null: unknown, the engine down)
 */
export function advocateNote(result, refuted) {
  if (result.status !== 'wrong') return ''
  if (result.mistakeAt === 0)
    return refuted === null
      ? t('calculation.advocate.ideaFailed')
      : t(
          refuted
            ? 'calculation.advocate.refutedFound'
            : 'calculation.advocate.refutedMissed'
        )
  return result.mistakeAt === 1 ? t('calculation.advocate.tooKind') : ''
}

/**
 * The Avocat du diable's face: delighted by an idea that falls, proud when time ran out, sad when
 * the idea held to the end; focused while the player thinks, doubtful past half the time.
 *
 * @param {{phase: string, result?: {status: string}|null, thinkLeftRatio: number}} state
 * @returns {import('@/utils/mindMapPath').Mood}
 */
export function advocateMood({ phase, result = null, thinkLeftRatio }) {
  if (result)
    return result.status === 'right'
      ? 'sadness'
      : result.status === 'late'
        ? 'pride'
        : 'joy'
  if (phase === 'think') return thinkLeftRatio < 0.5 ? 'doubt' : 'focus'
  return 'neutral'
}

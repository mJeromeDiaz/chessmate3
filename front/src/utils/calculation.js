import { Chess } from 'chess.js'
import { t } from '@/i18n'
import { numberedLine } from '@/utils/notation'

/**
 * The calculation module (docs/CALCULATION.md): a mate thought over on a frozen board, then its
 * whole line played at once, both sides, each half-move within `plySeconds` of the previous one.
 * Pure, so that it can be tested alone.
 */

/** The longest mate drawn: a line never has more half-moves than one in 4. */
export const MAX_MATE = 4

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
 * mate has (`mate` known), or as a mate in MAX_MATE has (its length hidden).
 *
 * @param {string} fen where the line starts
 * @param {string[]} played UCI
 * @param {number|null} mate
 */
export function lineOver(fen, played, mate) {
  if (played.length === 0) return false
  if (new Chess(after(fen, played)).isCheckmate()) return true
  return played.length >= matePlies(mate ?? MAX_MATE)
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
 * @param {{status: string, expected?: {san: string}[], answer?: {san: string}[], mistakeAt?: number|null}} result
 * @param {string} fen
 */
export function verdictText(result, fen) {
  const solution = numberedLine(
    (result.expected ?? []).map(m => m.san),
    fen
  )
  if (result.status === 'right') return solution
  const yours = numberedLine(
    (result.answer ?? []).map(m => m.san),
    fen
  )
  return [
    result.status === 'late'
      ? t('calculation.player.lateText')
      : t('calculation.player.mistakeAt', { n: (result.mistakeAt ?? 0) + 1 }),
    t('calculation.player.solution', { line: solution }),
    yours ? t('calculation.player.yours', { line: yours }) : ''
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

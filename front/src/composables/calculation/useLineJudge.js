import { ref } from 'vue'
import { isEngineAbort, useEngine } from '@/composables/engine/useEngine'
import { after, finalScore, judgeLine } from '@/utils/calculation'

/**
 * Stockfish judging the lines of chapter 3 of the calculation path (docs/CALCULATION.md,
 * § 2 quater): every position of a line is analysed as soon as it is on the board, while the
 * player plays the next half-move, so that the verdict is ready when the line ends (the server
 * times the line up to the submission). Positions are kept by FEN: the two lines of 3.2 share
 * their start.
 *
 * @typedef {import('@/utils/engine/uci').Score} Score
 */

/** How long the engine thinks over each position. */
export const ANALYSE_MS = 1000
/** The opponent's replies looked at to draw the decoy of 3.1. */
export const REPLIES_PV = 8

export function useLineJudge() {
  const engine = useEngine()
  /** @type {Map<string, Promise<Score|null>>} */
  const scores = new Map()
  /** An analysis failed (the engine did not start or crashed): no verdict but the solution's. */
  const failed = ref(false)

  /**
   * @param {unknown} error
   * @returns {null}
   */
  function lost(error) {
    if (!isEngineAbort(error)) failed.value = true
    return null
  }

  /**
   * The score of a position from the side to move, analysed once.
   *
   * @param {string} fen
   * @returns {Promise<Score|null>}
   */
  function scoreOf(fen) {
    let known = scores.get(fen)
    if (!known) {
      const final = finalScore(fen)
      known = final
        ? Promise.resolve(final)
        : engine
            .analyse(fen, { movetime: ANALYSE_MS })
            .then(({ lines }) => lines[0]?.score ?? null, lost)
      scores.set(fen, known)
    }
    return known
  }

  /**
   * Has every position of a line analysed (those not seen yet go in line behind the others).
   *
   * @param {string} fen where the line starts
   * @param {string[]} moves UCI
   */
  function follow(fen, moves) {
    for (let i = 0; i <= moves.length; i++)
      scoreOf(after(fen, moves.slice(0, i)))
  }

  /**
   * The engine's verdict on a line, null when the engine failed on one of its positions.
   *
   * @param {string} fen where the line starts
   * @param {string[]} moves UCI
   * @param {number} toleranceCp
   * @returns {Promise<{right: boolean, mistakeAt: number|null}|null>}
   */
  async function verdict(fen, moves, toleranceCp) {
    const line = await Promise.all(
      Array.from({ length: moves.length + 1 }, (_, i) =>
        scoreOf(after(fen, moves.slice(0, i)))
      )
    )
    if (line.some(s => s === null)) return null
    return judgeLine(/** @type {Score[]} */ (line), toleranceCp)
  }

  /**
   * The opponent's best replies in a position (3.1), null when the engine failed.
   *
   * @param {string} fen
   * @returns {Promise<import('@/utils/engine/uci').EngineLine[]|null>}
   */
  function replies(fen) {
    return engine
      .analyse(fen, { movetime: ANALYSE_MS, multiPv: REPLIES_PV })
      .then(({ lines }) => lines, lost)
  }

  /** Forgets the positions analysed and gives up the searches running (a new item). */
  function reset() {
    engine.stop()
    scores.clear()
    failed.value = false
  }

  return { status: engine.status, failed, follow, verdict, replies, reset }
}

import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { isEngineAbort, useEngine } from '@/composables/engine/useEngine'
import {
  REVIEW_MOVETIME,
  finalScore,
  mistakesOf,
  positionsOf,
  reviewMoves,
  whiteValue
} from '@/utils/arenaReview'

/**
 * The review of a game of the arena once over (docs/ARENA.md, § 6 quinquies): Stockfish in the
 * browser looks at each position, one after the other ({@link REVIEW_MOVETIME} each), and each
 * move gets its verdict as soon as both positions around it are known. Nothing is sent: the
 * review stays in the page.
 *
 * @typedef {import('@/utils/arenaReview').PositionEval} PositionEval
 */

/**
 * @param {{engine?: ReturnType<typeof useEngine>, movetime?: number}} [options]
 */
export function useArenaReview({
  engine = useEngine(),
  movetime = REVIEW_MOVETIME
} = {}) {
  /** @type {import('vue').ShallowRef<{fen: string, san: string|null, uci: string|null}[]>} */
  const positions = shallowRef([])
  /** @type {import('vue').ShallowRef<PositionEval[]>} */
  const evals = shallowRef([])
  /** @type {import('vue').Ref<'idle'|'running'|'done'|'failed'>} */
  const state = ref('idle')
  let generation = 0

  const moves = computed(() => reviewMoves(positions.value, evals.value))
  const progress = computed(() =>
    positions.value.length ? evals.value.length / positions.value.length : 0
  )
  /** White's score after each position known, for the curve. */
  const curve = computed(() =>
    evals.value.map((value, i) =>
      whiteValue(value.score, positions.value[i].fen)
    )
  )

  /**
   * Looks at the game's positions; a review already running is given up.
   *
   * @param {string[]} gameMoves UCI
   */
  async function start(gameMoves) {
    const mine = ++generation
    engine.stop()
    positions.value = positionsOf(gameMoves)
    evals.value = []
    state.value = 'running'
    try {
      for (const position of positions.value) {
        const over = finalScore(position.fen)
        /** @type {PositionEval} */
        let value
        if (over) value = { score: over, bestMove: null }
        else {
          const { lines, bestMove } = await engine.analyse(position.fen, {
            movetime
          })
          if (mine !== generation) return
          value = { score: lines[0]?.score ?? { cp: 0 }, bestMove }
        }
        evals.value = [...evals.value, value]
      }
      state.value = 'done'
    } catch (e) {
      if (mine !== generation || isEngineAbort(e)) return
      state.value = 'failed'
    }
  }

  function stop() {
    generation++
    engine.stop()
    if (state.value === 'running') state.value = 'idle'
  }

  onScopeDispose(() => {
    generation++
  })

  return {
    positions,
    evals,
    moves,
    curve,
    progress,
    state,
    start,
    stop,
    /** @param {'white'|'black'} side */
    mistakes: side => mistakesOf(moves.value, side)
  }
}

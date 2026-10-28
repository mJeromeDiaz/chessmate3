import { computed, ref, watch } from 'vue'
import { positionsOf } from '@/utils/arenaReview'

/**
 * Stepping through the moves of a game in progress (docs/ARENA.md, § 3): the board shows a past
 * position while the game goes on. It stays there when a new move comes (the boss's), the
 * player being told so; only the way back to the game (`live()`, or a step past the last move)
 * gives the hand back.
 *
 * @param {import('vue').Ref<string[]>} moves the game's moves (UCI), growing
 * @param {import('vue').Ref<string|undefined>} gameId a new game goes back to the live position
 */
export function useMoveStepper(moves, gameId) {
  /** @type {import('vue').Ref<number|null>} the position looked at (after n moves), null: live */
  const viewed = ref(null)
  /** The moves count when the player stepped back: more since, the boss has played. */
  const seen = ref(0)

  const positions = computed(() => positionsOf(moves.value))
  const last = computed(() => positions.value.length - 1)
  const browsing = computed(() => viewed.value !== null)
  /** The position on the board: 0 the start, n after the n-th move. */
  const cursor = computed(() => viewed.value ?? last.value)
  const shown = computed(() => positions.value[cursor.value])
  /** A move played while the player was looking back. */
  const moved = computed(() => browsing.value && last.value > seen.value)

  /**
   * Shows the position after `ply` moves; at or past the last one, back to the game.
   *
   * @param {number} ply
   */
  function go(ply) {
    const target = Math.max(0, Math.min(ply, last.value))
    if (target >= last.value) {
      viewed.value = null
      return
    }
    if (viewed.value === null) seen.value = last.value
    viewed.value = target
  }

  function live() {
    viewed.value = null
  }

  watch(gameId, live)

  return { positions, last, cursor, shown, browsing, moved, go, live }
}

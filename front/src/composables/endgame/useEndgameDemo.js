import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { endgameApi } from '@/services/api'
import { tablebaseUnavailable } from '@/composables/endgame/useEndgameGame'
import { demoMove, demoOver, gameFrom, playUci } from '@/utils/endgame'

/**
 * A demonstration of an endgame (docs/ENDGAMES.md, § 9): the best line of both sides from a
 * position, worked out move by move from the tablebase (`demoMove`) while it is shown, up to the
 * lesson's goal (`demoOver`). The line grows in the background; `cursor` is the half-move shown
 * (0: the start), moved by the player or the player's autoplay. Lichess unavailable: the line stops
 * where it is, `status` says so (no Stockfish here).
 *
 * @typedef {{fen: string, goal: import('@/utils/endgame').Goal, result: import('@/utils/endgame').Result, holdMoves: number|null}} DemoLesson
 * @typedef {'idle'|'loading'|'done'|'unavailable'|'error'} DemoStatus
 */

/**
 * @param {{tablebase?: (fen: string) => Promise<import('@/utils/endgame').TablebaseAnswer>}} [options]
 */
export function useEndgameDemo({
  tablebase = fen => endgameApi.tablebase(fen)
} = {}) {
  /** @type {import('vue').ShallowRef<DemoLesson|null>} */
  const lesson = shallowRef(null)
  /** @type {import('vue').Ref<string[]>} the line so far, UCI */
  const moves = ref([])
  /** @type {import('vue').Ref<string[]>} the same, SAN (English letters) */
  const sans = ref([])
  /** @type {import('vue').Ref<string[]>} the position after each half-move, the start first */
  const fens = ref([])
  /** @type {import('vue').Ref<DemoStatus>} */
  const status = ref(/** @type {DemoStatus} */ ('idle'))
  const cursor = ref(0)
  /** Bumped by each new demonstration and on dispose: an older line's answers are dropped. */
  let generation = 0

  const fen = computed(
    () => fens.value[cursor.value] ?? lesson.value?.fen ?? ''
  )
  /** The move that led to the position shown, for the board to mark. */
  const lastMove = computed(() =>
    cursor.value > 0 ? moves.value[cursor.value - 1] : null
  )
  /** The line is still growing past the position shown. */
  const waiting = computed(
    () => status.value === 'loading' && cursor.value >= moves.value.length
  )
  const atEnd = computed(
    () => status.value !== 'loading' && cursor.value >= moves.value.length
  )

  /**
   * Starts a demonstration from a position: its line worked out in the background.
   *
   * @param {DemoLesson} value
   */
  function load(value) {
    generation++
    lesson.value = value
    moves.value = []
    sans.value = []
    fens.value = [value.fen]
    cursor.value = 0
    status.value = 'loading'
    grow(generation, value).catch(() => {})
  }

  /**
   * @param {number} mine this line's generation
   * @param {DemoLesson} current
   */
  async function grow(mine, current) {
    const side = current.fen.split(' ')[1] ?? 'w'
    let position = current.fen
    let playerMoves = 0
    for (;;) {
      let answer
      try {
        answer = await tablebase(position)
      } catch (e) {
        if (mine === generation)
          status.value = tablebaseUnavailable(e) ? 'unavailable' : 'error'
        return
      }
      if (mine !== generation) return
      const game = gameFrom(position)
      const isMine = game.turn() === side
      const uci = answer.known ? demoMove(answer, isMine, current.result) : null
      const move = uci ? playUci(game, uci) : null
      if (!move || !uci) {
        status.value = moves.value.length ? 'done' : 'unavailable'
        return
      }
      if (isMine) playerMoves++
      position = game.fen()
      moves.value = [...moves.value, uci]
      sans.value = [...sans.value, move.san]
      fens.value = [...fens.value, position]
      const plies = moves.value.length
      if (demoOver(current, game, move, isMine, playerMoves, plies)) {
        status.value = 'done'
        return
      }
    }
  }

  /** One half-move on, when it is known. */
  function next() {
    if (cursor.value < moves.value.length) cursor.value++
  }

  function previous() {
    if (cursor.value > 0) cursor.value--
  }

  /** @param {number} ply */
  function goTo(ply) {
    cursor.value = Math.max(0, Math.min(ply, moves.value.length))
  }

  function restart() {
    cursor.value = 0
  }

  onScopeDispose(() => {
    generation++
  })

  return {
    lesson,
    moves,
    sans,
    fens,
    status,
    cursor,
    fen,
    lastMove,
    waiting,
    atEnd,
    load,
    next,
    previous,
    goTo,
    restart
  }
}

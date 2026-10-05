import { computed, ref } from 'vue'
import { Chess } from 'chess.js'

/**
 * @typedef {object} ReplayLine
 * @property {string} startFen normalized FEN (4 fields) or a full one: where the line starts
 * @property {string[]} moves SAN moves from there, both sides
 * @property {'white'|'black'} orientation the side the user plays
 *
 * @typedef {'correct'|'wrong'|'done'|'ignored'} ReplayVerdict
 */

/**
 * @param {string} uci
 * @returns {{from: string, to: string, promotion?: string}}
 */
const toMove = uci => ({
  from: uci.slice(0, 2),
  to: uci.slice(2, 4),
  ...(uci[4] ? { promotion: uci[4] } : {})
})

/** @param {string} fen a normalized FEN (4 fields) or a full one */
const fullFen = fen => (fen.split(' ').length === 4 ? `${fen} 0 1` : fen)

/**
 * A repertoire segment or line replayed on the board from its start position, client side only
 * (the end-of-run review, docs/TRAINING.md § 7): the opponent's moves are played alone, the user's
 * are checked against the line. A wrong move is taken back and the right one shown (arrow); it is
 * then the only move accepted, as in the test. Nothing is sent to the server.
 *
 * Phases: idle → showing (opponent's moves) → playing ⇄ correcting → done; invalid when the line
 * cannot be played from its start position.
 *
 * @param {object} [options]
 * @param {(ms: number) => Promise<void>} [options.wait]
 * @param {number} [options.moveMs] pause before each of the opponent's moves
 */
export function useLineReplay(options = {}) {
  const {
    wait = ms => new Promise(resolve => setTimeout(resolve, ms)),
    moveMs = 400
  } = options

  let chess = new Chess()
  /** @type {string[]} UCI moves of the line. */
  let line = []
  let generation = 0

  const fen = ref(chess.fen())
  const orientation = ref(/** @type {'white'|'black'} */ ('white'))
  /** @type {import('vue').Ref<'idle'|'showing'|'playing'|'correcting'|'done'|'invalid'>} */
  const phase = ref('idle')
  /** Index in the line of the next move to play. */
  const cursor = ref(0)
  const total = ref(0)
  const mistakes = ref(0)
  /** @type {import('vue').Ref<{square: string, type: string}[]>} */
  const highlights = ref([])
  /** @type {import('vue').Ref<{from: string, to: string, type: string}[]>} */
  const arrows = ref([])

  const movableColor = computed(() =>
    phase.value === 'playing' || phase.value === 'correcting'
      ? orientation.value
      : null
  )
  const expectedMove = computed(() => line[cursor.value] ?? null)

  /** @param {string} uci */
  function apply(uci) {
    const move = chess.move(toMove(uci))
    fen.value = chess.fen()
    cursor.value++
    highlights.value = [
      { square: move.from, type: 'lastMove' },
      { square: move.to, type: 'lastMove' }
    ]
  }

  const userToMove = () =>
    (chess.turn() === 'w' ? 'white' : 'black') === orientation.value

  /**
   * Plays the opponent's moves until the user's turn, or the end of the line.
   *
   * @param {number} turn
   */
  async function advance(turn) {
    while (cursor.value < line.length && !userToMove()) {
      phase.value = 'showing'
      await wait(moveMs)
      if (turn !== generation) return
      apply(line[cursor.value])
    }
    phase.value = cursor.value >= line.length ? 'done' : 'playing'
  }

  /**
   * Starts a line from its start position.
   *
   * @param {ReplayLine} replay
   * @returns {Promise<boolean>} false: the line cannot be played (invalid)
   */
  async function load(replay) {
    const turn = ++generation
    cursor.value = 0
    mistakes.value = 0
    highlights.value = []
    arrows.value = []
    orientation.value = replay.orientation
    try {
      const probe = new Chess(fullFen(replay.startFen))
      line = replay.moves.map(san => probe.move(san).lan)
      chess = new Chess(fullFen(replay.startFen))
    } catch {
      line = []
      total.value = 0
      phase.value = 'invalid'
      return false
    }
    total.value = line.length
    fen.value = chess.fen()
    await advance(turn)
    return true
  }

  /**
   * The user played a move on the board.
   *
   * @param {string} uci
   * @returns {ReplayVerdict} wrong or ignored: the board goes back to `fen`
   */
  function play(uci) {
    if (phase.value !== 'playing' && phase.value !== 'correcting')
      return 'ignored'
    const expected = expectedMove.value
    if (!expected) return 'ignored'
    if (uci !== expected) {
      if (phase.value === 'playing') mistakes.value++
      phase.value = 'correcting'
      arrows.value = [
        {
          from: expected.slice(0, 2),
          to: expected.slice(2, 4),
          type: 'solution'
        }
      ]
      return 'wrong'
    }
    arrows.value = []
    apply(uci)
    if (cursor.value >= line.length) {
      phase.value = 'done'
      return 'done'
    }
    void advance(generation)
    return 'correct'
  }

  /** Stops any animation in progress (leaving the replay). */
  function dispose() {
    generation++
    phase.value = 'idle'
  }

  return {
    fen,
    orientation,
    phase,
    cursor,
    total,
    mistakes,
    highlights,
    arrows,
    movableColor,
    load,
    play,
    dispose
  }
}

import { computed, ref, shallowRef } from 'vue'
import { Chess } from 'chess.js'

/**
 * @typedef {object} PuzzleData
 * @property {string} fen position before the opponent's first move
 * @property {string[]} moves UCI moves: [0] is the opponent's, then player / opponent alternately
 * @property {'white'|'black'} playerColor
 *
 * @typedef {'solved'|'failed'} Outcome
 *
 * @typedef {object} PuzzleOptions
 * @property {(outcome: Outcome, report: {moves: string[], hintLevel: number, solutionShown: boolean}) => void} [onResolve]
 *   called once, as soon as the rated outcome is known: at the first mistake, hint or solution
 *   display (failed), or when the solution is completed cleanly (solved)
 * @property {() => void} [onComplete] called when the final position is reached
 * @property {{opponent?: number, reply?: number, solutionStep?: number}} [delays] in ms
 */

/**
 * Puzzle logic, independent of the board component and of the API (docs/PUZZLES.md):
 * plays the opponent's moves, checks the player's (any checkmating move wins, so alternative
 * mates in one are accepted), handles mistakes, progressive hints and the animated solution.
 *
 * Phases: idle → intro (opponent's first move pending) → playing → complete.
 *
 * @param {PuzzleOptions} [options]
 */
export function usePuzzle(options = {}) {
  const delays = {
    opponent: 600,
    reply: 350,
    solutionStep: 700,
    ...options.delays
  }

  /** @type {import('vue').ShallowRef<PuzzleData|null>} */
  const puzzle = shallowRef(null)
  const phase = ref(/** @type {'idle'|'intro'|'playing'|'complete'} */ ('idle'))
  const fen = ref('')
  const lastMove = ref(/** @type {{from: string, to: string}|null} */ (null))
  /** Index in puzzle.moves of the next move to play. */
  const cursor = ref(0)
  const failed = ref(false)
  const outcome = ref(/** @type {Outcome|null} */ (null))
  /** Highest hint level used on this puzzle (reported to the server). */
  const hintLevel = ref(0)
  /** Hint level shown for the current move (cleared by each correct move). */
  const hintShown = ref(0)
  const solutionShown = ref(false)
  /** Every move the player tried, wrong ones included (what the server replays). */
  const moveLog = ref(/** @type {string[]} */ ([]))
  const feedback = ref(
    /** @type {{square: string, type: 'error'|'success'}|null} */ (null)
  )

  let game = new Chess()
  let generation = 0

  const orientation = computed(() => puzzle.value?.playerColor ?? 'white')
  const movableColor = computed(() =>
    phase.value === 'playing' ? orientation.value : null
  )
  const expectedMove = computed(() => puzzle.value?.moves[cursor.value] ?? null)

  /** Squares to colour on the board. */
  const highlights = computed(() => {
    const list = []
    if (lastMove.value) {
      list.push({ square: lastMove.value.from, type: 'lastMove' })
      list.push({ square: lastMove.value.to, type: 'lastMove' })
    }
    if (feedback.value) list.push(feedback.value)
    if (
      phase.value === 'playing' &&
      hintShown.value >= 1 &&
      expectedMove.value
    ) {
      list.push({ square: expectedMove.value.slice(0, 2), type: 'hint' })
    }
    return list
  })

  const arrows = computed(() =>
    phase.value === 'playing' && hintShown.value >= 2 && expectedMove.value
      ? [
          {
            from: expectedMove.value.slice(0, 2),
            to: expectedMove.value.slice(2, 4),
            type: 'hint'
          }
        ]
      : []
  )

  /**
   * Starts a puzzle: shows the initial position, then plays the opponent's first move.
   *
   * @param {PuzzleData} data
   * @returns {Promise<void>} resolved when the player can move
   */
  async function load(data) {
    const run = ++generation
    puzzle.value = data
    game = new Chess(data.fen)
    fen.value = data.fen
    lastMove.value = null
    cursor.value = 0
    failed.value = false
    outcome.value = null
    hintLevel.value = 0
    hintShown.value = 0
    solutionShown.value = false
    moveLog.value = []
    feedback.value = null
    phase.value = 'intro'

    await wait(delays.opponent)
    if (run !== generation) return
    apply(data.moves[0])
    phase.value = 'playing'
  }

  /**
   * The player tries a move.
   *
   * @param {string} uci
   * @returns {'correct'|'wrong'|'ignored'} on 'wrong', the board must go back to `fen`
   */
  function play(uci) {
    if (phase.value !== 'playing' || !puzzle.value) return 'ignored'

    const probe = new Chess(game.fen())
    const move = tryMove(probe, uci)
    if (!move) return 'ignored'
    moveLog.value = [...moveLog.value, uci]

    const mates = probe.isCheckmate()
    if (uci !== expectedMove.value && !mates) {
      feedback.value = { square: move.to, type: 'error' }
      fail()
      return 'wrong'
    }

    apply(uci)
    feedback.value = { square: move.to, type: 'success' }
    hintShown.value = 0
    if (mates || cursor.value >= puzzle.value.moves.length) {
      finish()
      return 'correct'
    }

    phase.value = 'intro'
    const run = generation
    wait(delays.reply).then(() => {
      if (run !== generation || !puzzle.value) return
      apply(puzzle.value.moves[cursor.value])
      feedback.value = null
      if (cursor.value >= puzzle.value.moves.length) finish()
      else phase.value = 'playing'
    })
    return 'correct'
  }

  /** Next hint level: 1 = the piece to move, 2 = the move as an arrow. Counts as a failure. */
  function hint() {
    if (phase.value !== 'playing' || hintShown.value >= 2) return
    hintShown.value += 1
    hintLevel.value = Math.max(hintLevel.value, hintShown.value)
    fail()
  }

  /**
   * Plays the rest of the solution, one animated move at a time. Counts as a failure.
   *
   * @returns {Promise<void>}
   */
  async function showSolution() {
    if (!puzzle.value || phase.value === 'complete' || phase.value === 'idle')
      return
    const run = ++generation
    solutionShown.value = true
    fail()
    feedback.value = null
    phase.value = 'intro'
    while (cursor.value < puzzle.value.moves.length) {
      await wait(delays.solutionStep)
      if (run !== generation) return
      apply(puzzle.value.moves[cursor.value])
    }
    finish()
  }

  /** Stops any pending timer (component unmounted, next puzzle...). */
  function dispose() {
    generation++
  }

  function fail() {
    failed.value = true
    resolve('failed')
  }

  function finish() {
    phase.value = 'complete'
    resolve('solved')
    options.onComplete?.()
  }

  /** @param {Outcome} value */
  function resolve(value) {
    if (outcome.value) return
    outcome.value = failed.value ? 'failed' : value
    options.onResolve?.(outcome.value, {
      moves: [...moveLog.value],
      hintLevel: hintLevel.value,
      solutionShown: solutionShown.value
    })
  }

  /** @param {string} uci */
  function apply(uci) {
    const move = tryMove(game, uci)
    if (!move) throw new Error(`Illegal puzzle move ${uci}`)
    fen.value = game.fen()
    lastMove.value = { from: move.from, to: move.to }
    cursor.value += 1
  }

  return {
    puzzle,
    phase,
    fen,
    lastMove,
    orientation,
    movableColor,
    highlights,
    arrows,
    failed,
    outcome,
    hintLevel,
    hintShown,
    solutionShown,
    moveLog,
    load,
    play,
    hint,
    showSolution,
    dispose
  }
}

/**
 * @param {Chess} chess
 * @param {string} uci
 */
function tryMove(chess, uci) {
  try {
    return chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
  } catch {
    return null // chess.js 1.x throws on illegal moves
  }
}

/** @param {number} ms */
function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

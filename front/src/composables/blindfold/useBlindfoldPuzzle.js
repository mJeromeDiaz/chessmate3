import { computed, ref, shallowRef } from 'vue'
import { Chess } from 'chess.js'

/**
 * @typedef {object} BlindfoldPuzzleData
 * @property {string} fen position before the opponent's first move
 * @property {string[]} moves UCI moves: [0] is the opponent's, then player / opponent alternately
 * @property {'white'|'black'} playerColor
 *
 * @typedef {object} BlindfoldSettings the item's settings (docs/BLINDFOLD.md)
 * @property {number} visibleSeconds how long the position is shown (the first time, and on a peek)
 * @property {number} hiddenSeconds the pause, position hidden, before playing
 * @property {number} peeks peeks allowed after a mistake
 *
 * @typedef {'solved'|'helped'|'failed'} BlindfoldStatus
 *
 * @typedef {'idle'|'intro'|'show'|'hidden'|'play'|'reply'|'solution'|'complete'} BlindfoldPhase
 *   intro: the opponent's first move; show: position visible (memorize); hidden: the pause; play:
 *   the player's turn, board empty; reply: the opponent answers; solution: played on the visible
 *   board after a failure; complete: the final position, visible
 *
 * @typedef {{square: string, type: 'lastMove'|'hint'|'error'|'success'}} Highlight
 *
 * @typedef {object} BlindfoldOptions
 * @property {(status: BlindfoldStatus, report: {moves: string[], hintLevel: number, solutionShown: boolean}) => void} [onResolve]
 *   called once, as soon as the outcome is known: at the end of a solved puzzle, or at the failure
 *   (the solution is then played)
 * @property {(status: BlindfoldStatus) => void} [onComplete] the final position is reached
 * @property {{opponent?: number, reply?: number, flash?: number, solutionStep?: number}} [delays] in ms
 */

/** The empty board shown while the player plays from memory. */
export const EMPTY_BOARD = '8/8/8/8/8/8/8/8 w - - 0 1'

/**
 * A blindfold puzzle (docs/BLINDFOLD.md), independent of the board component and of the API.
 * The position is shown (`visibleSeconds`, or until "J'ai mémorisé"), hidden (`hiddenSeconds`),
 * then the player plays on an empty board by clicking the starting square, then the arrival
 * square. A correct move: the opponent's reply comes as text and a flash of its squares. A wrong
 * (legal) move: while peeks remain, the current position is shown again, hidden again, and the
 * player goes on from there; one mistake more fails the puzzle and the solution is played on the
 * visible board. An impossible move is refused without counting (the server only replays legal
 * moves). Any checkmating move wins, as in the other puzzles.
 *
 * @param {BlindfoldOptions} [options]
 */
export function useBlindfoldPuzzle(options = {}) {
  const delays = {
    opponent: 600,
    reply: 600,
    flash: 1500,
    solutionStep: 700,
    ...options.delays
  }

  /** @type {import('vue').ShallowRef<BlindfoldPuzzleData|null>} */
  const puzzle = shallowRef(null)
  const phase = ref(/** @type {BlindfoldPhase} */ ('idle'))
  /** The real position (shown or not). */
  const fen = ref('')
  const lastMove = ref(/** @type {{from: string, to: string}|null} */ (null))
  /** Index in puzzle.moves of the next move to play. */
  const cursor = ref(0)
  /** Seconds left in `show` or `hidden`. */
  const countdown = ref(0)
  /** The position is shown again after a mistake. */
  const peeking = ref(false)
  const mistakes = ref(0)
  const peeksLeft = ref(0)
  /** The square picked as the start of the move. */
  const selected = ref(/** @type {string|null} */ (null))
  /** A pawn move to the last rank, waiting for its piece. */
  const promotion = ref(/** @type {{from: string, to: string}|null} */ (null))
  /** Squares lit for a moment (the opponent's reply, a wrong move). */
  const flash = ref(/** @type {Highlight[]} */ ([]))
  /** What just happened, for the status line. */
  const message = ref(
    /** @type {{text: string, tone: 'info'|'success'|'error'}|null} */ (null)
  )
  /** The moves played since the position shown first (SAN), the opponent's first one included. */
  const history = ref(/** @type {string[]} */ ([]))
  /** Every move the player tried, wrong ones included (what the server replays). */
  const moveLog = ref(/** @type {string[]} */ ([]))
  const solutionShown = ref(false)
  const status = ref(/** @type {BlindfoldStatus|null} */ (null))

  /** @type {BlindfoldSettings} */
  let settings = { visibleSeconds: 10, hiddenSeconds: 3, peeks: 1 }
  let game = new Chess()
  /** Ignores the timers of a puzzle left (next puzzle, unmounted). */
  let generation = 0
  /** @type {ReturnType<typeof setInterval>|undefined} */
  let countdownTimer
  /** @type {ReturnType<typeof setTimeout>|undefined} */
  let flashTimer

  const orientation = computed(() => puzzle.value?.playerColor ?? 'white')
  /** The position is on the board (memorizing, solution, end); otherwise the board is empty. */
  const visible = computed(() =>
    ['intro', 'show', 'solution', 'complete'].includes(phase.value)
  )
  const boardFen = computed(() => (visible.value ? fen.value : EMPTY_BOARD))
  const expectedMove = computed(() => puzzle.value?.moves[cursor.value] ?? null)

  const highlights = computed(() => {
    /** @type {Highlight[]} */
    const list = []
    if (visible.value) {
      if (lastMove.value) {
        list.push({ square: lastMove.value.from, type: 'lastMove' })
        list.push({ square: lastMove.value.to, type: 'lastMove' })
      }
      return list
    }
    list.push(...flash.value)
    if (selected.value) list.push({ square: selected.value, type: 'hint' })
    return list
  })

  /**
   * Starts a puzzle: the initial position, the opponent's first move, then the position to
   * memorize.
   *
   * @param {BlindfoldPuzzleData} data
   * @param {Partial<BlindfoldSettings>} [values]
   * @returns {Promise<void>} resolved when the position is shown to memorize
   */
  async function load(data, values = {}) {
    dispose()
    const run = generation
    settings = { ...settings, ...values }
    puzzle.value = data
    game = new Chess(data.fen)
    fen.value = data.fen
    lastMove.value = null
    cursor.value = 0
    countdown.value = 0
    peeking.value = false
    mistakes.value = 0
    peeksLeft.value = settings.peeks
    selected.value = null
    promotion.value = null
    flash.value = []
    message.value = null
    history.value = []
    moveLog.value = []
    solutionShown.value = false
    status.value = null
    phase.value = 'intro'

    await wait(delays.opponent)
    if (run !== generation) return
    apply(data.moves[0])
    show()
  }

  /** The position to memorize, for `visibleSeconds`. */
  function show() {
    countdownPhase('show', settings.visibleSeconds, hide)
  }

  /** Hidden for `hiddenSeconds`, then the player's turn. */
  function hide() {
    countdownPhase('hidden', settings.hiddenSeconds, () => {
      peeking.value = false
      phase.value = 'play'
    })
  }

  /** "J'ai mémorisé": hides the position before the end of its time. */
  function memorized() {
    if (phase.value === 'show') hide()
  }

  /**
   * A square clicked on the empty board: the start of the move, then its arrival.
   *
   * @param {string} square
   * @returns {'selected'|'cleared'|'promotion'|'impossible'|'correct'|'wrong'|'ignored'}
   */
  function clickSquare(square) {
    if (phase.value !== 'play' || promotion.value) return 'ignored'
    if (!selected.value) {
      selected.value = square
      return 'selected'
    }
    const from = selected.value
    selected.value = null
    if (from === square) return 'cleared'
    const candidates = game
      .moves({ verbose: true })
      .filter(m => m.from === from && m.to === square)
    if (!candidates.length) {
      message.value = {
        text: `${from}-${square} : coup impossible dans cette position.`,
        tone: 'error'
      }
      return 'impossible'
    }
    if (candidates.some(m => m.promotion)) {
      promotion.value = { from, to: square }
      return 'promotion'
    }
    return move(from + square)
  }

  /**
   * The piece of a pending promotion.
   *
   * @param {'q'|'r'|'b'|'n'} piece
   */
  function promote(piece) {
    const pending = promotion.value
    if (!pending || phase.value !== 'play') return 'ignored'
    promotion.value = null
    return move(pending.from + pending.to + piece)
  }

  function cancelPromotion() {
    promotion.value = null
  }

  /**
   * Plays a legal move of the player.
   *
   * @param {string} uci
   * @returns {'correct'|'wrong'}
   */
  function move(uci) {
    const data = /** @type {BlindfoldPuzzleData} */ (puzzle.value)
    moveLog.value = [...moveLog.value, uci]
    const probe = new Chess(game.fen())
    const played = /** @type {import('chess.js').Move} */ (tryMove(probe, uci))
    const mates = probe.isCheckmate()

    if (uci !== expectedMove.value && !mates) {
      mistakes.value++
      lit([
        { square: played.from, type: 'error' },
        { square: played.to, type: 'error' }
      ])
      if (mistakes.value > settings.peeks) {
        message.value = { text: `${played.san} : raté.`, tone: 'error' }
        fail()
        return 'wrong'
      }
      peeksLeft.value--
      message.value = {
        text: `${played.san} n’est pas le bon coup. Regarde encore la position.`,
        tone: 'error'
      }
      peeking.value = true
      show()
      return 'wrong'
    }

    apply(uci)
    message.value = { text: `${played.san} : bien vu !`, tone: 'success' }
    lit([{ square: played.to, type: 'success' }])
    if (mates || cursor.value >= data.moves.length) {
      finish()
      return 'correct'
    }

    phase.value = 'reply'
    const run = generation
    wait(delays.reply).then(() => {
      if (run !== generation || !puzzle.value) return
      const reply = apply(puzzle.value.moves[cursor.value])
      message.value = { text: `L’adversaire joue ${reply.san}.`, tone: 'info' }
      lit([
        { square: reply.from, type: 'lastMove' },
        { square: reply.to, type: 'lastMove' }
      ])
      if (cursor.value >= puzzle.value.moves.length) finish()
      else phase.value = 'play'
    })
    return 'correct'
  }

  /** Gives up: the solution is played on the visible board. Counts as failed. */
  function giveUp() {
    if (['idle', 'intro', 'solution', 'complete'].includes(phase.value)) return
    solutionShown.value = true
    message.value = { text: 'Solution…', tone: 'info' }
    fail()
  }

  /** Stops any pending timer (next puzzle, component unmounted). */
  function dispose() {
    generation++
    clearInterval(countdownTimer)
    clearTimeout(flashTimer)
  }

  /**
   * A phase with a countdown, in whole seconds, then `then`.
   *
   * @param {'show'|'hidden'} name
   * @param {number} seconds
   * @param {() => void} then
   */
  function countdownPhase(name, seconds, then) {
    clearInterval(countdownTimer)
    const run = generation
    phase.value = name
    countdown.value = seconds
    selected.value = null
    promotion.value = null
    countdownTimer = setInterval(() => {
      if (run !== generation) return clearInterval(countdownTimer)
      countdown.value--
      if (countdown.value > 0) return
      clearInterval(countdownTimer)
      then()
    }, 1000)
  }

  /** @param {Highlight[]} squares */
  function lit(squares) {
    clearTimeout(flashTimer)
    flash.value = squares
    flashTimer = setTimeout(() => (flash.value = []), delays.flash)
  }

  function fail() {
    status.value = 'failed'
    resolve()
    playSolution()
  }

  async function playSolution() {
    clearInterval(countdownTimer)
    const run = ++generation
    selected.value = null
    promotion.value = null
    phase.value = 'solution'
    while (puzzle.value && cursor.value < puzzle.value.moves.length) {
      await wait(delays.solutionStep)
      if (run !== generation) return
      apply(puzzle.value.moves[cursor.value])
    }
    complete()
  }

  function finish() {
    status.value = mistakes.value > 0 ? 'helped' : 'solved'
    resolve()
    complete()
  }

  function complete() {
    phase.value = 'complete'
    options.onComplete?.(/** @type {BlindfoldStatus} */ (status.value))
  }

  function resolve() {
    options.onResolve?.(/** @type {BlindfoldStatus} */ (status.value), {
      moves: [...moveLog.value],
      hintLevel: 0,
      solutionShown: solutionShown.value
    })
  }

  /**
   * @param {string} uci
   * @returns {import('chess.js').Move}
   */
  function apply(uci) {
    const played = tryMove(game, uci)
    if (!played) throw new Error(`Illegal puzzle move ${uci}`)
    fen.value = game.fen()
    lastMove.value = { from: played.from, to: played.to }
    history.value = [...history.value, played.san]
    cursor.value += 1
    return played
  }

  return {
    puzzle,
    phase,
    fen,
    boardFen,
    visible,
    orientation,
    lastMove,
    highlights,
    countdown,
    peeking,
    mistakes,
    peeksLeft,
    selected,
    promotion,
    message,
    history,
    moveLog,
    solutionShown,
    status,
    load,
    memorized,
    clickSquare,
    promote,
    cancelPromotion,
    giveUp,
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

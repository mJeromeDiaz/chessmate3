import { computed, ref } from 'vue'
import { Chess } from 'chess.js'
import { normalizeFen } from '@/utils/chess/normalizeFen'

/** Pause before the deviation is played, once the start position is on the board. */
export const DEVIATION_DELAY_MS = 600
/** Each move of "Revoir les coups". */
export const REPLAY_STEP_MS = 250

/**
 * @typedef {{uci: string, san: string}} PlayedMove
 * @typedef {'context'|'opponent'|'user'|'corrected'} MoveKind
 * @typedef {{uci: string, san: string, kind: MoveKind}} ListedMove
 * @typedef {{unit: 'segment'|'line', orientation: 'white'|'black', context: PlayedMove[], deviation: boolean,
 *   label: import('@/utils/repertoireTest').SegmentLabel, rank: number, round: number, retry: boolean, newRound: boolean}} UnitStart
 * @typedef {{unitId: string, repertoireId: string, segmentId: string, index: number, total: number,
 *   play: PlayedMove[], fen: string, ply: number, unit: 'segment'|'line', orientation: 'white'|'black',
 *   label: import('@/utils/repertoireTest').SegmentLabel, start: UnitStart|null}} DrillItemData
 * @typedef {{status: 'answered'|'stale', correct: boolean|null, expected: PlayedMove|null, comment: string|null,
 *   rating: string|null, unitDone: boolean, unitSuccess: boolean|null, retry: boolean}} DrillResultData
 * @typedef {'next'|'correct'|'unitSucceeded'|'unitFailed'|'refused'} Verdict what the page does next
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
 * One repertoire test being played on the board (docs/REPERTOIRE.md § 15), without any API call:
 * the page sends what `attempt` returns and hands the verdict to `resolve`.
 *
 * - A unit starts on its start position at once (the context is listed, not asked), then the
 *   deviation is played after {@link DEVIATION_DELAY_MS}; each question first plays the opponent's
 *   moves before it.
 * - The think time runs from the moment the board can be played (animations excluded).
 * - A wrong move is taken back, the right one is shown (arrow, comment) and is the only move
 *   accepted until played; then the unit goes on.
 *
 * @param {object} [options]
 * @param {() => number} [options.now] ms
 * @param {(ms: number) => Promise<void>} [options.wait]
 * @param {number} [options.moveMs] time given to each move's animation
 */
export function useRepertoireDrill(options = {}) {
  const {
    now = () => Date.now(),
    wait = ms => new Promise(resolve => setTimeout(resolve, ms)),
    moveMs = 250
  } = options

  let chess = new Chess()
  let beforeAttempt = chess.fen()
  /** Where the listed moves start: the initial position, or the question's after a reload. */
  let listStart = chess.fen()
  let thinkStart = 0
  let generation = 0
  /** @type {DrillResultData|null} */
  let pendingWrong = null

  const fen = ref(chess.fen())
  const orientation = ref(/** @type {'white'|'black'} */ ('white'))
  /** idle, showing (animations), playing, submitting, correcting, replaying, unitDone */
  const phase = ref('idle')
  /** @type {import('vue').Ref<ListedMove[]>} */
  const moves = ref([])
  /** Ply of the first listed move: 0, or the question's ply after a reload in the middle of a unit. */
  const firstPly = ref(0)
  /** @type {import('vue').Ref<UnitStart|null>} */
  const start = ref(null)
  /** @type {import('vue').Ref<PlayedMove|null>} */
  const deviation = ref(null)
  /** @type {import('vue').Ref<PlayedMove|null>} The right move, after a wrong one. */
  const expected = ref(null)
  const comment = ref(/** @type {string|null} */ (null))
  /** @type {import('vue').Ref<{success: boolean, retry: boolean}|null>} */
  const outcome = ref(null)
  /** The previous unit was dropped: the repertoire changed meanwhile. */
  const stale = ref(false)
  const index = ref(0)
  const total = ref(0)
  const mistakes = ref(0)
  /** Units finished on this page. */
  const succeeded = ref(0)
  const failed = ref(0)
  /** @type {import('vue').Ref<{square: string, type: string}[]>} */
  const highlights = ref([])
  /** @type {import('vue').Ref<{from: string, to: string, type: string}[]>} */
  const arrows = ref([])

  const movableColor = computed(() =>
    phase.value === 'playing' || phase.value === 'correcting'
      ? orientation.value
      : null
  )

  /**
   * Plays a move on the board.
   *
   * @param {string} uci
   * @param {MoveKind} kind
   * @returns {PlayedMove}
   */
  function apply(uci, kind) {
    const move = chess.move(toMove(uci))
    fen.value = chess.fen()
    moves.value = [...moves.value, { uci, san: move.san, kind }]
    highlights.value = [
      { square: move.from, type: 'lastMove' },
      { square: move.to, type: 'lastMove' }
    ]
    return { uci, san: move.san }
  }

  /**
   * Shows an item: a new unit from its start position, or the next question of the unit.
   *
   * @param {{data: DrillItemData}} item
   * @returns {Promise<void>}
   */
  async function present(item) {
    const turn = ++generation
    const data = item.data
    outcome.value = null
    expected.value = null
    comment.value = null
    arrows.value = []
    pendingWrong = null
    index.value = data.index
    total.value = data.total
    phase.value = 'showing'
    let play = data.play

    if (data.start) {
      start.value = data.start
      orientation.value = data.start.orientation
      mistakes.value = 0
      chess = new Chess()
      fen.value = chess.fen()
      listStart = chess.fen()
      moves.value = []
      firstPly.value = 0
      highlights.value = []
      for (const move of data.start.context) apply(move.uci, 'context')
      deviation.value = data.start.deviation ? (data.play[0] ?? null) : null
      if (play.length > 0) {
        await wait(DEVIATION_DELAY_MS)
        if (turn !== generation) return
      }
    } else if (!sameAfter(play, data.fen)) {
      // A reload in the middle of a unit: the question's position, as it is, with what the item
      // says of its unit (the unit's start and its moves so far are not sent again).
      start.value = {
        unit: data.unit,
        orientation: data.orientation,
        context: [],
        deviation: false,
        label: data.label,
        rank: 1,
        round: 1,
        retry: false,
        newRound: false
      }
      orientation.value = data.orientation
      deviation.value = null
      chess = new Chess(fullFen(data.fen))
      fen.value = chess.fen()
      listStart = chess.fen()
      moves.value = []
      firstPly.value = data.ply
      highlights.value = []
      play = []
    }

    for (const [i, move] of play.entries()) {
      apply(move.uci, 'opponent')
      if (i === 0 && deviation.value && data.start) {
        highlights.value = highlights.value.map(h => ({ ...h, type: 'hint' }))
      }
      await wait(moveMs)
      if (turn !== generation) return
    }
    phase.value = 'playing'
    thinkStart = now()
  }

  /**
   * Whether playing `play` from the board's position reaches `target` (normalized FEN).
   *
   * @param {PlayedMove[]} play
   * @param {string} target
   */
  function sameAfter(play, target) {
    if (moves.value.length === 0) return false
    try {
      const probe = new Chess(chess.fen())
      for (const move of play) probe.move(toMove(move.uci))
      return normalizeFen(probe.fen()) === normalizeFen(fullFen(target))
    } catch {
      return false
    }
  }

  /**
   * The user played a move: what to send to the server, or null when no answer is expected now.
   *
   * @param {string} uci
   * @returns {{moves: string[], hintLevel: number, solutionShown: boolean, thinkMs: number}|null}
   */
  function attempt(uci) {
    if (phase.value !== 'playing') return null
    const probe = new Chess(chess.fen())
    try {
      probe.move(toMove(uci))
    } catch {
      return null
    }
    beforeAttempt = chess.fen()
    const thinkMs = Math.max(0, Math.round(now() - thinkStart))
    apply(uci, 'user')
    phase.value = 'submitting'
    return { moves: [uci], hintLevel: 0, solutionShown: false, thinkMs }
  }

  /**
   * The server's verdict on the move sent.
   *
   * @param {{data: DrillResultData}|null} result null: refused (time up, run over)
   * @returns {Verdict}
   */
  function resolve(result) {
    if (!result) {
      // The runner may already have brought the next item (item closed): keep it playable.
      if (phase.value === 'submitting') phase.value = 'idle'
      return 'refused'
    }
    const data = result.data
    if (data.status === 'stale') {
      takeBack()
      stale.value = true
      phase.value = 'unitDone'
      return 'next'
    }
    stale.value = false
    if (data.correct) {
      if (data.unitDone) return finish(Boolean(data.unitSuccess), data.retry)
      phase.value = 'showing'
      return 'next'
    }
    takeBack()
    mistakes.value++
    pendingWrong = data
    expected.value = data.expected
    comment.value = data.comment
    if (data.expected) {
      arrows.value = [
        {
          from: data.expected.uci.slice(0, 2),
          to: data.expected.uci.slice(2, 4),
          type: 'solution'
        }
      ]
    }
    phase.value = 'correcting'
    return 'correct'
  }

  /**
   * After a wrong move, the right one must be played.
   *
   * @param {string} uci
   * @returns {Verdict|false} false: not the right move (the board goes back)
   */
  function correct(uci) {
    if (phase.value !== 'correcting' || !expected.value) return false
    if (uci !== expected.value.uci) return false
    apply(uci, 'corrected')
    arrows.value = []
    const wrong = pendingWrong
    pendingWrong = null
    if (wrong?.unitDone) return finish(false, wrong.retry)
    phase.value = 'showing'
    return 'next'
  }

  /**
   * Replays the listed moves from where they start (no question); the think time is paused.
   *
   * @returns {Promise<void>}
   */
  async function replay() {
    if (phase.value !== 'playing' && phase.value !== 'unitDone') return
    const turn = generation
    const resumeTo = phase.value
    const startedAt = now()
    const listed = moves.value
    phase.value = 'replaying'
    const probe = new Chess(listStart)
    fen.value = probe.fen()
    for (const move of listed) {
      await wait(REPLAY_STEP_MS)
      if (turn !== generation) return
      probe.move(toMove(move.uci))
      fen.value = probe.fen()
    }
    fen.value = chess.fen()
    thinkStart += now() - startedAt
    phase.value = resumeTo
  }

  function takeBack() {
    chess = new Chess(beforeAttempt)
    fen.value = chess.fen()
    moves.value = moves.value.slice(0, -1)
  }

  /**
   * @param {boolean} success
   * @param {boolean} retry
   * @returns {Verdict}
   */
  function finish(success, retry) {
    outcome.value = { success, retry }
    if (success) succeeded.value++
    else failed.value++
    phase.value = 'unitDone'
    return success ? 'unitSucceeded' : 'unitFailed'
  }

  /** Stops any animation in progress (leaving the page, run over). */
  function dispose() {
    generation++
    phase.value = 'idle'
  }

  return {
    fen,
    orientation,
    phase,
    moves,
    firstPly,
    start,
    deviation,
    expected,
    comment,
    outcome,
    stale,
    index,
    total,
    mistakes,
    succeeded,
    failed,
    highlights,
    arrows,
    movableColor,
    present,
    attempt,
    resolve,
    correct,
    replay,
    dispose
  }
}

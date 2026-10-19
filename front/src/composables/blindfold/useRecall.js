import { computed, ref, shallowRef } from 'vue'
import {
  boardFen,
  correctionMarks,
  placeOn,
  squaresOf,
  toPlacement
} from '@/utils/blindfoldRecall'

/**
 * @typedef {import('@/utils/blindfoldRecall').Squares} Squares
 * @typedef {import('@/utils/blindfoldRecall').RecallResult} RecallResult
 *
 * @typedef {object} RecallData the blindfold_recall item's data
 * @property {string} fen the position shown
 * @property {number} level
 * @property {number} stage
 * @property {'free'|'timed'} mode
 * @property {number} visibleSeconds
 * @property {number} hiddenSeconds
 * @property {{uci: string, san: string}[]} announced
 *
 * @typedef {'idle'|'show'|'hidden'|'build'|'sending'|'complete'} RecallPhase show: the position to
 *   memorize; hidden: the pause; build: the empty board and the palette; sending: the rebuild
 *   submitted; complete: the correction
 *
 * @typedef {object} RecallOptions
 * @property {(placement: {square: string, piece: string}[]) => void} [onSubmit] "J'ai terminé"
 */

/** The empty board of the rebuild. */
export const EMPTY_FEN = '8/8/8/8/8/8/8/8 w - - 0 1'

/**
 * A position to rebuild (docs/BLINDFOLD_RECALL.md), independent of the board component and of the
 * API: shown for `visibleSeconds` (or until "J'ai mémorisé"), hidden for `hiddenSeconds`, then
 * rebuilt on an empty board by choosing a piece in the palette and clicking squares (the same piece
 * again takes it back; the eraser empties a square). "J'ai terminé" hands the placement to
 * `onSubmit`; the page gives the server's correction back with `correct`.
 *
 * @param {RecallOptions} [options]
 */
export function useRecall(options = {}) {
  /** @type {import('vue').ShallowRef<RecallData|null>} */
  const data = shallowRef(null)
  const phase = ref(/** @type {RecallPhase} */ ('idle'))
  const countdown = ref(0)
  /** @type {import('vue').Ref<Squares>} */
  const placed = ref({})
  /** The palette's piece (FEN letter), null for the eraser. */
  const selected = ref(/** @type {string|null} */ (null))
  /** @type {import('vue').ShallowRef<RecallResult|null>} */
  const result = shallowRef(null)
  /** The correction shows the position ("answer") or the player's rebuild ("mine"). */
  const view = ref(/** @type {'answer'|'mine'} */ ('answer'))

  /** @type {ReturnType<typeof setInterval>|undefined} */
  let timer
  let generation = 0

  const placedCount = computed(() => Object.keys(placed.value).length)

  const expected = computed(() =>
    result.value ? squaresOf(result.value.expectedPlacement) : {}
  )

  /** The position on the board: shown, hidden (the empty board under the veil), rebuilt, corrected. */
  const fen = computed(() => {
    if (!data.value) return EMPTY_FEN
    switch (phase.value) {
      case 'show':
        return data.value.fen
      case 'hidden':
        return EMPTY_FEN
      case 'complete':
        return view.value === 'answer'
          ? boardFen(expected.value)
          : boardFen(placed.value)
      default:
        return boardFen(placed.value)
    }
  })

  const highlights = computed(() => {
    if (phase.value !== 'complete' || !result.value) return []
    const marks = correctionMarks(placed.value, expected.value)
    return view.value === 'answer' ? marks.answer : marks.mine
  })

  /**
   * A new position: shown at once.
   *
   * @param {RecallData} value
   */
  function load(value) {
    dispose()
    data.value = value
    placed.value = {}
    selected.value = null
    result.value = null
    view.value = 'answer'
    countdownPhase('show', value.visibleSeconds, hide)
  }

  function hide() {
    countdownPhase('hidden', data.value?.hiddenSeconds ?? 0, () => {
      phase.value = 'build'
    })
  }

  /** "J'ai mémorisé": hides the position before the end of its time. */
  function memorized() {
    if (phase.value === 'show') hide()
  }

  /** @param {string|null} piece a FEN letter, null for the eraser */
  function select(piece) {
    selected.value = piece
  }

  /** @param {string} square */
  function click(square) {
    if (phase.value !== 'build') return
    placed.value = placeOn(placed.value, square, selected.value)
  }

  /** "Tout effacer". */
  function clear() {
    if (phase.value === 'build') placed.value = {}
  }

  /** "J'ai terminé": the rebuild leaves; the board stays as it is until the correction. */
  function submit() {
    if (phase.value !== 'build') return
    phase.value = 'sending'
    options.onSubmit?.(toPlacement(placed.value))
  }

  /** The submission failed: the rebuild can be sent again. */
  function unlock() {
    if (phase.value === 'sending') phase.value = 'build'
  }

  /** @param {RecallResult} value the server's correction */
  function correct(value) {
    result.value = value
    phase.value = 'complete'
  }

  /** @param {'answer'|'mine'} value */
  function show(value) {
    view.value = value
  }

  /** Stops the countdown (next position, component unmounted). */
  function dispose() {
    generation++
    clearInterval(timer)
  }

  /**
   * A phase with a countdown, in whole seconds, then `then`.
   *
   * @param {'show'|'hidden'} name
   * @param {number} seconds
   * @param {() => void} then
   */
  function countdownPhase(name, seconds, then) {
    clearInterval(timer)
    const run = generation
    phase.value = name
    countdown.value = seconds
    if (seconds <= 0) return then()
    timer = setInterval(() => {
      if (run !== generation) return clearInterval(timer)
      countdown.value--
      if (countdown.value > 0) return
      clearInterval(timer)
      then()
    }, 1000)
  }

  return {
    data,
    phase,
    countdown,
    placed,
    placedCount,
    selected,
    result,
    view,
    fen,
    highlights,
    load,
    memorized,
    select,
    click,
    clear,
    submit,
    unlock,
    correct,
    show,
    dispose
  }
}

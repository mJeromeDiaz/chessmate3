import { onScopeDispose, ref } from 'vue'

/**
 * The beats of the end-of-exercise animation (design "Animation Puzzle"), as [ms, step]: the
 * sheet slides up (1), the title pops (2), the XP and the promotion (3), the sparks (4). A miss
 * shakes three times (2–5) before its buttons (5) and settles (6).
 */
export const TIMELINES = {
  win: [
    [30, 1],
    [420, 2],
    [760, 3],
    [1000, 4]
  ],
  miss: [
    [30, 1],
    [430, 2],
    [540, 3],
    [650, 4],
    [760, 5],
    [900, 6]
  ]
}

/**
 * Plays the feedback animation: `step` goes from 0 (hidden) to the last beat of the verdict's
 * timeline. Shared by the professor's bubble and the sheet so that they move together. A user who
 * asked for reduced motion gets the last beat at once.
 */
export function useFeedbackTimeline() {
  const kind = ref(
    /** @type {import('@/utils/feedback').FeedbackKind|null} */ (null)
  )
  const step = ref(0)
  /** Incremented at each play: a new key replays the confetti. */
  const run = ref(0)
  /** @type {ReturnType<typeof setTimeout>[]} */
  let timers = []

  /** @param {import('@/utils/feedback').FeedbackKind} verdict */
  function play(verdict) {
    clear()
    kind.value = verdict
    step.value = 0
    run.value += 1
    const beats = TIMELINES[verdict === 'miss' ? 'miss' : 'win']
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
      step.value = beats[beats.length - 1][1]
      return
    }
    timers = beats.map(([ms, beat]) =>
      setTimeout(() => (step.value = beat), ms)
    )
  }

  /** Back to the game: nothing shown. */
  function reset() {
    clear()
    kind.value = null
    step.value = 0
  }

  function clear() {
    timers.forEach(clearTimeout)
    timers = []
  }

  onScopeDispose(clear)

  return { kind, step, run, play, reset }
}

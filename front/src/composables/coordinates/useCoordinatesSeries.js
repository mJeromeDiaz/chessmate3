import { computed, getCurrentScope, onScopeDispose, ref } from 'vue'

/**
 * @typedef {object} Answer an answer as the API takes it
 * @property {number} index rank of the square in the series
 * @property {string} square the square clicked
 * @property {number} ms time spent on the square
 *
 * @typedef {object} LastAnswer
 * @property {string} target
 * @property {string} clicked
 * @property {boolean} correct
 */

/**
 * A coordinates series being played (docs/BLINDFOLD.md). The squares were drawn by the server at
 * the start: each click is judged here at once (instant feedback), then sent in batches every
 * few seconds, and judged again by the server. A batch that fails (network) is sent again with
 * the next one; one refused because the run is over is dropped. The last answers are sent before
 * the run closes ("Terminer", time up), through the runner's `beforeClose`.
 *
 * @param {{submit: (report: {answers: Answer[]}) => Promise<any>, beforeClose?: (hook: () => Promise<void>) => () => void}} runner
 *   the timed run (useTimeboxedRun)
 * @param {object} [options]
 * @param {number} [options.batchMs] delay between two batches
 * @param {number} [options.maxBatch] answers per batch, at most (the API's limit)
 * @param {() => number} [options.now] clock in ms, for the time spent on each square
 */
export function useCoordinatesSeries(runner, options = {}) {
  const {
    batchMs = 3000,
    maxBatch = 100,
    now = () => performance.now()
  } = options

  /** @type {import('vue').Ref<string[]>} */
  const squares = ref([])
  /** Rank of the square to find. */
  const index = ref(0)
  /** Right answers so far. */
  const correct = ref(0)
  /** @type {import('vue').Ref<LastAnswer|null>} */
  const last = ref(null)
  /** Answers not acknowledged by the server yet. */
  const unsent = ref(0)

  /** @type {Answer[]} */
  let pending = []
  /** @type {Promise<void>|null} */
  let sending = null
  let shownAt = now()
  /** @type {ReturnType<typeof setInterval>|null} */
  let timer = null

  const target = computed(() => squares.value[index.value] ?? null)
  const answered = computed(() => index.value)

  /**
   * Starts (or resumes, after a reload) the series served by the API.
   *
   * @param {{data: {squares: string[], answered?: number, successCount?: number}}} item
   */
  function load(item) {
    squares.value = item.data.squares
    index.value = item.data.answered ?? 0
    correct.value = item.data.successCount ?? 0
    last.value = null
    pending = []
    unsent.value = 0
    shownAt = now()
    if (!timer) timer = setInterval(() => flush().catch(() => {}), batchMs)
  }

  /**
   * Judges a click on the square to find, and moves on to the next square.
   *
   * @param {string} square
   * @returns {boolean|null} whether it was right, null when there is nothing to find
   */
  function answer(square) {
    const expected = target.value
    if (!expected) return null
    const at = now()
    const ok = square === expected
    pending.push({
      index: index.value,
      square,
      ms: Math.max(0, Math.round(at - shownAt))
    })
    unsent.value = pending.length
    index.value++
    if (ok) correct.value++
    last.value = { target: expected, clicked: square, correct: ok }
    shownAt = at
    if (pending.length >= maxBatch) flush().catch(() => {})
    return ok
  }

  /**
   * Sends the pending answers, one batch at a time.
   *
   * @returns {Promise<void>} rejects when a batch could not be sent (kept for the next try)
   */
  async function flush() {
    while (sending) await sending
    while (pending.length) {
      const batch = pending.slice(0, maxBatch)
      const lastIndex = batch[batch.length - 1].index
      sending = runner
        .submit({ answers: batch })
        .then(() => {
          // Judged, or refused because the run is over: either way, done with them.
          pending = pending.filter(a => a.index > lastIndex)
          unsent.value = pending.length
        })
        .finally(() => {
          sending = null
        })
      await sending
    }
  }

  function dispose() {
    if (timer) clearInterval(timer)
    timer = null
    unregister()
  }

  const unregister = runner.beforeClose?.(flush) ?? (() => {})

  if (getCurrentScope()) onScopeDispose(dispose)

  return {
    squares,
    target,
    index,
    answered,
    correct,
    last,
    unsent,
    load,
    answer,
    flush,
    dispose
  }
}

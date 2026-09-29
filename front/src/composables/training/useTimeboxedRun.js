import { computed, getCurrentScope, onScopeDispose, ref } from 'vue'
import { trainingApi } from '@/services/api'

/**
 * @typedef {object} RunSummary
 * @property {number} durationMs
 * @property {number} itemCount
 * @property {number} successCount
 * @property {number} failureCount
 * @property {number|null} successRate
 * @property {number|null} itemsPerMinute
 * @property {Record<string, any>} metrics module-specific
 * @property {Record<string, any>} context why the run closed (e.g. availableAt)
 *
 * @typedef {object} TrainingRun
 * @property {string} id
 * @property {string} module
 * @property {string} subjectType
 * @property {string} subjectId
 * @property {'active'|'closed'} status
 * @property {'time_up'|'stopped'|'subject_finished'|'subject_resting'|'subject_unavailable'|null} closeReason
 * @property {number} budgetSeconds
 * @property {string} startedAt
 * @property {string} expiresAt
 * @property {string|null} closedAt
 * @property {string} serverNow the server's clock when it answered
 * @property {RunSummary|null} summary
 *
 * @typedef {object} RunItem
 * @property {string} id
 * @property {string} type e.g. woodpecker_puzzle
 * @property {Record<string, any>} data
 *
 * @typedef {object} ItemResult
 * @property {string} itemId
 * @property {boolean} success
 * @property {Record<string, any>} data
 */

/**
 * A timed run of any module (docs/TRAINING.md), seen from the client.
 *
 * The server alone decides when time is up; the client only displays it. Its clock may be off:
 * each response carries `serverNow`, and the offset is measured on the response with the shortest
 * round trip (Cristian's algorithm: the midpoint of that request is the best estimate of when the
 * server answered). There is no grace period: at zero no item is played any more. A submission
 * already on its way is still sent (the server accepts it within 2 s), then the client asks for the
 * next item, which closes the run on the server and brings the recap.
 *
 * @param {object} [options]
 * @param {typeof trainingApi} [options.api]
 * @param {() => number} [options.now] client clock, in ms
 * @param {number} [options.tickMs] display refresh
 * @param {number} [options.retryMs] delay before asking again when the server still had time
 */
export function useTimeboxedRun(options = {}) {
  const {
    api = trainingApi,
    now = () => Date.now(),
    tickMs = 250,
    retryMs = 1000
  } = options

  /** @type {import('vue').Ref<TrainingRun|null>} */
  const run = ref(null)
  /** @type {import('vue').Ref<RunItem|null>} */
  const item = ref(null)
  /** @type {import('vue').Ref<ItemResult|null>} The verdict on the last submission. */
  const result = ref(null)
  /** Items resolved and solved in this run, as seen by this page (reset by a reload). */
  const played = ref(0)
  const solved = ref(0)
  const submitting = ref(false)
  /** Client clock, refreshed every tick. */
  const clock = ref(now())
  /** Server clock minus client clock, in ms. */
  const offsetMs = ref(0)
  let bestRoundTrip = Infinity
  let expiring = false
  let lastExpiry = -Infinity
  /** @type {ReturnType<typeof setInterval>|null} */
  let timer = null

  const remainingMs = computed(() =>
    run.value
      ? Math.max(
          0,
          Date.parse(run.value.expiresAt) - (clock.value + offsetMs.value)
        )
      : 0
  )

  /**
   * idle: no run; running: time left; timeUp: zero on this clock, the server has not closed the
   * run yet; ended: closed (recap in `run.summary`).
   */
  const phase = computed(() => {
    if (!run.value) return 'idle'
    if (run.value.status === 'closed') return 'ended'
    return remainingMs.value > 0 ? 'running' : 'timeUp'
  })

  const summary = computed(() => run.value?.summary ?? null)

  /**
   * Calls the API and measures the clock offset on the way.
   *
   * @template T
   * @param {() => Promise<T>} request
   * @returns {Promise<T>}
   */
  async function call(request) {
    const sent = now()
    const data = await request()
    const received = now()
    const server =
      /** @type {any} */ (data)?.serverNow ??
      /** @type {any} */ (data)?.run?.serverNow
    if (server) sync(Date.parse(server), sent, received)
    return data
  }

  /**
   * @param {number} server when the server answered (its clock)
   * @param {number} sent
   * @param {number} received
   */
  function sync(server, sent, received) {
    const roundTrip = received - sent
    if (roundTrip <= bestRoundTrip) {
      bestRoundTrip = roundTrip
      offsetMs.value = server - (sent + received) / 2
    }
    clock.value = received
  }

  /** @param {TrainingRun} value */
  function applyRun(value) {
    run.value = value
    if (value.status === 'closed') {
      item.value = null
      stopTicking()
    } else {
      startTicking()
    }
  }

  /**
   * @param {{module: string, subjectId: string, budgetSeconds: number}} payload
   * @returns {Promise<TrainingRun>}
   */
  async function start(payload) {
    reset()
    applyRun(await call(() => api.start(payload)))
    await next()
    return /** @type {TrainingRun} */ (run.value)
  }

  /**
   * Back to a run after a reload or a disconnection: the item in progress comes back as it was
   * (timer running on the server).
   *
   * @param {string} id
   */
  async function resume(id) {
    reset()
    applyRun(await call(() => api.get(id)))
    if (phase.value === 'running') await next()
  }

  /** @returns {Promise<RunItem|null>} */
  async function next() {
    if (!run.value || run.value.status === 'closed') return null
    result.value = null
    const step = await call(() =>
      api.next(/** @type {TrainingRun} */ (run.value).id)
    )
    applyRun(step.run)
    item.value = step.item
    return item.value
  }

  /**
   * Sends what was tried on the current item; the server computes the verdict.
   *
   * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
   * @returns {Promise<ItemResult|null>} null when the server refused it (time up, run over, item closed)
   */
  async function submit(report) {
    const current = run.value
    if (!current || !item.value || submitting.value) return null
    submitting.value = true
    try {
      const step = await call(() =>
        api.submit(current.id, {
          itemId: /** @type {RunItem} */ (item.value).id,
          ...report
        })
      )
      result.value = step.result
      if (step.result) {
        played.value++
        if (step.result.success) solved.value++
      }
      applyRun(step.run)
      return step.result
    } catch (e) {
      const status = /** @type {any} */ (e)?.response?.status
      if (status !== 409 && status !== 404) throw e
      // Time was up, the run closed meanwhile, or the item's round ended: ask where we stand.
      await refresh()
      return null
    } finally {
      submitting.value = false
      if (phase.value === 'timeUp') expire()
    }
  }

  /** Ends the run now. */
  async function stop() {
    if (!run.value) return
    applyRun(
      await call(() => api.stop(/** @type {TrainingRun} */ (run.value).id))
    )
    item.value = null
  }

  async function refresh() {
    if (!run.value) return
    applyRun(
      await call(() => api.get(/** @type {TrainingRun} */ (run.value).id))
    )
    if (run.value.status === 'active') await next()
  }

  /**
   * Zero on the synced clock: the next request lets the server close the run (it serves nothing
   * past the expiry). If it still had a few ms, it serves an item; ask again a moment later.
   */
  async function expire() {
    if (expiring || submitting.value || phase.value !== 'timeUp') return
    if (clock.value - lastExpiry < retryMs) return
    expiring = true
    lastExpiry = clock.value
    try {
      const step = await call(() =>
        api.next(/** @type {TrainingRun} */ (run.value).id)
      )
      applyRun(step.run)
      item.value = step.item
    } finally {
      expiring = false
    }
  }

  function tick() {
    clock.value = now()
    if (phase.value === 'timeUp') expire().catch(() => {})
  }

  function startTicking() {
    if (!timer) timer = setInterval(tick, tickMs)
  }

  function stopTicking() {
    if (timer) clearInterval(timer)
    timer = null
  }

  function reset() {
    run.value = null
    item.value = null
    result.value = null
    played.value = 0
    solved.value = 0
  }

  if (getCurrentScope()) onScopeDispose(stopTicking)

  return {
    run,
    item,
    result,
    played,
    solved,
    submitting,
    phase,
    remainingMs,
    offsetMs,
    summary,
    start,
    resume,
    next,
    submit,
    stop,
    dispose: stopTicking
  }
}

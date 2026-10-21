import { computed, onScopeDispose, ref } from 'vue'
import {
  EngineAbortError,
  EngineFailureError,
  UciEngine
} from '@/utils/engine/UciEngine'

/**
 * Stockfish in the browser (docs/ENGINE.md), shared by every component that needs it: loaded at
 * the first search asked, stopped once the last of them is gone.
 *
 * @typedef {import('@/utils/engine/UciEngine').Analysis} Analysis
 * @typedef {import('@/utils/engine/UciEngine').AnalyseOptions} AnalyseOptions
 * @typedef {import('@/utils/engine/UciEngine').WorkerLike} WorkerLike
 * @typedef {'idle'|'loading'|'ready'|'thinking'|'failed'} EngineStatus
 */

/** Stockfish 19 lite, single-threaded (no cross-origin isolation needed), in `public/engine/`. */
export const STOCKFISH_URL = `${import.meta.env.BASE_URL ?? '/'}engine/stockfish-19-lite-single.js`

/** @type {() => WorkerLike} */
const defaultFactory = () => new Worker(STOCKFISH_URL)

let factory = defaultFactory
/** @type {UciEngine|null} */
let engine = null
let users = 0
/** Searches running or waiting, all users together. */
const pending = ref(0)
/** @type {import('vue').Ref<EngineStatus>} */
const shared = ref('idle')

/**
 * Replaces the worker the engine runs in (unit tests; another UCI engine one day). Stops the
 * engine running, if any.
 *
 * @param {(() => WorkerLike)|null} next null restores Stockfish
 */
export function setWorkerFactory(next) {
  shutdown()
  factory = next ?? defaultFactory
}

/** Stops the engine, if running: the next search starts it again. */
function shutdown() {
  engine?.terminate()
  engine = null
  pending.value = 0
  shared.value = 'idle'
}

/** The engine, started if it was not (or if it failed: a new try). */
function start() {
  if (engine && !engine.failed) return engine
  engine?.terminate()
  shared.value = 'loading'
  const started = new UciEngine(factory())
  engine = started
  started.ready().then(
    () => {
      if (engine === started) shared.value = 'ready'
    },
    () => {
      if (engine === started) shared.value = 'failed'
    }
  )
  return started
}

/**
 * The engine for one component: its searches are given up when the component goes (its effect
 * scope is disposed), the engine itself when no component uses it any more.
 *
 * - `analyse(fen, {multiPv, movetime, depth, moves, searchMoves, options, signal})`: the best
 *   lines, each scored from the side to move in the position analysed;
 * - `bestMove(fen, options)`: the move the engine would play, null without one (mate, stalemate);
 * - `stop()`: gives up this component's searches (rejected with an `AbortError`).
 */
export function useEngine() {
  users++
  /** @type {Set<AbortController>} */
  const controllers = new Set()

  /**
   * @param {string} fen
   * @param {AnalyseOptions} [options]
   * @returns {Promise<Analysis>}
   */
  async function analyse(fen, options = {}) {
    const controller = new AbortController()
    const outer = options.signal
    if (outer?.aborted) throw new EngineAbortError()
    const forward = () => controller.abort()
    outer?.addEventListener('abort', forward, { once: true })
    controllers.add(controller)
    pending.value++
    try {
      return await start().analyse(fen, {
        ...options,
        signal: controller.signal
      })
    } finally {
      pending.value = Math.max(0, pending.value - 1)
      controllers.delete(controller)
      outer?.removeEventListener('abort', forward)
    }
  }

  /**
   * @param {string} fen
   * @param {AnalyseOptions} [options]
   * @returns {Promise<string|null>}
   */
  async function bestMove(fen, options = {}) {
    return (await analyse(fen, { ...options, multiPv: 1 })).bestMove
  }

  function stop() {
    for (const controller of controllers) controller.abort()
  }

  onScopeDispose(() => {
    stop()
    users--
    if (users === 0) shutdown()
  })

  /** @type {import('vue').ComputedRef<EngineStatus>} */
  const status = computed(() =>
    shared.value === 'ready' && pending.value > 0 ? 'thinking' : shared.value
  )

  return { status, analyse, bestMove, stop }
}

/**
 * Whether an error is only a search given up (by `stop()`, a signal, the component leaving): no
 * message for the user.
 *
 * @param {unknown} error
 */
export function isEngineAbort(error) {
  return error instanceof EngineAbortError
}

export { EngineAbortError, EngineFailureError }

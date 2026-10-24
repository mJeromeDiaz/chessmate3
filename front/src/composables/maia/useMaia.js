import { computed, onScopeDispose, ref } from 'vue'
import { EngineAbortError } from '@/utils/engine/UciEngine'
import { drawMove } from '@/utils/maia/encoding'
import { MaiaEngine } from '@/utils/maia/MaiaEngine'

/**
 * Maia-3 in the browser (docs/ENGINE.md, § Maia), shared by every component that needs it, as
 * `useEngine` shares Stockfish: loaded by `preload()` or the first prediction, stopped once the
 * last of them is gone.
 *
 * @typedef {import('@/utils/maia/MaiaEngine').Prediction} Prediction
 * @typedef {import('@/utils/maia/MaiaEngine').PredictOptions} PredictOptions
 * @typedef {import('@/utils/maia/MaiaEngine').MaiaWorkerLike} MaiaWorkerLike
 * @typedef {import('@/composables/engine/useEngine').EngineStatus} MaiaStatus
 */

/** Maia-3 23M in float16 (46 MB), in `public/maia/` (`npm run maia:fetch`). */
export const MAIA_URL = `${import.meta.env.BASE_URL ?? '/'}maia/maia3-23m.fp16.onnx`

/** @type {() => MaiaWorkerLike} */
const defaultFactory = () =>
  new Worker(new URL('../../utils/maia/maia.worker.js', import.meta.url), {
    type: 'module'
  })

let factory = defaultFactory
/** @type {MaiaEngine|null} */
let engine = null
let users = 0
/** Predictions running or waiting, all users together. */
const pending = ref(0)
/** @type {import('vue').Ref<MaiaStatus>} */
const shared = ref('idle')
/** The share of the model downloaded, 0 to 1 (null while its size is unknown). */
const progress = ref(/** @type {number|null} */ (0))

/**
 * Replaces the worker Maia runs in (unit tests). Stops Maia running, if any.
 *
 * @param {(() => MaiaWorkerLike)|null} next null restores the real worker
 */
export function setMaiaWorkerFactory(next) {
  shutdown()
  factory = next ?? defaultFactory
}

function shutdown() {
  engine?.terminate()
  engine = null
  pending.value = 0
  shared.value = 'idle'
  progress.value = 0
}

/** Maia, started if it was not (or if it failed: a new try). */
function start() {
  if (engine && !engine.failed) return engine
  engine?.terminate()
  shared.value = 'loading'
  progress.value = 0
  const started = new MaiaEngine(factory(), {
    url: MAIA_URL,
    onProgress: ({ loaded, total }) => {
      if (engine === started)
        progress.value = total > 0 ? Math.min(1, loaded / total) : null
    }
  })
  engine = started
  started.ready().then(
    () => {
      if (engine !== started) return
      progress.value = 1
      shared.value = 'ready'
    },
    () => {
      if (engine === started) shared.value = 'failed'
    }
  )
  return started
}

/**
 * Maia for one component: its predictions are given up when the component goes, Maia itself when
 * no component uses it any more.
 *
 * - `preload()`: starts the download (the endgames module opening, before the player's first
 *   move); resolves once loaded, rejects with `EngineFailureError` if it cannot be;
 * - `predict(fen, {elo, opponentElo, signal})`: the legal moves with their probabilities;
 * - `chooseMove(fen, {elo, opponentElo, among, signal})`: a move drawn as Maia plays it
 *   (`drawMove`), null without one;
 * - `stop()`: gives up this component's predictions (rejected with an `AbortError`).
 */
export function useMaia() {
  users++
  /** @type {Set<AbortController>} */
  const controllers = new Set()

  function preload() {
    return start().ready()
  }

  /**
   * @param {string} fen
   * @param {PredictOptions} options
   * @returns {Promise<Prediction>}
   */
  async function predict(fen, options) {
    const controller = new AbortController()
    const outer = options.signal
    if (outer?.aborted) throw new EngineAbortError()
    const forward = () => controller.abort()
    outer?.addEventListener('abort', forward, { once: true })
    controllers.add(controller)
    pending.value++
    try {
      return await start().predict(fen, {
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
   * @param {PredictOptions & {among?: string[]}} options `among`: only these moves (the best
   *   defence's ties at « Parfait »)
   * @returns {Promise<string|null>}
   */
  async function chooseMove(fen, { among, ...options }) {
    return drawMove((await predict(fen, options)).chances, { among })
  }

  function stop() {
    for (const controller of controllers) controller.abort()
  }

  onScopeDispose(() => {
    stop()
    users--
    if (users === 0) shutdown()
  })

  /** @type {import('vue').ComputedRef<MaiaStatus>} */
  const status = computed(() =>
    shared.value === 'ready' && pending.value > 0 ? 'thinking' : shared.value
  )

  return {
    status,
    progress: computed(() => progress.value),
    preload,
    predict,
    chooseMove,
    stop
  }
}

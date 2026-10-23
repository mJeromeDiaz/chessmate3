import { EngineAbortError, EngineFailureError } from '@/utils/engine/UciEngine'
import { encodeBoard, legalChances, outlook } from '@/utils/maia/encoding'

/**
 * Maia-3 in a Web Worker (docs/ENGINE.md, § Maia; the worker: `maia.worker.js`): loads the model
 * once, then gives the legal moves of a position with the probability a human of that Elo plays
 * each. Same errors as the UCI engine: `EngineAbortError` for a prediction given up,
 * `EngineFailureError` when the model does not load or the worker breaks (it will not answer
 * again).
 *
 * @typedef {import('@/utils/maia/encoding').MoveChance} MoveChance
 * @typedef {import('@/utils/maia/encoding').Outlook} Outlook
 * @typedef {{chances: MoveChance[], outlook: Outlook}} Prediction
 * @typedef {{elo: number, opponentElo?: number, signal?: AbortSignal}} PredictOptions
 * @typedef {{postMessage: (message: unknown, transfer?: Transferable[]) => void, terminate: () => void, onmessage: ((event: {data: any}) => void)|null, onerror: ((event: unknown) => void)|null}} MaiaWorkerLike
 * @typedef {(progress: {loaded: number, total: number}) => void} ProgressListener
 */

/** A prediction takes a few hundred milliseconds; this long means the worker is stuck. */
export const RUN_TIMEOUT_MS = 20_000

/** Maia was trained on these ratings; the model clips its inputs to them too. */
export const MIN_ELO = 600
export const MAX_ELO = 2600

/** @param {number} elo */
const clampElo = elo => Math.min(MAX_ELO, Math.max(MIN_ELO, Math.round(elo)))

export class MaiaEngine {
  /** @type {MaiaWorkerLike} */
  #worker
  /** @type {Promise<void>} */
  #ready
  /** @type {(error: Error) => void} */
  #rejectReady = () => {}
  /** @type {Map<number, {resolve: (data: any) => void, reject: (error: Error) => void}>} */
  #runs = new Map()
  #nextId = 1
  #failed = false
  #terminated = false

  /**
   * @param {MaiaWorkerLike} worker
   * @param {{url: string, onProgress?: ProgressListener, runTimeoutMs?: number}} settings
   */
  constructor(worker, { url, onProgress, runTimeoutMs = RUN_TIMEOUT_MS }) {
    this.#worker = worker
    this.runTimeoutMs = runTimeoutMs
    this.#ready = new Promise((resolve, reject) => {
      this.#rejectReady = reject
      worker.onmessage = ({ data }) => {
        if (data?.type === 'progress') onProgress?.({ loaded: data.loaded, total: data.total })
        else if (data?.type === 'ready') resolve()
        else if (data?.type === 'failed') {
          this.#fail()
          reject(new EngineFailureError(`Maia could not load: ${data.message}`))
        } else if (data?.type === 'result' || data?.type === 'error') {
          const run = this.#runs.get(data.id)
          this.#runs.delete(data.id)
          if (data.type === 'result') run?.resolve(data)
          else run?.reject(new EngineFailureError(`Maia failed: ${data.message}`))
        }
      }
      worker.onerror = () => {
        this.#fail()
        reject(new EngineFailureError('Maia could not load.'))
      }
    })
    // Read by the first prediction; a failure seen there, never unhandled before.
    this.#ready.catch(() => {})
    worker.postMessage({ type: 'load', url })
  }

  /** Whether the model failed to load or the worker broke (it will not answer again). */
  get failed() {
    return this.#failed
  }

  /** Resolves once the model is loaded, rejects with `EngineFailureError` if it cannot be. */
  ready() {
    return this.#ready
  }

  /**
   * The legal moves of a position with Maia's probabilities (the likeliest first), and its
   * outlook for the side to move. `elo` is the side to move's rating, `opponentElo` the other's
   * (the same by default), both kept within 600–2600.
   *
   * @param {string} fen
   * @param {PredictOptions} options
   * @returns {Promise<Prediction>}
   */
  async predict(fen, { elo, opponentElo = elo, signal }) {
    if (signal?.aborted || this.#terminated) throw new EngineAbortError()
    await this.#ready
    // Given up or terminated while the model was loading: an aborted signal fires no more events.
    if (signal?.aborted || this.#terminated) throw new EngineAbortError()
    const tokens = encodeBoard(fen)
    const id = this.#nextId++
    const data = await new Promise((resolve, reject) => {
      const done = () => {
        clearTimeout(timer)
        signal?.removeEventListener('abort', abort)
      }
      const abort = () => {
        // The worker cannot stop a prediction: its answer is dropped when it comes.
        this.#runs.delete(id)
        done()
        reject(new EngineAbortError())
      }
      const timer = setTimeout(() => {
        this.#runs.delete(id)
        done()
        this.#fail()
        reject(new EngineFailureError('Maia stopped answering.'))
      }, this.runTimeoutMs)
      signal?.addEventListener('abort', abort, { once: true })
      this.#runs.set(id, {
        resolve: value => {
          done()
          resolve(value)
        },
        reject: error => {
          done()
          reject(error)
        }
      })
      this.#worker.postMessage(
        { type: 'run', id, tokens, eloSelf: clampElo(elo), eloOppo: clampElo(opponentElo) },
        [tokens.buffer]
      )
    })
    return { chances: legalChances(fen, data.move), outlook: outlook(data.value) }
  }

  /** Stops the worker: the predictions waiting are rejected with an `EngineAbortError`. */
  terminate() {
    if (this.#terminated) return
    this.#terminated = true
    this.#worker.terminate()
    this.#rejectReady(new EngineAbortError())
    for (const run of this.#runs.values()) run.reject(new EngineAbortError())
    this.#runs.clear()
  }

  #fail() {
    this.#failed = true
    for (const run of this.#runs.values()) {
      run.reject(new EngineFailureError('Maia stopped answering.'))
    }
    this.#runs.clear()
  }
}

import {
  goCommand,
  linesOf,
  optionCommand,
  parseBestMove,
  parseInfo,
  positionCommand
} from '@/utils/engine/uci'

/**
 * A UCI engine running in a Web Worker (docs/ENGINE.md): one search at a time, the others wait in
 * line. Any engine speaking UCI over `postMessage` fits (Stockfish today); an engine that does not
 * (Maia in ONNX) would offer the same `analyse` and `terminate`.
 *
 * @typedef {import('@/utils/engine/uci').EngineLine} EngineLine
 * @typedef {import('@/utils/engine/uci').GoOptions} GoOptions
 * @typedef {{lines: EngineLine[], bestMove: string|null}} Analysis
 * @typedef {GoOptions & {moves?: string[], multiPv?: number, options?: Record<string, string|number|boolean>, signal?: AbortSignal}} AnalyseOptions
 * @typedef {{postMessage: (message: string) => void, terminate: () => void, onmessage: ((event: {data: unknown}) => void)|null, onerror: ((event: unknown) => void)|null}} WorkerLike
 */

/** The engine has this long to load and answer `uciok` (a slow phone on a slow network). */
export const START_TIMEOUT_MS = 30_000

/**
 * Options every search starts from: a search that sets none plays at full strength with one line,
 * whatever the one before asked for (the engine is shared).
 */
export const DEFAULT_OPTIONS = Object.freeze({
  MultiPV: 1,
  UCI_LimitStrength: false
})

/** Raised by a search given up (its signal aborted, the engine terminated). */
export class EngineAbortError extends Error {
  constructor() {
    super('The engine search was given up.')
    this.name = 'AbortError'
  }
}

/** Raised when the engine does not start or stops answering. */
export class EngineFailureError extends Error {
  /** @param {string} message */
  constructor(message) {
    super(message)
    this.name = 'EngineFailureError'
  }
}

/** Told to the listener waiting when the engine is terminated or crashes (never a UCI line). */
const TERMINATED = '\u0000terminated'
const FAILED = '\u0000failed'

/** @param {string} signal TERMINATED or FAILED */
function interruption(signal) {
  return signal === TERMINATED
    ? new EngineAbortError()
    : new EngineFailureError('The engine stopped answering.')
}

export class UciEngine {
  /** @type {WorkerLike} */
  #worker
  /** @type {Promise<void>} */
  #ready
  /** @type {Promise<unknown>} the searches in line, each after the one before */
  #queue = Promise.resolve()
  /** @type {((line: string) => void)|null} */
  #listener = null
  /** @type {Record<string, string|number|boolean>} the options as the engine has them */
  #options = {}
  #failed = false
  #terminated = false

  /**
   * @param {WorkerLike} worker
   * @param {{startTimeoutMs?: number}} [settings]
   */
  constructor(worker, { startTimeoutMs = START_TIMEOUT_MS } = {}) {
    this.#worker = worker
    worker.onmessage = event => {
      if (typeof event.data === 'string') this.#listener?.(event.data)
    }
    this.#ready = new Promise((resolve, reject) => {
      /** @param {Error} error */
      const fail = error => {
        clearTimeout(timer)
        this.#failed = true
        reject(error)
      }
      const timer = setTimeout(
        () => fail(new EngineFailureError('The engine did not start.')),
        startTimeoutMs
      )
      worker.onerror = () => {
        fail(new EngineFailureError('The engine could not load.'))
        // Crashed while searching: the search waiting for it fails too.
        this.#listener?.(FAILED)
      }
      this.#listener = line => {
        if (line === TERMINATED) {
          clearTimeout(timer)
          reject(new EngineAbortError())
        }
        if (line.trim() !== 'uciok') return
        clearTimeout(timer)
        this.#listener = null
        resolve()
      }
      worker.postMessage('uci')
    })
    // Read once by the first search; a failure seen there, never unhandled before.
    this.#ready.catch(() => {})
  }

  /** Whether the engine failed to start (it will not answer again). */
  get failed() {
    return this.#failed
  }

  /** Resolves once the engine answered `uciok`, rejects if it never does. */
  ready() {
    return this.#ready
  }

  /**
   * Analyses a position (after `moves` from it, if any): the best lines, best first, each scored
   * from the side to move there. Waits for the searches asked before.
   *
   * @param {string} fen
   * @param {AnalyseOptions} [options]
   * @returns {Promise<Analysis>}
   */
  analyse(fen, options = {}) {
    const run = this.#queue.then(() => this.#search(fen, options))
    // A search that fails never stops the next one.
    this.#queue = run.catch(() => {})
    return run
  }

  /**
   * Forgets what the engine learned (its hash): before positions of another game.
   */
  newGame() {
    this.#queue = this.#queue
      .then(async () => {
        await this.#ready
        await this.#sync('ucinewgame')
      })
      .catch(() => {})
  }

  /** Stops the engine for good; a search running or waiting is given up. */
  terminate() {
    if (this.#terminated) return
    this.#terminated = true
    this.#listener?.(TERMINATED)
    this.#listener = null
    this.#worker.terminate()
  }

  /**
   * @param {string} fen
   * @param {AnalyseOptions} options
   * @returns {Promise<Analysis>}
   */
  async #search(fen, { moves, multiPv, options, signal, ...go }) {
    if (this.#terminated || signal?.aborted) throw new EngineAbortError()
    await this.#ready
    if (this.#failed)
      throw new EngineFailureError('The engine stopped answering.')
    const wanted = {
      ...DEFAULT_OPTIONS,
      ...options,
      ...(multiPv ? { MultiPV: multiPv } : {})
    }
    for (const [name, value] of Object.entries(wanted)) {
      if (this.#options[name] === value) continue
      this.#worker.postMessage(optionCommand(name, value))
      this.#options[name] = value
    }
    await this.#sync()
    if (this.#terminated || signal?.aborted) throw new EngineAbortError()

    return new Promise((resolve, reject) => {
      /** @type {Map<number, import('@/utils/engine/uci').InfoLine>} */
      const infos = new Map()
      let aborted = false
      const onAbort = () => {
        aborted = true
        // The engine still ends on a `bestmove`, read before the next search starts.
        this.#worker.postMessage('stop')
      }
      signal?.addEventListener('abort', onAbort, { once: true })
      this.#listener = line => {
        if (line === TERMINATED || line === FAILED) {
          this.#listener = null
          signal?.removeEventListener('abort', onAbort)
          reject(interruption(line))
          return
        }
        const info = parseInfo(line)
        if (info) {
          const known = infos.get(info.multipv)
          if (!known || info.depth >= known.depth) infos.set(info.multipv, info)
          return
        }
        const best = parseBestMove(line)
        if (!best) return
        this.#listener = null
        signal?.removeEventListener('abort', onAbort)
        if (aborted) reject(new EngineAbortError())
        else resolve({ lines: linesOf(infos), bestMove: best.move })
      }
      this.#worker.postMessage(positionCommand(fen, moves))
      this.#worker.postMessage(goCommand(go))
    })
  }

  /**
   * Sends `command` (if any), then waits for the engine to have read everything sent (`isready` →
   * `readyok`).
   *
   * @param {string} [command]
   * @returns {Promise<void>}
   */
  #sync(command) {
    return new Promise((resolve, reject) => {
      this.#listener = line => {
        if (line === TERMINATED || line === FAILED) {
          this.#listener = null
          reject(interruption(line))
        } else if (line.trim() === 'readyok') {
          this.#listener = null
          resolve()
        }
      }
      if (command) this.#worker.postMessage(command)
      this.#worker.postMessage('isready')
    })
  }
}

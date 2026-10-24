import { afterEach, describe, expect, it } from 'vitest'
import { effectScope } from 'vue'
import {
  MOVES,
  drawMove,
  encodeBoard,
  legalChances,
  mirrorMove,
  moveIndex,
  outlook
} from '@/utils/maia/encoding'
import { MaiaEngine } from '@/utils/maia/MaiaEngine'
import { EngineAbortError, EngineFailureError } from '@/utils/engine/UciEngine'
import { setMaiaWorkerFactory, useMaia } from '@/composables/maia/useMaia'

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'
const AFTER_E4 = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1'
/** e2e4: from e2 (12) × 64 + to e4 (28). */
const E2E4 = 796

const flush = () => new Promise(resolve => setTimeout(resolve))

/** Logits of a policy with one move far ahead of the others. */
function policy(index, logit = 5) {
  const logits = new Float32Array(MOVES.length)
  logits[index] = logit
  return logits
}

/**
 * A worker answering as maia.worker.js does: `load` → progress then ready (or failed), each `run`
 * → the logits of `best`; with `hold`, a run is never answered.
 */
class FakeMaia {
  /** @param {{fail?: boolean, hold?: boolean, best?: number}} [script] */
  constructor({ fail = false, hold = false, best = E2E4 } = {}) {
    Object.assign(this, { fail, hold, best })
    /** @type {any[]} */
    this.sent = []
    this.terminated = false
    /** @type {((event: {data: any}) => void)|null} */
    this.onmessage = null
    /** @type {((event: unknown) => void)|null} */
    this.onerror = null
  }

  /** @param {any} message */
  postMessage(message) {
    this.sent.push(message)
    setTimeout(() => this.answer(message))
  }

  /** @param {any} message */
  answer(message) {
    if (this.terminated) return
    if (message.type === 'load') {
      if (this.fail) return this.emit({ type: 'failed', message: 'no wasm' })
      this.emit({ type: 'progress', loaded: 50, total: 100 })
      this.emit({ type: 'ready' })
    } else if (message.type === 'run' && !this.hold) {
      this.emit({
        type: 'result',
        id: message.id,
        move: policy(this.best),
        value: Float32Array.of(0, 0, 3)
      })
    }
  }

  /** @param {any} data */
  emit(data) {
    this.onmessage?.({ data })
  }

  terminate() {
    this.terminated = true
  }
}

describe('Maia encoding', () => {
  it('lists the 4352 moves of the policy in the upstream order', () => {
    expect(MOVES).toHaveLength(4352)
    expect(MOVES[0]).toBe('a1a1')
    expect(MOVES[E2E4]).toBe('e2e4')
    expect(MOVES[4095]).toBe('h8h8')
    expect(MOVES[4096]).toBe('a7a8q')
    // h → g (7 × 8 + 6), knight (the 4th piece).
    expect(MOVES[4096 + (7 * 8 + 6) * 4 + 3]).toBe('h7g8n')
  })

  it('finds a move, mirrored when Black is to move', () => {
    expect(moveIndex('e2e4', false)).toBe(E2E4)
    expect(moveIndex('e7e5', true)).toBe(E2E4)
    expect(moveIndex('a2a1q', true)).toBe(4096)
    expect(mirrorMove('b2b1r')).toBe('b7b8r')
    expect(mirrorMove('g8f6')).toBe('g1f3')
  })

  it('encodes the board seen by White when White is to move', () => {
    const tokens = encodeBoard(START)
    expect(tokens).toHaveLength(64 * 12)
    expect(tokens.reduce((sum, value) => sum + value, 0)).toBe(32)
    expect(tokens[8 * 12 + 0]).toBe(1) // white pawn a2
    expect(tokens[4 * 12 + 5]).toBe(1) // white king e1
    expect(tokens[60 * 12 + 11]).toBe(1) // black king e8, the opponent's
  })

  it('mirrors the board, colours swapped, when Black is to move', () => {
    const tokens = encodeBoard(AFTER_E4)
    expect(tokens.reduce((sum, value) => sum + value, 0)).toBe(32)
    expect(tokens[4 * 12 + 5]).toBe(1) // the black king e8, seen on e1 as the side to move
    expect(tokens[36 * 12 + 6]).toBe(1) // the white pawn e4, seen on e5 as the opponent's
    expect(tokens[28 * 12 + 0]).toBe(0)
  })

  it('keeps the legal moves only, their probabilities summing to 1', () => {
    const chances = legalChances(START, policy(E2E4))
    expect(chances).toHaveLength(20)
    expect(chances[0].uci).toBe('e2e4')
    expect(chances.reduce((sum, move) => sum + move.p, 0)).toBeCloseTo(1, 6)
    // A move never legal here (a1a8) is ignored, however high its logit.
    expect(legalChances(START, policy(0 * 64 + 56, 50))[0].p).toBeCloseTo(
      1 / 20,
      6
    )
  })

  it('reads the moves of Black in the mirrored policy', () => {
    const chances = legalChances(AFTER_E4, policy(moveIndex('c7c5', true)))
    expect(chances[0].uci).toBe('c7c5')
    expect(
      legalChances('8/8/8/8/8/8/p7/2K4k b - - 0 1', policy(4096))[0].uci
    ).toBe('a2a1q')
  })

  it('gives no move when there is none', () => {
    expect(legalChances('7k/5QQ1/8/8/8/8/8/K7 b - - 0 1', policy(0))).toEqual(
      []
    )
  })

  it('reads the outlook for the side to move', () => {
    const value = outlook([0, 0, Math.log(2)])
    expect(value.win).toBeCloseTo(0.5, 6)
    expect(value.draw).toBeCloseTo(0.25, 6)
    expect(value.loss).toBeCloseTo(0.25, 6)
  })
})

describe('drawMove', () => {
  const chances = [
    { uci: 'a', p: 0.6 },
    { uci: 'b', p: 0.37 },
    { uci: 'c', p: 0.03 }
  ]

  it('draws by probability among the moves of at least 5 %', () => {
    expect(drawMove(chances, { random: () => 0 })).toBe('a')
    expect(drawMove(chances, { random: () => 0.7 })).toBe('b')
    // c (3 %) is never drawn, even at the very end of the range.
    expect(drawMove(chances, { random: () => 0.9999 })).toBe('b')
  })

  it('takes the probabilities again among the moves allowed', () => {
    // b 0.925, c 0.075 among themselves: c is drawn now.
    expect(drawMove(chances, { among: ['b', 'c'], random: () => 0.99 })).toBe(
      'c'
    )
    expect(drawMove(chances, { among: ['b', 'c'], random: () => 0 })).toBe('b')
  })

  it('plays the likeliest when every move is below the threshold', () => {
    const flat = [
      { uci: 'x', p: 0.04 },
      { uci: 'y', p: 0.03 }
    ]
    expect(drawMove(flat, { random: () => 0.99 })).toBe('x')
  })

  it('gives null without a move to draw', () => {
    expect(drawMove([])).toBeNull()
    expect(drawMove(chances, { among: ['z'] })).toBeNull()
  })
})

describe('MaiaEngine', () => {
  it('loads the model, telling the progress, then predicts', async () => {
    const worker = new FakeMaia()
    /** @type {{loaded: number, total: number}[]} */
    const seen = []
    const engine = new MaiaEngine(worker, {
      url: '/maia/m.onnx',
      onProgress: p => seen.push(p)
    })
    const prediction = await engine.predict(START, { elo: 3000 })
    expect(worker.sent[0]).toEqual({ type: 'load', url: '/maia/m.onnx' })
    expect(seen).toEqual([{ loaded: 50, total: 100 }])
    expect(prediction.chances[0].uci).toBe('e2e4')
    expect(prediction.outlook.win).toBeGreaterThan(0.9)
    // Ratings kept within what Maia knows, the opponent's the same by default.
    expect(worker.sent[1]).toMatchObject({
      type: 'run',
      eloSelf: 2600,
      eloOppo: 2600
    })
    expect(worker.sent[1].tokens).toHaveLength(768)
  })

  it('fails when the model does not load', async () => {
    const engine = new MaiaEngine(new FakeMaia({ fail: true }), { url: '/m' })
    await expect(engine.ready()).rejects.toBeInstanceOf(EngineFailureError)
    await expect(engine.predict(START, { elo: 1500 })).rejects.toBeInstanceOf(
      EngineFailureError
    )
    expect(engine.failed).toBe(true)
  })

  it('gives a prediction up on its signal', async () => {
    const engine = new MaiaEngine(new FakeMaia({ hold: true }), { url: '/m' })
    await engine.ready()
    const controller = new AbortController()
    const running = engine.predict(START, {
      elo: 1500,
      signal: controller.signal
    })
    await flush()
    controller.abort()
    await expect(running).rejects.toBeInstanceOf(EngineAbortError)
    expect(engine.failed).toBe(false)
  })

  it('fails when the worker stops answering', async () => {
    const engine = new MaiaEngine(new FakeMaia({ hold: true }), {
      url: '/m',
      runTimeoutMs: 5
    })
    await expect(engine.predict(START, { elo: 1500 })).rejects.toBeInstanceOf(
      EngineFailureError
    )
    expect(engine.failed).toBe(true)
  })

  it('rejects what waits when terminated, even before loading', async () => {
    const worker = new FakeMaia()
    const engine = new MaiaEngine(worker, { url: '/m' })
    const running = engine.predict(START, { elo: 1500 })
    engine.terminate()
    await expect(running).rejects.toBeInstanceOf(EngineAbortError)
    expect(worker.terminated).toBe(true)
  })
})

describe('useMaia', () => {
  /** @type {FakeMaia[]} */
  let workers = []

  /** @param {ConstructorParameters<typeof FakeMaia>[0]} [script] */
  function fake(script) {
    workers = []
    setMaiaWorkerFactory(() => {
      const worker = new FakeMaia(script)
      workers.push(worker)
      return worker
    })
  }

  afterEach(() => setMaiaWorkerFactory(null))

  it('preloads, then chooses a move as Maia plays it', async () => {
    fake()
    const scope = effectScope()
    const maia = scope.run(() => useMaia())
    expect(maia.status.value).toBe('idle')
    const loading = maia.preload()
    expect(maia.status.value).toBe('loading')
    await loading
    expect(maia.status.value).toBe('ready')
    expect(maia.progress.value).toBe(1)
    const choosing = maia.chooseMove(START, { elo: 1500 })
    expect(maia.status.value).toBe('thinking')
    // e2e4 is far ahead, every other move below 5 %: always e2e4.
    expect(await choosing).toBe('e2e4')
    expect(
      await maia.chooseMove(START, { elo: 1500, among: ['d2d4', 'g1f3'] })
    ).toMatch(/^(d2d4|g1f3)$/)
    expect(workers).toHaveLength(1)
    scope.stop()
    expect(workers[0].terminated).toBe(true)
  })

  it('reports a failure, then tries a new worker', async () => {
    fake({ fail: true })
    const scope = effectScope()
    const maia = scope.run(() => useMaia())
    await expect(maia.preload()).rejects.toBeInstanceOf(EngineFailureError)
    expect(maia.status.value).toBe('failed')
    await expect(maia.preload()).rejects.toBeInstanceOf(EngineFailureError)
    expect(workers).toHaveLength(2)
    scope.stop()
  })

  it('gives up the predictions of a component that goes', async () => {
    fake({ hold: true })
    const scope = effectScope()
    const maia = scope.run(() => useMaia())
    await maia.preload()
    const running = maia.chooseMove(START, { elo: 1500 })
    scope.stop()
    await expect(running).rejects.toBeInstanceOf(EngineAbortError)
  })
})

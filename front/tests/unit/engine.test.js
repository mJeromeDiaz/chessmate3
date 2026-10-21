import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'
import {
  flipScore,
  goCommand,
  isNearBest,
  parseBestMove,
  parseInfo,
  positionCommand,
  scoreValue
} from '@/utils/engine/uci'
import { UciEngine } from '@/utils/engine/UciEngine'
import {
  isEngineAbort,
  setWorkerFactory,
  useEngine
} from '@/composables/engine/useEngine'

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'

/**
 * A worker speaking UCI as Stockfish does, answering each `go` with `infos` then `bestmove`; with
 * `hold`, a search ends only on `stop`.
 */
class FakeWorker {
  /** @param {{infos?: string[], best?: string, hold?: boolean, silent?: boolean}} [script] */
  constructor({
    infos = [
      'info depth 10 multipv 1 score cp 30 pv e2e4 e7e5',
      'info depth 12 multipv 1 score cp 35 pv e2e4 c7c5',
      'info depth 12 multipv 2 score cp 20 pv d2d4 d7d5',
      'info depth 12 multipv 2 score cp 25 lowerbound pv d2d4'
    ],
    best = 'e2e4',
    hold = false,
    silent = false
  } = {}) {
    Object.assign(this, { infos, best, hold, silent })
    this.sent = []
    this.terminated = false
    this.onmessage = null
    this.onerror = null
  }

  /** @param {string} line */
  emit(line) {
    queueMicrotask(() => this.onmessage?.({ data: line }))
  }

  /** @param {string} command */
  postMessage(command) {
    this.sent.push(command)
    if (this.silent) return
    if (command === 'uci') this.emit('uciok')
    else if (command === 'isready') this.emit('readyok')
    else if (command.startsWith('go')) {
      for (const info of this.infos) this.emit(info)
      if (!this.hold) this.emit(`bestmove ${this.best}`)
    } else if (command === 'stop') this.emit(`bestmove ${this.best}`)
  }

  terminate() {
    this.terminated = true
  }
}

/** Lets the worker's answers (microtasks) arrive. */
async function flush() {
  for (let i = 0; i < 20; i++) await Promise.resolve()
}

describe('the UCI lines', () => {
  it('reads an info line, its score from the side to move, and skips bounds', () => {
    expect(
      parseInfo(
        'info depth 18 seldepth 24 multipv 2 score mate -3 nodes 1000 pv g8f6 d1h5 f6h5'
      )
    ).toEqual({
      multipv: 2,
      depth: 18,
      score: { mate: -3 },
      pv: ['g8f6', 'd1h5', 'f6h5']
    })
    expect(parseInfo('info depth 5 score cp 12 upperbound pv e2e4')).toBeNull()
    expect(parseInfo('info string NNUE enabled')).toBeNull()
    expect(parseInfo('bestmove e2e4')).toBeNull()
  })

  it('reads the best move, none when mated or stalemated', () => {
    expect(parseBestMove('bestmove e7e8q ponder a2a3')).toEqual({
      move: 'e7e8q'
    })
    expect(parseBestMove('bestmove (none)')).toEqual({ move: null })
    expect(parseBestMove('info depth 1')).toBeNull()
  })

  it('writes the position and the search', () => {
    expect(positionCommand(START)).toBe(`position fen ${START}`)
    expect(positionCommand(START, ['e2e4', 'e7e5'])).toBe(
      `position fen ${START} moves e2e4 e7e5`
    )
    expect(goCommand({ movetime: 1500, searchMoves: ['e2e4', 'd2d4'] })).toBe(
      'go movetime 1500 searchmoves e2e4 d2d4'
    )
    expect(goCommand()).toBe('go depth 12')
  })

  it('orders mates before material, the shorter first, and flips sides', () => {
    expect(scoreValue({ mate: 2 })).toBeGreaterThan(scoreValue({ mate: 3 }))
    expect(scoreValue({ mate: 3 })).toBeGreaterThan(scoreValue({ cp: 900 }))
    expect(scoreValue({ mate: -5 })).toBeGreaterThan(scoreValue({ mate: -2 }))
    expect(scoreValue({ cp: -900 })).toBeGreaterThan(scoreValue({ mate: -5 }))
    expect(flipScore({ mate: 2 })).toEqual({ mate: -2 })
    expect(flipScore({ cp: 40 })).toEqual({ cp: -40 })
  })

  it('takes a move within the tolerance as one of the best, a slower mate not', () => {
    const lines = [
      { move: 'e2e4', score: { cp: 60 }, pv: ['e2e4'], depth: 12 },
      { move: 'd2d4', score: { cp: 20 }, pv: ['d2d4'], depth: 12 },
      { move: 'a2a3', score: { cp: -30 }, pv: ['a2a3'], depth: 12 }
    ]
    expect(isNearBest(lines, 'd2d4', 50)).toBe(true)
    expect(isNearBest(lines, 'a2a3', 50)).toBe(false)
    expect(isNearBest(lines, 'h2h4', 50)).toBe(false)
    const mates = [
      { move: 'd1h5', score: { mate: 2 }, pv: ['d1h5'], depth: 20 },
      { move: 'f3g5', score: { mate: 3 }, pv: ['f3g5'], depth: 20 }
    ]
    expect(isNearBest(mates, 'f3g5', 50)).toBe(false)
  })
})

describe('UciEngine', () => {
  it('starts, then analyses with the options asked, its lines best first', async () => {
    const worker = new FakeWorker()
    const engine = new UciEngine(worker)
    const analysis = await engine.analyse(START, {
      multiPv: 2,
      movetime: 500
    })
    expect(analysis.bestMove).toBe('e2e4')
    expect(analysis.lines).toEqual([
      { move: 'e2e4', score: { cp: 35 }, pv: ['e2e4', 'c7c5'], depth: 12 },
      { move: 'd2d4', score: { cp: 20 }, pv: ['d2d4', 'd7d5'], depth: 12 }
    ])
    expect(worker.sent).toEqual([
      'uci',
      'setoption name MultiPV value 2',
      'setoption name UCI_LimitStrength value false',
      'isready',
      `position fen ${START}`,
      'go movetime 500'
    ])
  })

  it('runs one search at a time and puts the defaults back for the next', async () => {
    const worker = new FakeWorker()
    const engine = new UciEngine(worker)
    const first = engine.analyse(START, {
      multiPv: 3,
      options: { UCI_LimitStrength: true, UCI_Elo: 1500 }
    })
    const second = engine.analyse(START, { moves: ['e2e4'] })
    await Promise.all([first, second])
    const go = worker.sent.findIndex(c => c.startsWith('go'))
    const next = worker.sent.slice(go + 1)
    expect(next).toEqual([
      'setoption name MultiPV value 1',
      'setoption name UCI_LimitStrength value false',
      'isready',
      `position fen ${START} moves e2e4`,
      'go depth 12'
    ])
  })

  it('gives up a search on its signal, and the next one still runs', async () => {
    const worker = new FakeWorker({ hold: true })
    const engine = new UciEngine(worker)
    const controller = new AbortController()
    const given = engine.analyse(START, { signal: controller.signal })
    await flush()
    controller.abort()
    await expect(given).rejects.toMatchObject({ name: 'AbortError' })
    expect(worker.sent).toContain('stop')
    worker.hold = false
    await expect(engine.analyse(START)).resolves.toMatchObject({
      bestMove: 'e2e4'
    })
  })

  it('fails when the engine never starts', async () => {
    vi.useFakeTimers()
    try {
      const engine = new UciEngine(new FakeWorker({ silent: true }), {
        startTimeoutMs: 1000
      })
      const search = engine.analyse(START)
      vi.advanceTimersByTime(1000)
      await expect(search).rejects.toMatchObject({
        name: 'EngineFailureError'
      })
      expect(engine.failed).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('fails the search running when the worker crashes', async () => {
    const worker = new FakeWorker({ hold: true })
    const engine = new UciEngine(worker)
    const search = engine.analyse(START)
    await flush()
    worker.onerror?.({})
    await expect(search).rejects.toMatchObject({ name: 'EngineFailureError' })
  })
})

describe('useEngine', () => {
  /** @type {FakeWorker[]} */
  let workers = []
  afterEach(() => {
    setWorkerFactory(null)
    workers = []
  })

  function install(script) {
    setWorkerFactory(() => {
      const worker = new FakeWorker(script)
      workers.push(worker)
      return worker
    })
  }

  it('starts one engine at the first search, shared, and stops it with its last user', async () => {
    install()
    const a = effectScope()
    const b = effectScope()
    const first = a.run(() => useEngine())
    const second = b.run(() => useEngine())
    expect(first.status.value).toBe('idle')
    expect(workers).toHaveLength(0)

    await expect(first.bestMove(START)).resolves.toBe('e2e4')
    await second.analyse(START)
    expect(workers).toHaveLength(1)
    expect(second.status.value).toBe('ready')

    a.stop()
    expect(workers[0].terminated).toBe(false)
    b.stop()
    expect(workers[0].terminated).toBe(true)
  })

  it('shows its thinking, and gives up its searches when its component goes', async () => {
    install({ hold: true })
    const scope = effectScope()
    const engine = scope.run(() => useEngine())
    const search = engine.analyse(START)
    await flush()
    await nextTick()
    expect(engine.status.value).toBe('thinking')

    scope.stop()
    const error = await search.catch(e => e)
    expect(isEngineAbort(error)).toBe(true)
  })
})

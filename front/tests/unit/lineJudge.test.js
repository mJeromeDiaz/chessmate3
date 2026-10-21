import { afterEach, describe, expect, it } from 'vitest'
import { effectScope } from 'vue'
import { setWorkerFactory } from '@/composables/engine/useEngine'
import { useLineJudge } from '@/composables/calculation/useLineJudge'
import { after } from '@/utils/calculation'

// A sample mate in 2 (GlnnF), once c5c6 is played: black mates by Rxh4+ gxh4 Qg2#.
const START = after('7r/p3kp2/1p3p2/2Pqp1r1/7P/P3Q1P1/5P1K/2R1R3 w - - 0 36', [
  'c5c6'
])

/** A UCI worker scoring each position as told (centipawns from the side to move, 0 otherwise). */
class ScoredWorker {
  /** @param {Map<string, string>} scores FEN → `cp 40`, `mate 2`… */
  constructor(scores) {
    this.scores = scores
    this.fen = ''
    this.searches = []
    this.onmessage = null
    this.onerror = null
  }

  /** @param {string} line */
  emit(line) {
    queueMicrotask(() => this.onmessage?.({ data: line }))
  }

  /** @param {string} command */
  postMessage(command) {
    if (command === 'uci') this.emit('uciok')
    else if (command === 'isready') this.emit('readyok')
    else if (command.startsWith('position fen ')) this.fen = command.slice(13)
    else if (command.startsWith('go')) {
      this.searches.push(this.fen)
      this.emit(
        `info depth 12 multipv 1 score ${this.scores.get(this.fen) ?? 'cp 0'} pv a1a2`
      )
      this.emit('bestmove a1a2')
    }
  }

  terminate() {}
}

describe('useLineJudge', () => {
  afterEach(() => setWorkerFactory(null))

  it('analyses each position once, as it comes, and judges the line', async () => {
    const line = ['h8h4', 'g3h4', 'd5g2']
    const worker = new ScoredWorker(
      new Map([
        [START, 'mate 2'],
        [after(START, line.slice(0, 1)), 'mate -1'],
        [after(START, line.slice(0, 2)), 'mate 1']
      ])
    )
    setWorkerFactory(() => worker)
    const scope = effectScope()
    const judge = scope.run(() => useLineJudge())
    judge.follow(START, [])
    judge.follow(START, line.slice(0, 1))
    await expect(judge.verdict(START, line, 50)).resolves.toEqual({
      right: true,
      mistakeAt: null
    })
    // The mate at the end needs no search; nothing analysed twice.
    expect(worker.searches).toHaveLength(3)
    scope.stop()
  })

  it('finds where a defence imagined is too weak', async () => {
    const line = ['h8h4', 'h2g1', 'd5d4']
    const worker = new ScoredWorker(
      new Map([
        [START, 'cp 900'],
        [after(START, line.slice(0, 1)), 'cp -880'],
        // Kg1 hands black far more than the best defence did.
        [after(START, line.slice(0, 2)), 'cp 1500'],
        [after(START, line), 'cp -1490']
      ])
    )
    setWorkerFactory(() => worker)
    const scope = effectScope()
    const judge = scope.run(() => useLineJudge())
    await expect(judge.verdict(START, line, 50)).resolves.toEqual({
      right: false,
      mistakeAt: 1
    })
    // The Avocat du diable: one half-move at a time.
    await expect(judge.holdsAt(START, line, 0, 50)).resolves.toBe(true)
    await expect(judge.holdsAt(START, line, 1, 50)).resolves.toBe(false)
    await expect(judge.holdsAt(START, line, 3, 50)).resolves.toBeNull()
    scope.stop()
  })

  it('gives no verdict when the engine fails', async () => {
    setWorkerFactory(() => {
      throw new Error('No WebAssembly.')
    })
    const scope = effectScope()
    const judge = scope.run(() => useLineJudge())
    await expect(judge.verdict(START, ['h8h4'], 50)).resolves.toBeNull()
    expect(judge.failed.value).toBe(true)
    scope.stop()
  })
})

import { existsSync, readFileSync } from 'node:fs'
import * as ort from 'onnxruntime-web/wasm'
import { beforeAll, describe, expect, it } from 'vitest'
import { encodeBoard, legalChances, outlook } from '@/utils/maia/encoding'

// The model maia.worker.js loads, run here by the same runtime (its wasm build, under Node): checks
// that it loads with these settings and that our encoding reads its answers right.
const MODEL = new URL('../../public/maia/maia3-23m.fp16.onnx', import.meta.url).pathname
const WASM = new URL(
  '../../node_modules/onnxruntime-web/dist/ort-wasm-simd-threaded.wasm',
  import.meta.url
).pathname

/** @type {ort.InferenceSession} */
let session

/**
 * @param {string} fen
 * @param {number} [elo]
 */
async function predict(fen, elo = 1500) {
  const started = performance.now()
  const output = await session.run({
    tokens: new ort.Tensor('float32', encodeBoard(fen), [1, 64, 12]),
    elo_self: new ort.Tensor('float32', Float32Array.of(elo), [1]),
    elo_oppo: new ort.Tensor('float32', Float32Array.of(elo), [1])
  })
  const ms = Math.round(performance.now() - started)
  const chances = legalChances(fen, /** @type {Float32Array} */ (output.logits_move.data))
  const value = outlook(/** @type {Float32Array} */ (output.logits_value.data))
  console.log(fen, `${ms} ms`, chances.slice(0, 3), value)
  return { chances, value, top: chances.slice(0, 3).map(move => move.uci) }
}

describe.skipIf(!existsSync(MODEL))('Maia-3 23M (npm run maia:fetch)', () => {
  beforeAll(async () => {
    ort.env.wasm.numThreads = 1
    ort.env.wasm.wasmBinary = readFileSync(WASM)
    session = await ort.InferenceSession.create(readFileSync(MODEL), {
      executionProviders: ['wasm'],
      graphOptimizationLevel: 'basic'
    })
  })

  it('has the inputs and outputs we feed and read', () => {
    expect([...session.inputNames].sort()).toEqual(['elo_oppo', 'elo_self', 'tokens'])
    expect([...session.outputNames].sort()).toEqual(['logits_move', 'logits_value'])
  })

  it('opens as a human does', async () => {
    const { top, value } = await predict(
      'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'
    )
    expect(top.some(move => ['e2e4', 'd2d4'].includes(move))).toBe(true)
    expect(value.win).toBeLessThan(0.7)
  })

  it('answers as Black (the mirrored board and moves)', async () => {
    const { top } = await predict('rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1')
    expect(top.some(move => ['e7e5', 'c7c5'].includes(move))).toBe(true)
  })

  it('sees a won endgame as won', async () => {
    const { value } = await predict('k7/8/1K6/8/8/8/8/7Q w - - 0 1')
    expect(value.win).toBeGreaterThan(0.8)
  })

  it('promotes, for White and for Black', async () => {
    expect((await predict('8/P7/8/8/8/8/8/k6K w - - 0 1')).top[0]).toBe('a7a8q')
    expect((await predict('7k/8/8/8/8/8/p7/7K b - - 0 1')).top[0]).toBe('a2a1q')
  })
})

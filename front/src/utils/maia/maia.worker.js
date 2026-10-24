// Maia-3 in a Web Worker (docs/ENGINE.md, § Maia): loads the ONNX model once, then answers each
// position with its raw logits; the encoding and the move drawn stay with the page
// (src/utils/maia/encoding.js). Messages:
// - in  {type: 'load', url}                       → out {type: 'progress', loaded, total} … then
//                                                    {type: 'ready'} or {type: 'failed', message}
// - in  {type: 'run', id, tokens, eloSelf, eloOppo} → out {type: 'result', id, move, value} or
//                                                    {type: 'error', id, message}
import * as ort from 'onnxruntime-web/wasm'
import wasmUrl from 'onnxruntime-web/ort-wasm-simd-threaded.wasm?url'

// One thread: threads need cross-origin isolation (COOP/COEP), which the app does not have.
ort.env.wasm.numThreads = 1
ort.env.wasm.wasmPaths = { wasm: wasmUrl }

/** @type {ort.InferenceSession|null} */
let session = null

/**
 * The model's bytes, the progress told as they come (total 0 when the server gives no length).
 *
 * @param {string} url
 */
async function download(url) {
  const response = await fetch(url)
  if (!response.ok || !response.body) throw new Error(`HTTP ${response.status}`)
  const total = Number(response.headers.get('Content-Length') ?? 0)
  const reader = response.body.getReader()
  const chunks = []
  let loaded = 0
  for (;;) {
    const { done, value } = await reader.read()
    if (done) break
    chunks.push(value)
    loaded += value.length
    self.postMessage({ type: 'progress', loaded, total })
  }
  const bytes = new Uint8Array(loaded)
  let offset = 0
  for (const chunk of chunks) {
    bytes.set(chunk, offset)
    offset += chunk.length
  }
  return bytes
}

/** @param {string} url */
async function load(url) {
  try {
    session = await ort.InferenceSession.create(await download(url), {
      executionProviders: ['wasm'],
      // 'all' and 'extended' hit an ORT fusion bug on this graph's fp16 casts (the export's README).
      graphOptimizationLevel: 'basic'
    })
    self.postMessage({ type: 'ready' })
  } catch (error) {
    self.postMessage({ type: 'failed', message: String(error) })
  }
}

/** @param {{id: number, tokens: Float32Array, eloSelf: number, eloOppo: number}} data */
async function run({ id, tokens, eloSelf, eloOppo }) {
  try {
    if (!session) throw new Error('Maia is not loaded.')
    const output = await session.run({
      tokens: new ort.Tensor('float32', tokens, [1, 64, 12]),
      elo_self: new ort.Tensor('float32', Float32Array.of(eloSelf), [1]),
      elo_oppo: new ort.Tensor('float32', Float32Array.of(eloOppo), [1])
    })
    // Copies of their own (a tensor's data may be a view on a larger buffer), transferred.
    const move = Float32Array.from(
      /** @type {Float32Array} */ (output.logits_move.data)
    )
    const value = Float32Array.from(
      /** @type {Float32Array} */ (output.logits_value.data)
    )
    self.postMessage({ type: 'result', id, move, value }, [
      move.buffer,
      value.buffer
    ])
  } catch (error) {
    self.postMessage({ type: 'error', id, message: String(error) })
  }
}

self.onmessage = ({ data }) => {
  if (data?.type === 'load') load(data.url)
  else if (data?.type === 'run') run(data)
}

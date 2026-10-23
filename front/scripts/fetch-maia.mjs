// Puts Maia in public/maia/ (docs/ENGINE.md, § Maia): Maia-3 23M, fp16 ONNX export, downloaded from
// Hugging Face at a pinned commit and checked against the digest below; a file already there with
// the right digest is kept. The folder is not in git: run once after a clone, and deploy.sh runs it
// before each build. The ONNX runtime's own .wasm comes from node_modules (onnxruntime-web).
// Usage: npm run maia:fetch   Exits 1 when the file cannot be fetched or does not match.
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'

const DIR = new URL('../public/maia/', import.meta.url).pathname
const COMMIT = '923df9a2e9396b54a09b168bb858ebbd5e5b76bc'
const REPOSITORY = `https://huggingface.co/bqrio/maia3-onnx/resolve/${COMMIT}/`
const FILES = {
  'maia3-23m.fp16.onnx':
    'c093e4c54ead1ca51a4a52135c0f21f64b8639a0469d128fea0d0cdeb6fac1c7'
}
// Served next to the model: its license and where its sources are (AGPLv3).
const NOTICE = `Maia-3 23M (maia3-23m.fp16.onnx), ONNX export in float16 of the Maia3-23M checkpoint
Copyright (c) the CSSLab, University of Toronto (Maia authors).
License: GNU Affero General Public License v3 (https://www.gnu.org/licenses/agpl-3.0.html)

Sources:
- ONNX export: https://huggingface.co/bqrio/maia3-onnx (commit ${COMMIT})
- Checkpoint: https://huggingface.co/UofTCSSLab/Maia3-23M
- Code: https://github.com/CSSLab/maia3
- Paper: Monroe et al., Chessformer: A Unified Architecture for Chess Modeling, ICLR 2026
`

/** @param {Uint8Array} bytes */
const digest = bytes => createHash('sha256').update(bytes).digest('hex')

mkdirSync(DIR, { recursive: true })
let failed = false
for (const [name, expected] of Object.entries(FILES)) {
  const path = DIR + name
  if (existsSync(path) && digest(readFileSync(path)) === expected) {
    console.log(`ok       ${name}`)
    continue
  }
  const response = await fetch(REPOSITORY + name)
  if (!response.ok) {
    console.error(`failed   ${name}: HTTP ${response.status}`)
    failed = true
    continue
  }
  const bytes = new Uint8Array(await response.arrayBuffer())
  if (digest(bytes) !== expected) {
    console.error(`mismatch ${name}: not the file expected, not written`)
    failed = true
    continue
  }
  writeFileSync(path, bytes)
  console.log(`fetched  ${name}`)
}
writeFileSync(DIR + 'README.txt', NOTICE)
process.exit(failed ? 1 : 0)

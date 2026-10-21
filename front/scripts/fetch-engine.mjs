// Puts the chess engine in public/engine/ (docs/ENGINE.md): Stockfish.js 19.0.1, lite single-threaded,
// downloaded from its GitHub release and checked against the digests below; a file already there
// with the right digest is kept. The folder is not in git: run once after a clone, and deploy.sh
// runs it before each build.
// Usage: npm run engine:fetch   Exits 1 when a file cannot be fetched or does not match.
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'

const DIR = new URL('../public/engine/', import.meta.url).pathname
const RELEASE =
  'https://github.com/nmrugg/stockfish.js/releases/download/v19.0.1/'
const FILES = {
  'stockfish-19-lite-single.js':
    'ccb130d7ff29408531c47d859a49f91d59d4f482d05a53eb1a3231a4f624c91a',
  'stockfish-19-lite-single.wasm':
    'ee4f115bdb2d32cadf96cada104a05b5ec257d7ad6f57582190b49bc71f096de'
}
// Served next to the engine: its license and where its sources are (GPLv3).
const NOTICE = `Stockfish.js 19.0.1, lite single-threaded build (stockfish-19-lite-single.js, .wasm)
Copyright (c) Nathan Rugg, Chess.com, LLC, and the Stockfish developers.
License: GNU General Public License v3 (https://www.gnu.org/licenses/gpl-3.0.html)

Sources:
- Stockfish.js: https://github.com/nmrugg/stockfish.js (release v19.0.1)
- Stockfish: https://github.com/official-stockfish/Stockfish
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
  const response = await fetch(RELEASE + name)
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

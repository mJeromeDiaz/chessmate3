// Lists the texts still to translate (docs/I18N.md): every key of the French source missing in a
// language, file by file; also the keys a language has that French no longer has (left over).
// Usage: npm run i18n:missing [-- en] [admin]   (all languages and files by default; a file as
// `admin`, `admin.json` or `en/admin.json`). Exits 1 when something is missing.
import { readdirSync, readFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = new URL('../src/i18n/', import.meta.url).pathname
const SOURCE = 'fr'

/** @returns {Record<string, unknown>} */
function read(path) {
  return existsSync(path) ? JSON.parse(readFileSync(path, 'utf8')) : {}
}

/** Flattens nested messages into dotted keys. */
function keys(messages, prefix = '') {
  return Object.entries(messages).flatMap(([key, value]) =>
    value !== null && typeof value === 'object'
      ? keys(value, `${prefix}${key}.`)
      : [`${prefix}${key}`]
  )
}

const all = readdirSync(ROOT, { withFileTypes: true })
  .filter(d => d.isDirectory() && d.name !== SOURCE)
  .map(d => d.name)
const wantedLanguages = new Set()
const wantedFiles = new Set()
for (const arg of process.argv.slice(2)) {
  const [first, second] = arg.split('/')
  if (second !== undefined) wantedLanguages.add(first)
  else if (all.includes(first)) {
    wantedLanguages.add(first)
    continue
  }
  const file = second ?? first
  wantedFiles.add(file.endsWith('.json') ? file : `${file}.json`)
}
const languages = all.filter(
  name => wantedLanguages.size === 0 || wantedLanguages.has(name)
)
const files = readdirSync(join(ROOT, SOURCE)).filter(
  f => f.endsWith('.json') && (wantedFiles.size === 0 || wantedFiles.has(f))
)
const unknown = [...wantedFiles].filter(file => !files.includes(file))
for (const file of unknown)
  console.log(`No such message file: ${SOURCE}/${file}`)
if (unknown.length) process.exit(1)

let missingCount = 0
for (const language of languages) {
  for (const file of files) {
    const source = new Set(keys(read(join(ROOT, SOURCE, file))))
    const target = new Set(keys(read(join(ROOT, language, file))))
    const missing = [...source].filter(k => !target.has(k))
    const extra = [...target].filter(k => !source.has(k))
    if (missing.length === 0 && extra.length === 0) continue
    console.log(`\n${language}/${file}`)
    for (const key of missing) console.log(`  missing  ${key}`)
    for (const key of extra) console.log(`  left over  ${key}`)
    missingCount += missing.length
  }
}

console.log(
  missingCount
    ? `\n${missingCount} text(s) to translate.`
    : 'Everything is translated.'
)
process.exit(missingCount ? 1 : 0)

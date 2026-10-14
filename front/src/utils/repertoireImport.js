import { t } from '@/i18n'

/**
 * Wording and choices of the repertoire import (docs/REPERTOIRE.md, "Import"). The API speaks in
 * stable codes; the words are here.
 */

/**
 * @typedef {{type: string, game: number, move?: string, count?: number}} ImportWarning
 * @typedef {{uci: string, san: string, origin: 'existing'|'file'|'both'}} Candidate
 * @typedef {{fen: string, path: string[], candidates: Candidate[], choice: string}} Conflict
 */

/** Largest import, as the API checks it (App\Repertoire\Limits::$maxImportBytes). */
export const MAX_IMPORT_BYTES = 1_048_576

/** The API's failure codes with a text of their own (repertoire.import.failures). */
const FAILURES = [
  'too_large',
  'too_many_games',
  'too_many_positions',
  'empty',
  'invalid_study_url',
  'study_private',
  'study_not_found',
  'limit_positions',
  'limit_depth',
  'limit_repertoires',
  'stale',
  'not_found',
  'invalid_name'
]

/** The API's warning types with a text of their own (repertoire.import.warnings). */
const WARNINGS = [
  'illegal_move',
  'repeated_position',
  'too_deep',
  'invalid_start',
  'start_not_found',
  'comment_truncated'
]

/**
 * Why an import failed or was refused, for the user.
 *
 * @param {string|null|undefined} code
 * @param {number|null} [line] PGN line of a syntax error
 * @returns {string}
 */
export function failureText(code, line = null) {
  const f = 'repertoire.import.failures.'
  if (code === 'syntax')
    return line ? t(`${f}syntaxLine`, { line }) : t(`${f}syntax`)
  return t(`${f}${code && FAILURES.includes(code) ? code : 'default'}`)
}

/**
 * @param {ImportWarning} warning
 * @param {string} [source] the import's source: an OpenBook backup has no games
 * @returns {string}
 */
export function warningText({ type, game, move, count }, source = 'pgn') {
  const w = 'repertoire.import.'
  const where =
    source === 'openbook'
      ? (move ?? t(`${w}where.position`))
      : move
        ? t(`${w}where.gameMove`, { game, move })
        : t(`${w}where.game`, { game })
  if (type === 'invalid_position')
    return t(`${w}warnings.invalid_position`, { move: move ?? '' })
  if (type === 'unreachable')
    return t(`${w}warnings.unreachable`, { n: count ?? 0 }, count ?? 0)
  return t(`${w}warnings.${WARNINGS.includes(type) ? type : 'default'}`, {
    where,
    game
  })
}

/**
 * The choices the API applies when none is sent: one per conflict.
 *
 * @param {Conflict[]} conflicts
 * @returns {Record<string, string>} normalized FEN => UCI
 */
export function defaultChoices(conflicts) {
  return Object.fromEntries(conflicts.map(c => [c.fen, c.choice]))
}

/**
 * Keeps what the repertoire has everywhere, or takes the file's first move everywhere (a
 * conflict without such a candidate keeps its default).
 *
 * @param {Conflict[]} conflicts
 * @param {'existing'|'file'} side
 * @returns {Record<string, string>}
 */
export function bulkChoices(conflicts, side) {
  return Object.fromEntries(
    conflicts.map(c => {
      const pick =
        side === 'existing'
          ? c.candidates.find(
              x => x.origin === 'existing' || x.origin === 'both'
            )
          : c.candidates.find(x => x.origin === 'file')
      return [c.fen, pick?.uci ?? c.choice]
    })
  )
}

/**
 * A conflict's position as moves: "1.e4 e5 2.…" (the moves that lead there).
 *
 * @param {string[]} path SAN from the initial position
 * @returns {string}
 */
export function pathText(path) {
  const words = path.map((san, i) =>
    i % 2 === 0 ? `${i / 2 + 1}.${san}` : san
  )
  const next =
    path.length % 2 === 0
      ? `${path.length / 2 + 1}.`
      : `${(path.length + 1) / 2}…`
  return [...words, next].join(' ')
}

/**
 * The origin of a candidate, for its badge.
 *
 * @param {Candidate['origin']} origin
 */
export function originLabel(origin) {
  return t(`repertoire.import.origins.${origin}`)
}

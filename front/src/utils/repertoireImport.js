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

const FAILURES = {
  too_large: 'Le fichier dépasse 1 Mo.',
  too_many_games: 'Le fichier contient plus de 300 parties ou chapitres.',
  too_many_positions:
    'Le fichier contient plus de 5 000 positions : découpez-le en plusieurs répertoires.',
  empty: 'Aucun coup légal à importer dans ce fichier.',
  syntax: 'Le fichier est mal formé',
  invalid_study_url:
    'Ce lien n’est pas celui d’une étude Lichess (lichess.org/study/…).',
  study_private:
    'Lichess ne donne pas cette étude : elle est privée, non répertoriée, ou n’existe pas.',
  study_not_found: 'Cette étude n’existe pas, ou vous n’y avez pas accès.',
  limit_positions:
    'Le répertoire dépasserait 5 000 positions : importez dans un nouveau répertoire, ou allégez le fichier.',
  limit_depth:
    'Une ligne dépasserait 80 demi-coups depuis la position initiale.',
  limit_repertoires: 'Vous avez atteint le nombre maximal de répertoires (50).',
  stale:
    'Le répertoire a été modifié entre-temps : vérifiez l’aperçu, puis recommencez.',
  not_found: 'Ce répertoire n’existe plus.',
  invalid_name: 'Nom de répertoire invalide.'
}

/**
 * Why an import failed or was refused, for the user.
 *
 * @param {string|null|undefined} code
 * @param {number|null} [line] PGN line of a syntax error
 * @returns {string}
 */
export function failureText(code, line = null) {
  if (code === 'syntax') {
    return line ? `${FAILURES.syntax} (ligne ${line}).` : `${FAILURES.syntax}.`
  }
  return FAILURES[code] ?? 'L’import a échoué. Réessayez.'
}

/**
 * @param {ImportWarning} warning
 * @param {string} [source] the import's source: an OpenBook backup has no games
 * @returns {string}
 */
export function warningText({ type, game, move, count }, source = 'pgn') {
  const where =
    source === 'openbook'
      ? (move ?? 'Position')
      : `Partie ${game}${move ? `, ${move}` : ''}`
  switch (type) {
    case 'invalid_position':
      return `Position ignorée (invalide, ou pas à vous de jouer) : ${move ?? ''}`
    case 'unreachable':
      return `${count ?? 0} position${(count ?? 0) > 1 ? 's' : ''} du fichier ne ${(count ?? 0) > 1 ? 'sont' : 'est'} atteinte${(count ?? 0) > 1 ? 's' : ''} par aucune ligne depuis la position initiale : ignorée${(count ?? 0) > 1 ? 's' : ''}.`
    case 'illegal_move':
      return `${where} : coup illégal ou ambigu, la ligne s’arrête avant lui.`
    case 'repeated_position':
      return `${where} : le coup ramène à une position d’où vient la ligne, il est ignoré.`
    case 'too_deep':
      return `${where} : ligne coupée à 80 demi-coups.`
    case 'invalid_start':
      return `Partie ${game} : position de départ (FEN) invalide, partie ignorée.`
    case 'start_not_found':
      return `Partie ${game} : elle part d’une position absente du fichier et du répertoire, elle est ignorée.`
    case 'comment_truncated':
      return `${where} : commentaire raccourci à 2 000 caractères.`
    default:
      return `${where} : élément ignoré.`
  }
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
  return { existing: 'répertoire', file: 'fichier', both: 'les deux' }[origin]
}

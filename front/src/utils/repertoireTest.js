/**
 * Wording of the repertoire test (docs/REPERTOIRE.md § 15).
 *
 * @typedef {{opening: {eco: string, name: string}|null, move: string|null}} SegmentLabel
 */

/**
 * "Sicilian Defense · 1…c5"; the trunk (no deviation): "Tronc commun · King's Pawn Game".
 *
 * @param {SegmentLabel|null|undefined} label
 * @returns {string}
 */
export function labelText(label) {
  const name = label?.opening?.name ?? null
  if (label?.move) return name ? `${name} · ${label.move}` : label.move
  return name ? `Tronc commun · ${name}` : 'Tronc commun'
}

/**
 * Moves numbered from the initial position: "1.e4 e5 2.Nf3", or "3…c5 4.d4" from a later ply.
 *
 * @param {string[]} sans
 * @param {number} [firstPly] ply of the first move (0: White's first move)
 * @returns {string}
 */
export function numberedMoves(sans, firstPly = 0) {
  return sans
    .map((san, i) => {
      const ply = firstPly + i
      const number = Math.floor(ply / 2) + 1
      if (ply % 2 === 0) return `${number}.${san}`
      return i === 0 ? `${number}…${san}` : san
    })
    .join(' ')
}

/**
 * @param {'segment'|'line'} unit
 * @param {number} count
 * @returns {string}
 */
export function unitWord(unit, count) {
  const word = unit === 'line' ? 'ligne' : 'tronçon'
  return `${count} ${word}${count > 1 ? 's' : ''}`
}

/**
 * Status of a presentation, for the user.
 *
 * @param {string} status
 * @returns {string}
 */
export function statusText(status) {
  switch (status) {
    case 'succeeded':
      return 'Réussi'
    case 'failed':
      return 'Raté'
    case 'interrupted':
      return 'Interrompu'
    case 'in_progress':
      return 'En cours'
    default:
      return status
  }
}

import { t } from '@/i18n'

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
  if (label?.move)
    return name
      ? t('repertoire.test.withMove', { name, move: label.move })
      : label.move
  return name
    ? t('repertoire.test.trunkNamed', { name })
    : t('repertoire.test.trunk')
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
  return t(
    `repertoire.test.units.${unit === 'line' ? 'line' : 'segment'}`,
    { n: count },
    count
  )
}

/**
 * Status of a presentation, for the user.
 *
 * @param {string} status
 * @returns {string}
 */
export function statusText(status) {
  return ['succeeded', 'failed', 'interrupted', 'in_progress'].includes(status)
    ? t(`repertoire.test.status.${status}`)
    : status
}

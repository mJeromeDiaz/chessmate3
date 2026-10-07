import { formatRate } from '@/utils/format'

/**
 * Blindfold puzzles (docs/BLINDFOLD.md), what the pages show. Pure. The rules come from the API.
 *
 * @typedef {object} PuzzleRules
 * @property {{key: string, min: number, max: number}[]} levels
 * @property {number[]} lengths player moves (the last one means "or more")
 * @property {number[]} visibleSeconds
 * @property {number} hiddenSeconds
 * @property {number} peeks
 *
 * @typedef {{played: number, solved: number, helped: number, failed: number}} PuzzleCounts
 */

/** The levels, as the API names them. */
export const LEVELS = {
  easy: 'Facile',
  medium: 'Moyen',
  hard: 'Difficile'
}

/**
 * A level with its ratings: "Facile · 600–1000".
 *
 * @param {{key: string, min: number, max: number}} level
 */
export function levelText(level) {
  return `${LEVELS[level.key] ?? level.key} · ${level.min}–${level.max}`
}

/**
 * A length of solution: "2 coups", "4 coups et +" (the longest length means "or more").
 *
 * @param {number} length
 * @param {number[]} [lengths] every length offered
 */
export function lengthText(length, lengths = []) {
  const more = lengths.length > 0 && length === Math.max(...lengths)
  return `${length} coups${more ? ' et +' : ''}`
}

/**
 * The success of a set of blindfold puzzles: "8 résolus sur 12 (66 %) · 2 avec coup d’œil".
 *
 * @param {PuzzleCounts} counts
 */
export function puzzleCountsText(counts) {
  if (!counts.played) return 'Aucun puzzle joué.'
  const rate = formatRate(counts.solved / counts.played)
  const helped = counts.helped ? ` · ${counts.helped} avec coup d’œil` : ''
  return `${counts.solved} résolu${counts.solved > 1 ? 's' : ''} sur ${counts.played} (${rate})${helped}`
}

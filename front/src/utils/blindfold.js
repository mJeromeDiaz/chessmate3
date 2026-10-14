import { formatRate } from '@/utils/format'
import { t } from '@/i18n'

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
export const LEVELS = Object.defineProperties(
  {},
  Object.fromEntries(
    ['easy', 'medium', 'hard'].map(level => [
      level,
      { get: () => t(`blindfold.levels.${level}`), enumerable: true }
    ])
  )
)

/**
 * A level with its ratings: "Facile · 600–1000".
 *
 * @param {{key: string, min: number, max: number}} level
 */
export function levelText(level) {
  return t('blindfold.level', {
    name: LEVELS[level.key] ?? level.key,
    min: level.min,
    max: level.max
  })
}

/**
 * A length of solution: "2 coups", "4 coups et +" (the longest length means "or more").
 *
 * @param {number} length
 * @param {number[]} [lengths] every length offered
 */
export function lengthText(length, lengths = []) {
  const more = lengths.length > 0 && length === Math.max(...lengths)
  return t(more ? 'blindfold.lengthMore' : 'blindfold.length', { n: length })
}

/**
 * The success of a set of blindfold puzzles: "8 résolus sur 12 (66 %) · 2 avec coup d’œil".
 *
 * @param {PuzzleCounts} counts
 */
export function puzzleCountsText(counts) {
  if (!counts.played) return t('blindfold.counts.none')
  const rate = formatRate(counts.solved / counts.played)
  const helped = counts.helped
    ? t('blindfold.counts.helped', { n: counts.helped })
    : ''
  return (
    t(
      'blindfold.counts.solved',
      { n: counts.solved, played: counts.played, rate },
      counts.solved
    ) + helped
  )
}

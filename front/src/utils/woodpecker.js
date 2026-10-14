import { intlLocale, t } from '@/i18n'

/**
 * Woodpecker set page helpers (docs/WOODPECKER.md): the cycle lengths, the last day of a
 * deadline and the explanation of the method, worded from the set's own settings.
 */

/**
 * Length of cycle `number` in days: first × factor^(number − 1), rounded up, never below the
 * minimum. Mirrors the server's DeadlineCalculator::cycleDays().
 *
 * @param {{firstCycleDays: number, reductionFactor: number, minCycleDays: number}} config
 * @param {number} number 1-based
 * @returns {number}
 */
export function cycleDays(config, number) {
  const days = config.firstCycleDays * config.reductionFactor ** (number - 1)
  return Math.max(1, config.minCycleDays, Math.ceil(days - 1e-9))
}

/**
 * The length of every cycle of a classic set, in days: [28, 14, 7, 4, 2].
 *
 * @param {{cycleCount: number|null, firstCycleDays: number|null, reductionFactor: number|null, minCycleDays: number|null}} set
 * @returns {number[]}
 */
export function cycleSchedule(set) {
  if (
    !set.cycleCount ||
    !set.firstCycleDays ||
    !set.reductionFactor ||
    set.minCycleDays === null
  )
    return []
  const config = {
    firstCycleDays: set.firstCycleDays,
    reductionFactor: set.reductionFactor,
    minCycleDays: set.minCycleDays
  }
  return Array.from({ length: set.cycleCount }, (_, i) =>
    cycleDays(config, i + 1)
  )
}

/**
 * The last day of a deadline (the end of a local day, i.e. the next local midnight), in words:
 * "mardi 21 octobre".
 *
 * @param {string} deadlineAt ISO 8601
 * @param {string} timeZone the user's timezone
 * @returns {string}
 */
export function deadlineDay(deadlineAt, timeZone) {
  return new Intl.DateTimeFormat(intlLocale(), {
    timeZone,
    weekday: 'long',
    day: 'numeric',
    month: 'long'
  }).format(new Date(new Date(deadlineAt).getTime() - 1000))
}

/**
 * How the cycles work, in a few short points worded from the set's settings.
 *
 * @param {{mode: 'classic'|'light', puzzleCount: number, cycleCount: number|null, firstCycleDays: number|null, reductionFactor: number|null, minCycleDays: number|null, restDays: number|null, shuffle: boolean}} set
 * @returns {{title: string, text: string}[]}
 */
export function explainCycles(set) {
  if (set.mode === 'light') {
    return ['same', 'oneTry', 'grows', 'custom'].map(key => ({
      title: t(`woodpecker.explain.light.${key}.title`),
      text: t(`woodpecker.explain.light.${key}.text`, { n: set.puzzleCount })
    }))
  }

  const schedule = cycleSchedule(set)
  const days = schedule
    .map(d => t('woodpecker.explain.days', { n: d }))
    .join(' → ')
  const rest = set.restDays
    ? t('woodpecker.explain.rest', { n: set.restDays }, set.restDays)
    : t('woodpecker.explain.noRest')
  return [
    {
      title: t('woodpecker.explain.classic.same.title'),
      text: t(
        set.shuffle
          ? 'woodpecker.explain.classic.same.shuffled'
          : 'woodpecker.explain.classic.same.ordered',
        {
          n: set.puzzleCount
        }
      )
    },
    {
      title: t('woodpecker.explain.classic.faster.title'),
      text: t('woodpecker.explain.classic.faster.text', {
        n: set.cycleCount,
        days,
        rest
      })
    },
    ...['oneTry', 'deadline', 'goal', 'custom'].map(key => ({
      title: t(`woodpecker.explain.classic.${key}.title`),
      text: t(`woodpecker.explain.classic.${key}.text`)
    }))
  ]
}

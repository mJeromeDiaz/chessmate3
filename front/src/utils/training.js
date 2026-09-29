import { formatDate } from '@/utils/format'

/**
 * Why a timed run closed, for the user.
 *
 * @param {import('@/composables/training/useTimeboxedRun').TrainingRun} run
 * @returns {string}
 */
export function closeReasonText(run) {
  switch (run.closeReason) {
    case 'time_up':
      return 'Temps écoulé.'
    case 'stopped':
      return 'Séance terminée avant la fin du temps.'
    case 'subject_finished':
      return 'Set terminé, bravo !'
    case 'subject_resting':
      return `Cycle terminé. Repos : prochain cycle le ${formatDate(run.summary?.context?.availableAt)}.`
    case 'subject_unavailable':
      return 'Le set a été mis en pause ou abandonné : séance close.'
    default:
      return ''
  }
}

/**
 * Where to go back to after a run: the page of its subject.
 *
 * @param {{subjectType: string, subjectId: string}} run
 * @returns {string}
 */
export function subjectPath(run) {
  return run.subjectType === 'woodpecker_set'
    ? `/woodpecker/${run.subjectId}`
    : '/'
}

/**
 * Each run with its change in items per minute since the previous (older) one.
 *
 * @template {{summary: {itemsPerMinute: number|null}|null}} R
 * @param {R[]} runs newest first
 * @returns {(R & {trend: number|null})[]}
 */
export function runTrends(runs) {
  return runs.map((run, i) => {
    const current = run.summary?.itemsPerMinute ?? null
    const previous = runs[i + 1]?.summary?.itemsPerMinute ?? null
    return {
      ...run,
      trend:
        current === null || previous === null
          ? null
          : Math.round((current - previous) * 100) / 100
    }
  })
}

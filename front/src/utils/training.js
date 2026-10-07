import { formatDate } from '@/utils/format'

/** Free study formats (module `free`), as the API names them. */
export const FREE_FORMATS = {
  book: 'Livre',
  video: 'Vidéo',
  course: 'Cours',
  podcast: 'Podcast',
  other: 'Autre'
}

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
      if (run.module === 'coordinates')
        return 'Toutes les cases de la série sont faites !'
      return 'Set terminé, bravo !'
    case 'subject_resting':
      return `Cycle terminé. Repos : prochain cycle le ${formatDate(run.summary?.context?.availableAt)}.`
    case 'subject_unavailable':
      if (run.module === 'repertoire')
        return 'Plus rien à tester dans cette sélection : séance close.'
      if (run.module === 'puzzles')
        return 'Plus aucun puzzle disponible pour ces thèmes : séance close.'
      if (run.module === 'blindfold')
        return 'Plus aucun puzzle à l’aveugle à ce niveau et de cette longueur : séance close.'
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
  if (run.subjectType === 'woodpecker_set')
    return `/woodpecker/${run.subjectId}`
  if (run.subjectType === 'repertoire_owner') return '/repertoire'
  if (run.subjectType === 'puzzle_player') return '/puzzle'
  if (run.subjectType === 'coordinates_player') return '/coordinates'
  if (run.subjectType === 'blindfold_player') return '/blindfold'
  return '/'
}

/**
 * The label of the "back" button after a run.
 *
 * @param {{module: string}} run
 * @returns {string}
 */
export function backLabel(run) {
  switch (run.module) {
    case 'woodpecker':
      return 'Retour au set'
    case 'repertoire':
      return 'Retour aux répertoires'
    case 'puzzles':
      return 'Retour aux puzzles'
    case 'coordinates':
      return 'Retour aux coordonnées'
    case 'blindfold':
      return 'Retour au jeu à l’aveugle'
    default:
      return 'Retour à l’accueil'
  }
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

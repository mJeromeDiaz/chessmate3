import { formatDate } from '@/utils/format'
import { t } from '@/i18n'

/** The API's refusal when another run of the player's is still open (409). */
export const RUN_IN_PROGRESS = 'run_in_progress'

/**
 * Whether a request was refused because another run is still open.
 *
 * @param {unknown} error
 */
export function isRunInProgress(error) {
  const response = /** @type {any} */ (error)?.response
  return response?.status === 409 && response?.data?.detail === RUN_IN_PROGRESS
}

/**
 * Starts a run, closing first the one left open (docs/TRAINING.md, « Une séance à la fois »): a run
 * quit without being closed (a tab closed, a stop lost on the network) never blocks the next one.
 * Refused for another run in progress, the open run is stopped and the start tried once more.
 *
 * @template T
 * @param {() => Promise<T>} start
 * @param {{current: () => Promise<{id: string}|null>, stop: (id: string) => Promise<unknown>}} runs
 * @returns {Promise<T>}
 */
export async function closingOpenRun(start, runs) {
  try {
    return await start()
  } catch (e) {
    if (!isRunInProgress(e)) throw e
    const open = await runs.current()
    // Closed meanwhile (a stop on its way): the start is tried again all the same.
    if (open) await runs.stop(open.id).catch(() => {})
    return start()
  }
}

/**
 * Free study formats (module `free`), as the API names them, to their labels (read when shown).
 *
 * @type {Record<string, string>}
 */
export const FREE_FORMATS = Object.defineProperties(
  {},
  Object.fromEntries(
    ['book', 'video', 'course', 'podcast', 'other'].map(format => [
      format,
      {
        get: () => t(`session.catalog.options.formats.${format}`),
        enumerable: true
      }
    ])
  )
)

/** Modules with their own "subject unavailable" text (training.close.unavailable). */
const UNAVAILABLE = [
  'repertoire',
  'puzzles',
  'blindfold',
  'evaluation',
  'brilliancy',
  'puzzle_streak',
  'parcours_puzzles',
  'puzzle_rush',
  'blindfold_recall',
  'notation',
  'endgames'
]

/** Modules with their own "back" label (training.back). */
const BACK_MODULES = [
  'woodpecker',
  'repertoire',
  'puzzles',
  'coordinates',
  'blindfold',
  'evaluation',
  'brilliancy',
  'puzzle_streak',
  'parcours_puzzles',
  'puzzle_rush',
  'blindfold_recall',
  'notation',
  'endgames'
]

/**
 * Why a timed run closed, for the user.
 *
 * @param {import('@/composables/training/useTimeboxedRun').TrainingRun} run
 * @returns {string}
 */
export function closeReasonText(run) {
  switch (run.closeReason) {
    case 'time_up':
      return t('training.close.time_up')
    case 'stopped':
      return t('training.close.stopped')
    case 'subject_finished':
      if (run.module === 'puzzle_streak')
        return run.summary?.context?.reason === 'no_puzzle'
          ? t('training.close.streakNoPuzzle')
          : t('training.close.streakDone')
      if (run.module === 'coordinates')
        return t('training.close.coordinatesDone')
      if (run.module === 'evaluation') return t('training.close.evaluationDone')
      // A lesson of the mind map (docs/MIND_MAP.md): its count of exercises played.
      if (run.summary?.context?.reason === 'count_done')
        return t('training.close.countDone')
      // A parcours tier (docs/PARCOURS.md): its count played, of puzzles or of lines.
      if (
        run.module === 'parcours_puzzles' ||
        run.summary?.context?.reason === 'limit_reached'
      )
        return t('training.close.tierDone')
      return t('training.close.setDone')
    case 'subject_resting':
      return t('training.close.resting', {
        date: formatDate(run.summary?.context?.availableAt)
      })
    case 'subject_unavailable':
      return t(
        `training.close.unavailable.${UNAVAILABLE.includes(run.module) ? run.module : 'woodpecker'}`
      )
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
  if (run.subjectType === 'evaluation_player') return '/evaluation'
  if (run.subjectType === 'brilliancy_player') return '/brillant-ou-blunder'
  if (run.subjectType === 'puzzle_streak_player') return '/streak'
  if (run.subjectType === 'parcours_player') return '/defis'
  if (run.subjectType === 'puzzle_rush_player') return '/puzzle'
  if (run.subjectType === 'blindfold_recall_player') return '/reconstitution'
  if (run.subjectType === 'notation_player') return '/notation'
  // A free run (a path's step ends on its verdict, never here).
  if (run.subjectType === 'endgame_player') return '/finales/libre'
  return '/'
}

/**
 * The label of the "back" button after a run.
 *
 * @param {{module: string}} run
 * @returns {string}
 */
export function backLabel(run) {
  return t(
    `training.back.${BACK_MODULES.includes(run.module) ? run.module : 'home'}`
  )
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

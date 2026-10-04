import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { formatDuration } from '@/utils/format'

/**
 * @typedef {object} SessionStep a step of a session, as the API sends it
 * @property {number} index
 * @property {string} module API module (free, puzzles, woodpecker, repertoire)
 * @property {number} minutes
 * @property {string} notes
 * @property {Record<string, any>} settings
 * @property {'pending'|'running'|'done'|'skipped'|'unplayed'} status
 * @property {string|null} runId
 * @property {{reason: string, message: string}|null} blocked
 * @property {import('@/composables/training/useTimeboxedRun').RunSummary|null} summary
 *
 * @typedef {object} TrainingSession
 * @property {string} id
 * @property {string} title
 * @property {string} description
 * @property {'active'|'completed'|'abandoned'|'expired'} status
 * @property {number} currentIndex
 * @property {string} startedAt
 * @property {string} expiresAt
 * @property {string|null} closedAt
 * @property {number} durationMs
 * @property {SessionStep[]} steps
 */

/**
 * The catalogue module of a step (its title, professor and colours).
 *
 * @param {{module: string}} step
 * @returns {import('@/utils/session/catalog').Module|null}
 */
export function stepModule(step) {
  return MODULES_BY_ID[CATALOG_MODULES[step.module]] ?? null
}

/**
 * Why a step cannot start, for the user.
 *
 * @param {string} reason the API's reason
 * @returns {string}
 */
export function blockedText(reason) {
  switch (reason) {
    case 'light_set_paused':
      return 'Ton set light est en pause : reprends-le pour jouer ce module.'
    case 'no_light_set':
      return 'Tu n’as plus de set light en cours.'
    case 'nothing_to_test':
      return 'Rien à réviser dans ces répertoires pour l’instant.'
    case 'no_puzzle':
      return 'Plus aucun puzzle disponible pour ces thèmes.'
    case 'subject_not_found':
      return 'Le sujet de ce module n’existe plus (répertoire supprimé ?).'
    case 'invalid_settings':
      return 'Les réglages de ce module ne sont plus valides.'
    default:
      return 'Ce module ne peut pas démarrer pour l’instant.'
  }
}

/** @type {Record<SessionStep['status'], string>} */
const STEP_STATUS = {
  pending: 'À venir',
  running: 'En cours',
  done: 'Fait',
  skipped: 'Passé',
  unplayed: 'Non joué'
}

/**
 * The state of a step, with what its run did once played.
 *
 * @param {SessionStep} step
 * @returns {string}
 */
export function stepStatusText(step) {
  const s = step.summary
  if (step.status !== 'done' || !s) return STEP_STATUS[step.status] ?? ''
  if (step.module === 'free') return `Fait · ${formatDuration(s.durationMs)}`
  return `Fait · ${s.itemCount} terminés, ${s.successCount} réussis`
}

/** @type {Record<TrainingSession['status'], string>} */
const SESSION_STATUS = {
  active: 'En cours',
  completed: 'Terminée',
  abandoned: 'Abandonnée',
  expired: 'Non terminée'
}

/**
 * @param {TrainingSession} session
 * @returns {string}
 */
export function sessionStatusText(session) {
  return SESSION_STATUS[session.status] ?? ''
}

/**
 * "2 / 3 modules": steps played (done) over the program.
 *
 * @param {TrainingSession} session
 * @returns {string}
 */
export function sessionProgressText(session) {
  const done = session.steps.filter(s => s.status === 'done').length
  const total = session.steps.length
  return `${done} / ${total} module${total > 1 ? 's' : ''}`
}

/**
 * A refused `next`, for the user. A blocked step's own reason is in the session (`blocked`).
 *
 * @param {any} error
 * @returns {string}
 */
export function nextErrorText(error) {
  const detail = String(error?.response?.data?.detail ?? '')
  if (error?.response?.status !== 409) return ''
  if (detail.includes('Another training run'))
    return 'Une autre séance chronométrée est en cours : termine-la avant de continuer.'
  if (detail.includes('session is over')) return 'Cette session est terminée.'
  return 'Ce module ne peut pas démarrer pour l’instant.'
}

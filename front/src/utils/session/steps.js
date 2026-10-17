import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { formatDuration } from '@/utils/format'
import { t } from '@/i18n'

/** The API's reasons a step cannot start (session.steps.blocked.*). */
const BLOCKED_REASONS = [
  'light_set_paused',
  'no_light_set',
  'nothing_to_test',
  'no_puzzle',
  'subject_not_found',
  'invalid_settings'
]

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
 * @property {string|null} planId the saved session it was launched from (null once deleted)
 * @property {SessionStep[]} steps
 * @property {import('@/utils/shopReward').SessionReward|null} [reward] the crowns of a completed
 *   session (docs/SHOP.md, § 4 bis)
 * @property {import('@/utils/parcours').SessionParcours|null} [parcours] a parcours' theme, effort
 *   and repertoire (docs/PARCOURS.md); null for any other session
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
  return t(
    `session.steps.blocked.${BLOCKED_REASONS.includes(reason) ? reason : 'default'}`
  )
}

/** @type {SessionStep['status'][]} */
const STEP_STATUS = ['pending', 'running', 'done', 'skipped', 'unplayed']

/**
 * The state of a step, with what its run did once played.
 *
 * @param {SessionStep} step
 * @returns {string}
 */
export function stepStatusText(step) {
  const s = step.summary
  if (step.status !== 'done' || !s)
    return STEP_STATUS.includes(step.status)
      ? t(`session.steps.status.${step.status}`)
      : ''
  if (step.module === 'free')
    return t('session.steps.doneFree', {
      duration: formatDuration(s.durationMs)
    })
  return t('session.steps.doneRun', {
    done: s.itemCount,
    success: s.successCount
  })
}

/** @type {TrainingSession['status'][]} */
const SESSION_STATUS = ['active', 'completed', 'abandoned', 'expired']

/**
 * @param {TrainingSession} session
 * @returns {string}
 */
export function sessionStatusText(session) {
  return SESSION_STATUS.includes(session.status)
    ? t(`session.steps.sessionStatus.${session.status}`)
    : ''
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
  return t('session.steps.progress', { done, total }, total)
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
    return t('session.steps.otherRun')
  if (detail.includes('session is over')) return t('session.steps.over')
  return t('session.steps.blocked.default')
}

/**
 * What `POST /training/sessions` needs to play a session's program again (title, description,
 * each step's module, minutes, notes and settings), without what it became when played.
 *
 * @param {TrainingSession} session
 * @returns {{title: string, description: string, steps: {module: string, minutes: number, notes: string, settings: Record<string, any>}[]}}
 */
export function replayProgram(session) {
  return {
    title: session.title,
    description: session.description,
    steps: session.steps.map(step => ({
      module: step.module,
      minutes: step.minutes,
      notes: step.notes,
      settings: step.settings
    }))
  }
}

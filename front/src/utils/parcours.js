import { t } from '@/i18n'

/**
 * The parcours (docs/PARCOURS.md): five tiers of a theme, the effort's count of exercises in each,
 * chained without a pause and without a clock, played as a session whose steps are the tiers.
 *
 * @typedef {'mates'|'opening'|'endgames'|'tactics'} ParcoursTheme
 * @typedef {'light'|'steady'|'intense'} ParcoursEffort
 *
 * @typedef {object} TierPlan what a tier plays, as the API describes it
 * @property {'lines'|'puzzles'} kind lines of the repertoire, or puzzles
 * @property {string[]} themes the Lichess themes of its puzzles (none: the opening's family)
 *
 * @typedef {object} ParcoursOverview `GET /parcours`
 * @property {{theme: ParcoursTheme, tiers: TierPlan[]}[]} themes
 * @property {{effort: ParcoursEffort, perTier: number, total: number, bonusXp: number}[]} efforts
 * @property {{id: string, name: string, color: string, opening: {family: string, name: string}|null}[]} repertoires
 * @property {number} rating the player's puzzle rating, the middle of each tier
 * @property {number} ratingGap below and above it, for the first and last thirds
 *
 * @typedef {{theme: ParcoursTheme, effort: ParcoursEffort, repertoireId: string|null}} SessionParcours
 *   what a session says of its parcours (null for any other session)
 */

/** Tiers of a parcours (App\Parcours\ParcoursRules::TIERS). */
export const TIERS = 5

/** The pause between two tiers before the next one starts by itself. */
export const NEXT_TIER_MS = 2000

/** @type {ParcoursTheme[]} */
export const THEMES = ['mates', 'opening', 'endgames', 'tactics']

/** @type {ParcoursEffort[]} */
export const EFFORTS = ['light', 'steady', 'intense']

/** @param {string} theme */
export function themeName(theme) {
  return THEMES.includes(/** @type {any} */ (theme))
    ? t(`parcours.themes.${theme}.name`)
    : theme
}

/** @param {string} effort */
export function effortName(effort) {
  return EFFORTS.includes(/** @type {any} */ (effort))
    ? t(`parcours.efforts.${effort}`)
    : effort
}

/**
 * What a tier plays, for the player: "Mat en 2", "Fourchette", "Lignes du répertoire", "Puzzles
 * de l'ouverture".
 *
 * @param {TierPlan} plan
 * @returns {string}
 */
export function tierName(plan) {
  if (plan.kind === 'lines') return t('parcours.tier.lines')
  const theme = plan.themes[0]
  if (!theme) return t('parcours.tier.openingPuzzles')
  const key = `parcours.tier.themes.${theme}`
  const name = t(key)
  return name === key ? theme : name
}

/**
 * A step of a parcours' session as its tier's plan: lines for a repertoire step, else the
 * puzzles of its settings' themes.
 *
 * @param {{module: string, settings: Record<string, any>}} step
 * @returns {TierPlan}
 */
export function stepPlan(step) {
  return step.module === 'repertoire'
    ? { kind: 'lines', themes: [] }
    : { kind: 'puzzles', themes: [...(step.settings?.themes ?? [])] }
}

/**
 * Where the puzzle at `position` (0-based) of a tier of `count` stands against the player's
 * rating: the first third below, the last third above, the middle at it (10: 3, 4, 3), as
 * App\Parcours\ParcoursRules::target.
 *
 * @param {number} position
 * @param {number} count
 * @returns {'below'|'at'|'above'}
 */
export function ratingZone(position, count) {
  const third = Math.floor(count / 3)
  if (position < third) return 'below'
  if (position >= count - third) return 'above'
  return 'at'
}

/**
 * The tier of a session played by a run: its step's index, or -1.
 *
 * @param {{steps: {index: number, runId: string|null}[]}|null} session
 * @param {string|null|undefined} runId
 * @returns {number}
 */
export function tierOfRun(session, runId) {
  if (!session || !runId) return -1
  return session.steps.find(step => step.runId === runId)?.index ?? -1
}

/**
 * The exercises of a parcours' session: done (its tiers' items) out of the total, and solved.
 *
 * @param {{steps: {summary: {itemCount: number, successCount: number}|null, settings: Record<string, any>}[]}} session
 * @returns {{played: number, solved: number, total: number}}
 */
export function parcoursScore(session) {
  let played = 0
  let solved = 0
  let total = 0
  for (const step of session.steps) {
    played += step.summary?.itemCount ?? 0
    solved += step.summary?.successCount ?? 0
    total += step.settings?.count ?? step.settings?.limit ?? 0
  }
  return { played, solved, total }
}

/**
 * Why a parcours cannot be launched (`POST /parcours` refused), for the player.
 *
 * @param {any} error
 * @returns {string} '' when the error is not one of the parcours' own
 */
export function launchErrorText(error) {
  const status = error?.response?.status
  const detail = String(error?.response?.data?.detail ?? '')
  if (status === 409 && ['no_opening', 'empty_repertoire'].includes(detail))
    return t(`parcours.errors.${detail}`)
  if (status === 404) return t('parcours.errors.repertoire')
  return ''
}

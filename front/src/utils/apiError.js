import { t } from '@/i18n'

/** The API's refusals of a guest (App\Guest\GuestRefusedException), by reason. */
const GUEST_REFUSALS = /** @type {Record<string, string>} */ ({
  account_required: 'guest.refusals.accountRequired',
  guest_endgame_quota: 'guest.refusals.endgameQuota'
})

/**
 * Turns an API error into a message for the user.
 *
 * In production the API only says which status it answered (its messages are for debugging),
 * so each page maps the statuses it expects to its own wording. Validation errors (422) are the
 * exception: their per-field messages are meant for users and are shown as they are, and so is the
 * 503 of a themed puzzle while the catalogue's selection index is rebuilt (`X-Puzzle-Maintenance`),
 * which any page drawing puzzles may receive, and a guest's refusals (an account's page or module,
 * the endgames' daily quota).
 *
 * @param {unknown} error an axios error
 * @param {Record<number, string>} byStatus
 * @param {string} [fallback]
 * @returns {string}
 */
export function apiErrorMessage(
  error,
  byStatus = {},
  fallback = t('common.errors.generic')
) {
  const response =
    /** @type {{response?: {status: number, data?: any, headers?: Record<string, string>}}} */ (
      error
    )?.response

  if (!response) {
    return t('common.errors.offline')
  }

  if (response.status === 503 && response.headers?.['x-puzzle-maintenance']) {
    return t('common.errors.puzzleMaintenance')
  }

  // What stays an account's, refused to a guest (docs/GUEST.md), on any page.
  const guestRefusal = GUEST_REFUSALS[response.data?.detail]
  if (response.status === 403 && guestRefusal) {
    return t(guestRefusal)
  }

  if (byStatus[response.status]) {
    return byStatus[response.status]
  }

  if (response.status === 429) {
    return t('common.errors.tooMany')
  }

  const violations = response.data?.violations
  if (
    response.status === 422 &&
    Array.isArray(violations) &&
    violations.length > 0
  ) {
    return violations.map(v => v.title).join(' ')
  }

  return fallback
}

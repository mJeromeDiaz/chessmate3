/**
 * Turns an API error into a message for the user.
 *
 * In production the API only says which status it answered (its messages are for debugging),
 * so each page maps the statuses it expects to its own wording. Validation errors (422) are the
 * exception: their per-field messages are meant for users and are shown as they are, and so is the
 * 503 of a themed puzzle while the catalogue's selection index is rebuilt (`X-Puzzle-Maintenance`),
 * which any page drawing puzzles may receive.
 *
 * @param {unknown} error an axios error
 * @param {Record<number, string>} byStatus
 * @param {string} [fallback]
 * @returns {string}
 */
export function apiErrorMessage(
  error,
  byStatus = {},
  fallback = 'Une erreur est survenue. Réessayez.'
) {
  const response =
    /** @type {{response?: {status: number, data?: any, headers?: Record<string, string>}}} */ (
      error
    )?.response

  if (!response) {
    return 'Le serveur est injoignable. Vérifiez votre connexion.'
  }

  if (response.status === 503 && response.headers?.['x-puzzle-maintenance']) {
    return 'Le catalogue de puzzles est en maintenance. Réessayez dans une minute.'
  }

  if (byStatus[response.status]) {
    return byStatus[response.status]
  }

  if (response.status === 429) {
    return 'Trop de tentatives. Patientez quelques minutes avant de réessayer.'
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

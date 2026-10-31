import { describe, expect, it } from 'vitest'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * An axios-like error carrying a response.
 *
 * @param {number} status
 * @param {Record<string, string>} [headers]
 */
function answered(status, headers = {}) {
  return { response: { status, headers, data: {} } }
}

describe('apiErrorMessage', () => {
  it('explains the maintenance of the puzzle catalogue, whatever the page maps', () => {
    const error = answered(503, { 'x-puzzle-maintenance': 'rebuilding' })

    expect(apiErrorMessage(error, { 503: 'Lichess est indisponible.' })).toBe(
      'Le catalogue de puzzles est en maintenance. Réessayez dans une minute.'
    )
  })

  it('leaves any other 503 to the page', () => {
    expect(
      apiErrorMessage(answered(503), { 503: 'Lichess est indisponible.' })
    ).toBe('Lichess est indisponible.')
    expect(apiErrorMessage(answered(503))).toBe(
      'Une erreur est survenue. Réessayez.'
    )
  })

  it('explains what a guest is refused, on any page', () => {
    const refused = detail => ({
      response: { status: 403, headers: {}, data: { detail } }
    })

    expect(apiErrorMessage(refused('guest_endgame_quota'))).toContain('demain')
    expect(apiErrorMessage(refused('account_required'))).toContain('compte')
    expect(apiErrorMessage(refused('Access Denied.'), { 403: 'Non.' })).toBe(
      'Non.'
    )
  })

  it('says when the server cannot be reached', () => {
    expect(apiErrorMessage(new Error('Network Error'))).toBe(
      'Le serveur est injoignable. Vérifiez votre connexion.'
    )
  })
})

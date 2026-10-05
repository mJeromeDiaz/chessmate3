/**
 * Invitations and players as the administration shows them (docs/EARLY_ACCESS.md): labels, the
 * sign-up link of a key, what can still be done with an invitation. Pure.
 */

/** @type {Record<string, string>} */
export const STATUS_LABELS = {
  pending: 'En attente',
  used: 'Utilisée',
  expired: 'Expirée',
  revoked: 'Révoquée'
}

/** @type {Record<string, string>} */
export const EMAIL_LABELS = {
  pending: 'En cours d’envoi',
  sent: 'Envoyé',
  failed: 'Échec'
}

/** @type {Record<string, string>} */
export const ACTION_LABELS = {
  key_created: 'Clé créée',
  key_sent: 'Email envoyé',
  key_resent: 'Nouvelle clé envoyée',
  key_send_failed: 'Envoi abandonné',
  key_used: 'Clé utilisée',
  key_expired: 'Tentative avec la clé expirée',
  key_revoked: 'Clé révoquée'
}

/** @type {Record<string, string>} */
export const METHOD_LABELS = {
  password: 'Mot de passe',
  google: 'Google',
  lichess: 'Lichess',
  other: 'Sans clé'
}

/**
 * The link of the invitation email, for the admin to pass on by another channel: this SPA's
 * address, hash route `/register?key=…`.
 *
 * @param {string} key
 * @param {string} [base] the SPA's address (defaults to the current page's, without its hash)
 * @returns {string}
 */
export function signupLink(key, base = globalThis.location?.href ?? '') {
  const root = base.split('#')[0]
  return `${root}#/register?key=${encodeURIComponent(key)}`
}

/**
 * What the admin can still do: a used or revoked invitation is closed; resending an expired one
 * starts it again for 7 days.
 *
 * @param {{status: string}} invitation
 * @returns {{resend: boolean, revoke: boolean}}
 */
export function invitationActions(invitation) {
  const open =
    invitation.status === 'pending' || invitation.status === 'expired'
  return { resend: open, revoke: open }
}

/**
 * The expiry sent to the API for a date picked in the form: the end of that day, in the admin's
 * browser timezone.
 *
 * @param {string} date Y-m-d (or Y/m/d, as Quasar's date picker writes it)
 * @returns {string} ISO 8601 instant
 */
export function endOfDay(date) {
  const [y, m, d] = date.split(/[-/]/).map(Number)
  return new Date(y, m - 1, d, 23, 59, 59).toISOString()
}

/**
 * The name a player goes by in the list: display name, else handle, else email, else "Compte
 * Lichess" (a Lichess sign-up has no email).
 *
 * @param {{displayName: string|null, handle: string|null, email: string|null, lichess: {username: string|null}|null}} player
 * @returns {string}
 */
export function playerName(player) {
  return (
    player.displayName ??
    (player.handle ? `@${player.handle}` : null) ??
    player.email ??
    (player.lichess?.username
      ? `${player.lichess.username} (Lichess)`
      : 'Compte sans email')
  )
}

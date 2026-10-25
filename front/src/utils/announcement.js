/**
 * The team's announcements on the player's side (docs/ANNOUNCEMENTS.md, § 3). Pure.
 *
 * @typedef {object} CurrentAnnouncement
 * @property {string} id
 * @property {string} title in the player's language (French when no English one)
 * @property {string} body HTML, sanitized by the server when saved
 * @property {string} startsAt ISO 8601, UTC: shown from then on
 */

/** How often a page left open asks again (ms): an announcement published meanwhile shows up. */
export const POLL_INTERVAL = 5 * 60 * 1000

/**
 * Whether the modal may open on this page: never during a run, whatever its address.
 *
 * @param {string} path the route's path
 */
export function announcementAllowed(path) {
  return !path.startsWith('/training/')
}

/**
 * The address's `u` and `t` of the email's « stop these emails » link, '' when missing.
 *
 * @param {Record<string, unknown>} query the route's query
 * @returns {{u: string, t: string}}
 */
export function unsubscribeParams(query) {
  const read = /** @param {unknown} value */ value =>
    typeof value === 'string' ? value : ''
  return { u: read(query.u), t: read(query.t) }
}

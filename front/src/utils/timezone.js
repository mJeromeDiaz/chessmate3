/**
 * The browser's IANA timezone (e.g. "Europe/Paris"), or null if unavailable.
 *
 * @returns {string|null}
 */
export function browserTimezone() {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || null
  } catch {
    return null
  }
}

/**
 * Every IANA timezone the browser knows, for the profile selector.
 *
 * @returns {string[]}
 */
export function allTimezones() {
  return typeof Intl.supportedValuesOf === 'function'
    ? Intl.supportedValuesOf('timeZone')
    : [browserTimezone() ?? 'UTC']
}

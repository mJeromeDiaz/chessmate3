/**
 * @param {string|null|undefined} iso an ISO 8601 date from the API
 * @returns {string} e.g. "26/09/2026 14:05", or "—"
 */
export function formatDate(iso) {
  if (!iso) return '—'

  return new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short'
  }).format(new Date(iso))
}

/** @param {string} provider */
export function providerLabel(provider) {
  return { google: 'Google', lichess: 'Lichess' }[provider] ?? provider
}

/**
 * A duration as "m:ss" (or "h:mm:ss").
 *
 * @param {number|null|undefined} ms
 * @returns {string}
 */
export function formatDuration(ms) {
  if (ms === null || ms === undefined) return '—'
  const total = Math.round(ms / 1000)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = String(total % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

/**
 * A rating change as "+12" / "−8" (rounded), or "" when there is none.
 *
 * @param {number|null|undefined} delta
 * @returns {string}
 */
export function formatRatingDelta(delta) {
  if (delta === null || delta === undefined) return ''
  const rounded = Math.round(delta)
  return rounded >= 0 ? `+${rounded}` : `−${Math.abs(rounded)}`
}

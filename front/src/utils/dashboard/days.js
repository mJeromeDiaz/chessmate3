/**
 * Local days of the dashboard (Y-m-d strings, as the API gives them).
 *
 * @typedef {{date: string, count: number, successCount: number, durationMs: number}} ActivityDay
 */

/**
 * A Y-m-d day shifted by `days` (calendar arithmetic in UTC: no DST surprises).
 *
 * @param {string} date
 * @param {number} days
 * @returns {string}
 */
export function addDays(date, days) {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

/**
 * The activity heatmap (design "Dashboard", "Régularité"): one column per week, Monday on top,
 * the current week last; days after today are left blank.
 *
 * @typedef {{date: string, count: number, successCount: number, durationMs: number}} ActivityDay
 *
 * @typedef {object} HeatCell
 * @property {string} date Y-m-d
 * @property {number} count exercises that day
 * @property {0|1|2|3|4} level shade index
 * @property {boolean} today
 * @property {boolean} future after today: drawn empty
 */

/** Exercises needed for each shade above 0. */
export const LEVEL_THRESHOLDS = [1, 5, 10, 20]

/**
 * @param {number} count
 * @returns {0|1|2|3|4}
 */
export function heatLevel(count) {
  let level = 0
  for (const threshold of LEVEL_THRESHOLDS) if (count >= threshold) level++
  return /** @type {0|1|2|3|4} */ (level)
}

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

/**
 * @param {string} today Y-m-d, in the user's timezone (from the API)
 * @param {ActivityDay[]} days active days
 * @param {number} [weeks]
 * @returns {HeatCell[]} weeks × 7 cells, column by column
 */
export function buildHeatmap(today, days, weeks = 12) {
  const counts = new Map(days.map(d => [d.date, d.count]))
  const weekday = (new Date(`${today}T00:00:00Z`).getUTCDay() + 6) % 7 // Monday = 0
  const start = addDays(today, -weekday - 7 * (weeks - 1))
  return Array.from({ length: weeks * 7 }, (_, i) => {
    const date = addDays(start, i)
    const future = date > today
    const count = future ? 0 : (counts.get(date) ?? 0)
    return {
      date,
      count,
      level: heatLevel(count),
      today: date === today,
      future
    }
  })
}

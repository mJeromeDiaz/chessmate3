import { addDays } from '@/utils/dashboard/days'

/**
 * A rating curve drawn in a 100 × 100 SVG box stretched to its card (design "Dashboard"): x is
 * time over the period, y the rating with 15 % of headroom above and below.
 *
 * @typedef {{date: string, rating: number}} RatingPoint
 *
 * @typedef {object} Curve
 * @property {string} line SVG path of the line
 * @property {string} area the same path closed down to the bottom, for the fill
 * @property {number} dotTop y of the last point, in % of the box
 * @property {number} current last rating
 * @property {number} delta current − first rating of the period
 * @property {number} min
 * @property {number} max
 */

/**
 * @param {string} date
 * @returns {number} days since the epoch
 */
const dayNumber = date => Date.parse(`${date}T00:00:00Z`) / 86_400_000

/**
 * @param {RatingPoint[]} points oldest first
 * @param {string} from first day of the period
 * @param {string} today last day: the curve is carried flat up to it
 * @returns {Curve|null} null without any point
 */
export function buildCurve(points, from, today) {
  if (!points.length) return null
  const last = points[points.length - 1]
  const all =
    last.date < today
      ? [...points, { date: today, rating: last.rating }]
      : points
  if (all.length === 1)
    all.unshift({ date: addDays(today, -1), rating: last.rating })

  const ratings = all.map(p => p.rating)
  const min = Math.min(...ratings)
  const max = Math.max(...ratings)
  // A flat curve sits in the middle of the box.
  const pad = max > min ? (max - min) * 0.15 : 10
  const low = min - pad
  const high = max + pad
  const start = Math.min(dayNumber(from), dayNumber(all[0].date))
  const width = Math.max(1, dayNumber(today) - start)
  const xy = all.map(p => [
    ((dayNumber(p.date) - start) / width) * 100,
    100 - ((p.rating - low) / (high - low)) * 100
  ])
  const line = xy
    .map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(2)} ${y.toFixed(2)}`)
    .join(' ')
  return {
    line,
    area: `${line} L${xy[xy.length - 1][0].toFixed(2)} 100 L${xy[0][0].toFixed(2)} 100 Z`,
    dotTop: xy[xy.length - 1][1],
    current: last.rating,
    delta: last.rating - points[0].rating,
    min,
    max
  }
}

/**
 * Month labels under the curve: the first day of each month inside the period, by position.
 *
 * @param {string} from
 * @param {string} today
 * @returns {{label: string, left: number}[]} left in %
 */
export function monthTicks(from, today) {
  const width = Math.max(1, dayNumber(today) - dayNumber(from))
  const ticks = []
  let date = `${from.slice(0, 7)}-01`
  if (date < from) date = addMonth(date)
  const format = new Intl.DateTimeFormat('fr-FR', {
    month: 'long',
    timeZone: 'UTC'
  })
  while (date <= today) {
    const label = format.format(new Date(`${date}T00:00:00Z`))
    ticks.push({
      label: label.charAt(0).toUpperCase() + label.slice(1),
      left: ((dayNumber(date) - dayNumber(from)) / width) * 100
    })
    date = addMonth(date)
  }
  return ticks
}

/**
 * @param {string} date first day of a month
 * @returns {string} first day of the next one
 */
function addMonth(date) {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCMonth(d.getUTCMonth() + 1)
  return d.toISOString().slice(0, 10)
}

/**
 * The calendar day of an instant in an IANA timezone, as "YYYY-MM-DD".
 *
 * @param {Date} date
 * @param {string} timeZone
 * @returns {string}
 */
export function localDate(date, timeZone) {
  // en-CA formats dates as YYYY-MM-DD.
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  }).format(date)
}

/**
 * Local calendar days left before an (exclusive) deadline, today included; 0 once it has passed.
 * Mirrors the server's DeadlineCalculator::daysLeft().
 *
 * @param {Date} now
 * @param {Date} deadline end of a local day (the next local midnight)
 * @param {string} timeZone the user's timezone (not necessarily the browser's)
 * @returns {number}
 */
export function daysLeft(now, deadline, timeZone) {
  if (now >= deadline) return 0
  const today = Date.parse(`${localDate(now, timeZone)}T00:00:00Z`)
  const lastDay = Date.parse(
    `${localDate(new Date(deadline.getTime() - 1000), timeZone)}T00:00:00Z`
  )
  return Math.round((lastDay - today) / 86_400_000) + 1
}

/**
 * The pace needed to finish the cycle in time.
 *
 * @param {{remaining: number, deadlineAt: string|Date, timeZone: string, now?: Date}} input
 * @returns {{remaining: number, daysLeft: number, perDay: number, overdue: boolean, text: string}}
 */
export function pace({ remaining, deadlineAt, timeZone, now = new Date() }) {
  const left = daysLeft(now, new Date(deadlineAt), timeZone)
  const perDay = left > 0 ? Math.ceil(remaining / left) : remaining
  const overdue = left === 0 && remaining > 0

  return {
    remaining,
    daysLeft: left,
    perDay,
    overdue,
    text: paceText(remaining, left, perDay)
  }
}

/**
 * @param {number} remaining
 * @param {number} left
 * @param {number} perDay
 * @returns {string}
 */
function paceText(remaining, left, perDay) {
  const puzzles = n => `${n} puzzle${n > 1 ? 's' : ''}`
  if (remaining === 0) return 'Cycle terminé.'
  if (left === 0) return `Échéance dépassée : il restait ${puzzles(remaining)}.`
  if (left === 1)
    return `Dernier jour : encore ${puzzles(remaining)} aujourd’hui.`
  return `Il reste ${puzzles(remaining)} et ${left} jours : environ ${perDay} par jour.`
}

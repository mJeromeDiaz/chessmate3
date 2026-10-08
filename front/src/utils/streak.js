/**
 * Display helpers of the streaks (docs/GAMIFICATION.md, § 4): badges, the professor's words on the
 * celebration screen, the week row. Pure, so that they can be tested alone.
 *
 * @typedef {object} StreakNotice
 * @property {boolean} pending
 * @property {number} streak the streak today reached
 * @property {number} previousStreak the streak before today (0: it had broken)
 * @property {string|null} badge the streak badge (trophy key) today reached
 * @property {boolean[]} week the active days of the local week, Monday first, today included
 * @property {number|null} nextMilestone
 * @property {string} localDate Y-m-d
 *
 * @typedef {object} StreakBadge
 * @property {string} key trophy key
 * @property {number} goal days
 * @property {string} name
 */

/** @type {StreakBadge[]} the streak badges, shortest first (7 and 30 are the older trophies) */
export const STREAK_BADGES = [
  { key: 'streak_3', goal: 3, name: 'Étincelle' },
  { key: 'on_fire', goal: 7, name: 'En feu' },
  { key: 'streak_14', goal: 14, name: 'Feu de camp' },
  { key: 'unstoppable', goal: 30, name: 'Inarrêtable' },
  { key: 'streak_50', goal: 50, name: 'Brasier' },
  { key: 'streak_100', goal: 100, name: 'Centenaire' },
  { key: 'streak_200', goal: 200, name: 'Fournaise' },
  { key: 'streak_300', goal: 300, name: 'Volcan' },
  { key: 'streak_365', goal: 365, name: 'Une année' },
  { key: 'streak_450', goal: 450, name: 'Phénix' },
  { key: 'streak_500', goal: 500, name: 'Soleil' },
  { key: 'streak_1000', goal: 1000, name: 'Légende' }
]

/** The week row's labels, Monday first. */
export const WEEK_DAYS = ['L', 'M', 'M', 'J', 'V', 'S', 'D']

/**
 * @param {string|null|undefined} key
 * @returns {StreakBadge|null}
 */
export function streakBadge(key) {
  return STREAK_BADGES.find(b => b.key === key) ?? null
}

/**
 * "1 jour", "12 jours"
 *
 * @param {number} n
 */
export function days(n) {
  return `${n} jour${n > 1 ? 's' : ''}`
}

/**
 * Today's date in a timezone (Y-m-d), the way the server counts the local day.
 *
 * @param {string|null|undefined} timezone IANA; UTC when missing, as on the server
 * @param {Date} [now]
 */
export function localToday(timezone, now = new Date()) {
  const options = { year: 'numeric', month: '2-digit', day: '2-digit' }
  try {
    return new Intl.DateTimeFormat('en-CA', {
      ...options,
      timeZone: timezone || 'UTC'
    }).format(now)
  } catch {
    return new Intl.DateTimeFormat('en-CA', {
      ...options,
      timeZone: 'UTC'
    }).format(now)
  }
}

/**
 * What the professor says on the celebration screen.
 *
 * @param {Pick<StreakNotice, 'streak'|'previousStreak'|'badge'>} notice
 * @param {string|null} [name] the player's display name
 */
export function streakMessage(notice, name = null) {
  const n = notice.streak
  if (n === 1 && notice.previousStreak > 1)
    return `Ta série de ${days(notice.previousStreak)} s’est arrêtée, mais une nouvelle commence aujourd’hui. Reviens demain pour la faire grandir !`
  if (n === 1)
    return 'Une série est née ! Reviens demain pour un deuxième jour : c’est là que l’habitude commence.'
  if (n === 7)
    return 'Une semaine complète, sans un trou. Les grands joueurs sont faits de semaines comme celle-là.'
  if (n === 30)
    return 'Un mois entier ! Ton œil tactique n’est plus le même qu’au premier jour, je te le garantis.'
  if (n === 100)
    return `Cent jours. Je n’ai rien à ajouter, à part : chapeau bas${name ? `, ${name}` : ''}.`
  if (n === 365)
    return 'Un an, chaque jour. Plus aucun « rooky » ici : te voilà roi de ta propre régularité.'
  const badge = streakBadge(notice.badge)
  if (badge)
    return `${days(n)} d’affilée : le badge « ${badge.name} » est à toi. Continue, le prochain t’attend !`
  return `${days(n)} d’affilée ! Chaque séance compte, même les courtes. Continue comme ça.`
}

/**
 * "Prochain palier : 30 jours · encore 18", or the legend's line past the last badge.
 *
 * @param {number} streak
 * @param {number|null} nextMilestone
 */
export function nextMilestoneText(streak, nextMilestone) {
  return nextMilestone
    ? `Prochain palier : ${days(nextMilestone)} · encore ${nextMilestone - streak}`
    : 'Tu es une légende.'
}

/**
 * Monday-first index (0 to 6) of a Y-m-d day.
 *
 * @param {string} date
 */
export function weekIndex(date) {
  return (new Date(`${date}T12:00:00Z`).getUTCDay() + 6) % 7
}

/**
 * The week row: label, done, today, still to come.
 *
 * @param {boolean[]} week Monday first
 * @param {string} today Y-m-d
 */
export function weekRow(week, today) {
  const t = weekIndex(today)
  return WEEK_DAYS.map((label, i) => ({
    label,
    done: !!week[i],
    today: i === t,
    future: i > t
  }))
}

/** Size of the streak card's flame below the first badge, and what each badge reached adds. */
export const FLAME_BASE = 0.55
export const FLAME_STEP = 0.06

/**
 * How big the streak card's flame is (1 = 132 px): small for less than a week, a little bigger
 * with each streak badge the current streak has reached (3, 7, 14, 30 days… up to 1000).
 *
 * @param {number} streak the current streak
 * @returns {number} a scale, from FLAME_BASE to FLAME_BASE + 12 × FLAME_STEP
 */
export function flameScale(streak) {
  const reached = STREAK_BADGES.filter(b => streak >= b.goal).length
  return Math.round((FLAME_BASE + reached * FLAME_STEP) * 100) / 100
}

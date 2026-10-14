import { t } from '@/i18n'

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
 * @property {boolean[]} [frozenWeek] its days covered by a freeze (docs/SHOP.md)
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
  {
    key: 'streak_3',
    goal: 3,
    get name() {
      return t('gamification.streakBadges.streak_3')
    }
  },
  {
    key: 'on_fire',
    goal: 7,
    get name() {
      return t('gamification.streakBadges.on_fire')
    }
  },
  {
    key: 'streak_14',
    goal: 14,
    get name() {
      return t('gamification.streakBadges.streak_14')
    }
  },
  {
    key: 'unstoppable',
    goal: 30,
    get name() {
      return t('gamification.streakBadges.unstoppable')
    }
  },
  {
    key: 'streak_50',
    goal: 50,
    get name() {
      return t('gamification.streakBadges.streak_50')
    }
  },
  {
    key: 'streak_100',
    goal: 100,
    get name() {
      return t('gamification.streakBadges.streak_100')
    }
  },
  {
    key: 'streak_200',
    goal: 200,
    get name() {
      return t('gamification.streakBadges.streak_200')
    }
  },
  {
    key: 'streak_300',
    goal: 300,
    get name() {
      return t('gamification.streakBadges.streak_300')
    }
  },
  {
    key: 'streak_365',
    goal: 365,
    get name() {
      return t('gamification.streakBadges.streak_365')
    }
  },
  {
    key: 'streak_450',
    goal: 450,
    get name() {
      return t('gamification.streakBadges.streak_450')
    }
  },
  {
    key: 'streak_500',
    goal: 500,
    get name() {
      return t('gamification.streakBadges.streak_500')
    }
  },
  {
    key: 'streak_1000',
    goal: 1000,
    get name() {
      return t('gamification.streakBadges.streak_1000')
    }
  }
]

/** The week row's labels, Monday first. */
/** The days' initials, Monday first, in the current language. */
const weekDays = () => [0, 1, 2, 3, 4, 5, 6].map(i => t(`gamification.weekDays.${i}`))

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
  return t('gamification.days', { n }, n)
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
    return t('gamification.streak.restarted', {
      days: days(notice.previousStreak)
    })
  if (n === 1)
    return t('gamification.streak.born')
  if (n === 7)
    return t('gamification.streak.week')
  if (n === 30)
    return t('gamification.streak.month')
  if (n === 100)
    return name
      ? t('gamification.streak.hundredNamed', { name })
      : t('gamification.streak.hundred')
  if (n === 365)
    return t('gamification.streak.year')
  const badge = streakBadge(notice.badge)
  if (badge)
    return t('gamification.streak.badge', { days: days(n), badge: badge.name })
  return t('gamification.streak.keepGoing', { days: days(n) })
}

/**
 * "Prochain palier : 30 jours · encore 18", or the legend's line past the last badge.
 *
 * @param {number} streak
 * @param {number|null} nextMilestone
 */
export function nextMilestoneText(streak, nextMilestone) {
  return nextMilestone
    ? t('gamification.streak.nextMilestone', {
        days: days(nextMilestone),
        left: nextMilestone - streak
      })
    : t('gamification.streak.legend')
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
 * The week row: label, done, frozen (a freeze kept the streak: an ice ball instead of the flame's
 * tick, docs/SHOP.md), today, still to come.
 *
 * @param {boolean[]} week Monday first
 * @param {string} today Y-m-d
 * @param {boolean[]} [frozen] Monday first
 */
export function weekRow(week, today, frozen = []) {
  const t = weekIndex(today)
  return weekDays().map((label, i) => ({
    label,
    done: !!week[i],
    frozen: !week[i] && !!frozen[i],
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

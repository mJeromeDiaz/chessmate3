import { intlLocale, t } from '@/i18n'

/**
 * Saved sessions (docs/TRAINING.md): their settings and how they read.
 *
 * @typedef {'on_demand'|'daily'|'weekly'} Repetition
 *
 * @typedef {object} SessionSettings
 * @property {Repetition} repetition
 * @property {string} time local "HH:MM" (repeated sessions)
 * @property {number[]} weekdays ISO days, 1 = Monday (daily: the checked days; weekly: one)
 * @property {boolean} public a flag only for now
 * @property {boolean} reminderEnabled
 * @property {('email'|'push')[]} reminderChannels
 * @property {number} reminderMinutes 10, 30, 60 or 1440
 * @property {boolean} calendarEnabled
 *
 * @typedef {SessionSettings & {id: string, title: string, description: string, steps: {module: string, minutes: number, notes: string, settings: Record<string, any>}[], totalMinutes: number, time: string|null, nextAt: string|null, updatedAt: string}} Plan
 */

/**
 * A choice whose label is a message (session.plans.<group>.<value>), read when shown.
 *
 * @template V
 * @param {string} group
 * @param {V} value
 * @returns {{value: V, label: string}}
 */
function option(group, value) {
  return {
    value,
    get label() {
      return t(`session.plans.${group}.${value}`)
    }
  }
}

/** @type {{value: Repetition, label: string}[]} */
export const REPETITIONS = [
  option('repetitions', 'on_demand'),
  option('repetitions', 'daily'),
  option('repetitions', 'weekly')
]

/**
 * A weekday's name in the current language ("lundi", "lun.", "L"; "Monday", "Mon", "M").
 *
 * @param {number} day ISO day, 1 = Monday
 * @param {'long'|'short'|'narrow'} weekday
 * @returns {string}
 */
function dayName(day, weekday) {
  // 1 January 2024 was a Monday.
  return new Intl.DateTimeFormat(intlLocale(), {
    weekday,
    timeZone: 'UTC'
  }).format(new Date(Date.UTC(2024, 0, day)))
}

/** ISO days of the week, Monday first, named in the current language. */
export const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7].map(value => ({
  value,
  get short() {
    return dayName(value, 'narrow')
  },
  get label() {
    return dayName(value, 'long')
  },
  get abbr() {
    return dayName(value, 'short')
  }
}))

export const REMINDER_DELAYS = [10, 30, 60, 1440].map(value =>
  option('delays', value)
)

/** @type {{value: 'email'|'push', label: string}[]} */
export const REMINDER_CHANNELS = [
  option('channels', 'email'),
  option('channels', 'push')
]

/** @returns {SessionSettings} */
export function defaultSettings() {
  return {
    repetition: 'on_demand',
    time: '18:30',
    weekdays: [1, 2, 3, 4, 5, 6, 7],
    public: false,
    reminderEnabled: false,
    reminderChannels: ['email'],
    reminderMinutes: 30,
    calendarEnabled: false
  }
}

/**
 * Stored or received settings made consistent (unknown values back to the defaults).
 *
 * @param {any} value
 * @returns {SessionSettings}
 */
export function normalizeSettings(value) {
  const d = defaultSettings()
  const v = value && typeof value === 'object' ? value : {}
  const days = Array.isArray(v.weekdays)
    ? [
        ...new Set(
          v.weekdays.filter(n => Number.isInteger(n) && n >= 1 && n <= 7)
        )
      ]
    : []
  return {
    repetition: REPETITIONS.some(r => r.value === v.repetition)
      ? v.repetition
      : d.repetition,
    time: /^([01]\d|2[0-3]):[0-5]\d$/.test(v.time ?? '') ? v.time : d.time,
    weekdays: days.length ? days.sort() : d.weekdays,
    public: v.public === true,
    reminderEnabled: v.reminderEnabled === true,
    reminderChannels: Array.isArray(v.reminderChannels)
      ? v.reminderChannels.filter(c => c === 'email' || c === 'push')
      : d.reminderChannels,
    reminderMinutes: REMINDER_DELAYS.some(r => r.value === v.reminderMinutes)
      ? v.reminderMinutes
      : d.reminderMinutes,
    calendarEnabled: v.calendarEnabled === true
  }
}

/**
 * What the API takes: a weekly session keeps one day, an on-demand one no schedule.
 *
 * @param {SessionSettings} s
 * @returns {SessionSettings & {time: string|null}}
 */
export function settingsPayload(s) {
  const onDemand = s.repetition === 'on_demand'
  return {
    ...s,
    time: onDemand ? null : s.time,
    weekdays: onDemand
      ? []
      : s.repetition === 'weekly'
        ? [s.weekdays[0] ?? 1]
        : [...s.weekdays].sort(),
    reminderEnabled: !onDemand && s.reminderEnabled,
    calendarEnabled: !onDemand && s.calendarEnabled
  }
}

/**
 * Why the settings cannot be saved, or null.
 *
 * @param {SessionSettings} s
 * @returns {string|null}
 */
export function settingsIssue(s) {
  if (s.repetition === 'on_demand') return null
  if (!s.weekdays.length) return t('session.plans.issues.noDay')
  if (s.reminderEnabled && !s.reminderChannels.length)
    return t('session.plans.issues.noChannel')
  return null
}

/**
 * "À la demande", "Tous les jours à 18:30", "Lun., mer., ven. à 18:30", "Chaque mardi à 07:00".
 *
 * @param {Pick<SessionSettings, 'repetition'|'weekdays'> & {time: string|null}} s
 * @returns {string}
 */
export function repetitionText(s) {
  if (s.repetition === 'on_demand' || !s.time)
    return t('session.plans.onDemand')
  const time = s.time
  const days = [...s.weekdays].sort()
  if (s.repetition === 'weekly') {
    const day = WEEKDAYS.find(d => d.value === days[0])
    return t('session.plans.weekly', { day: day?.label ?? '?', time })
  }
  if (days.length === 7) return t('session.plans.daily', { time })
  if (days.join() === '1,2,3,4,5') return t('session.plans.weekdays', { time })
  const names = days.map(n => WEEKDAYS.find(d => d.value === n)?.abbr ?? '')
  const text = names.join(', ')
  return t('session.plans.days', {
    days: `${text.charAt(0).toUpperCase()}${text.slice(1)}`,
    time
  })
}

const nextFormat = {
  format: d =>
    new Intl.DateTimeFormat(intlLocale(), {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    }).format(d)
}

/**
 * "Prochaine : mar. 29 sept., 18:30" in the browser's time, or ''.
 *
 * @param {{nextAt: string|null}} plan
 * @returns {string}
 */
export function nextText(plan) {
  return plan.nextAt
    ? t('session.plans.next', {
        date: nextFormat.format(new Date(plan.nextAt))
      })
    : ''
}

/**
 * The .ics file name of a session, from its title ("Matin échecs" → "matin-echecs.ics"), like the
 * server's.
 *
 * @param {string} title
 * @returns {string}
 */
export function icsFileName(title) {
  const slug = title
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return `${slug || 'session'}.ics`
}

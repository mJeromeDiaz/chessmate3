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

export const REPETITIONS = [
  { value: 'on_demand', label: 'À la demande' },
  { value: 'daily', label: 'Quotidienne' },
  { value: 'weekly', label: 'Hebdomadaire' }
]

/** ISO days of the week, Monday first. */
export const WEEKDAYS = [
  { value: 1, short: 'L', label: 'lundi', abbr: 'lun.' },
  { value: 2, short: 'M', label: 'mardi', abbr: 'mar.' },
  { value: 3, short: 'M', label: 'mercredi', abbr: 'mer.' },
  { value: 4, short: 'J', label: 'jeudi', abbr: 'jeu.' },
  { value: 5, short: 'V', label: 'vendredi', abbr: 'ven.' },
  { value: 6, short: 'S', label: 'samedi', abbr: 'sam.' },
  { value: 7, short: 'D', label: 'dimanche', abbr: 'dim.' }
]

export const REMINDER_DELAYS = [
  { value: 10, label: '10 min avant' },
  { value: 30, label: '30 min avant' },
  { value: 60, label: '1 h avant' },
  { value: 1440, label: '1 jour avant' }
]

export const REMINDER_CHANNELS = [
  { value: 'email', label: 'Email' },
  { value: 'push', label: 'Navigateur' }
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
  if (!s.weekdays.length) return 'Choisis au moins un jour.'
  if (s.reminderEnabled && !s.reminderChannels.length)
    return 'Choisis comment être rappelé (email ou navigateur).'
  return null
}

/**
 * "À la demande", "Tous les jours à 18:30", "Lun., mer., ven. à 18:30", "Chaque mardi à 07:00".
 *
 * @param {Pick<SessionSettings, 'repetition'|'weekdays'> & {time: string|null}} s
 * @returns {string}
 */
export function repetitionText(s) {
  if (s.repetition === 'on_demand' || !s.time) return 'À la demande'
  const days = [...s.weekdays].sort()
  if (s.repetition === 'weekly') {
    const day = WEEKDAYS.find(d => d.value === days[0])
    return `Chaque ${day?.label ?? '?'} à ${s.time}`
  }
  if (days.length === 7) return `Tous les jours à ${s.time}`
  if (days.join() === '1,2,3,4,5') return `En semaine à ${s.time}`
  const names = days.map(n => WEEKDAYS.find(d => d.value === n)?.abbr ?? '')
  const text = names.join(', ')
  return `${text.charAt(0).toUpperCase()}${text.slice(1)} à ${s.time}`
}

const nextFormat = new Intl.DateTimeFormat('fr-FR', {
  weekday: 'short',
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit'
})

/**
 * "Prochaine : mar. 29 sept., 18:30" in the browser's time, or ''.
 *
 * @param {{nextAt: string|null}} plan
 * @returns {string}
 */
export function nextText(plan) {
  return plan.nextAt
    ? `Prochaine : ${nextFormat.format(new Date(plan.nextAt))}`
    : ''
}

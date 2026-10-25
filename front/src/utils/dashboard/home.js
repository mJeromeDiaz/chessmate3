import { addDays } from '@/utils/dashboard/days'
import {
  chapterNodes,
  currentStep,
  isBoss,
  pathProgress
} from '@/utils/mindMapPath'
import { partsOf } from '@/utils/shop'
import { STREAK_BADGES, weekIndex } from '@/utils/streak'
import { t } from '@/i18n'

/**
 * The dashboard's cards (design « Dashboard v2 », docs/DASHBOARD.md): the path to propose and its
 * short track, the store's deals, the streak's month and badges. Pure, so that they can be tested
 * alone.
 *
 * @typedef {import('@/utils/mindMapPath').MindPath & {lastPlayedAt?: string|null}} PlayedPath
 * @typedef {import('@/utils/mindMapPath').PathStep} PathStep
 *
 * @typedef {object} PathTrack the proposed path, cut short (chosen 2026-10-25)
 * @property {PathStep|null} previous the step before the current one
 * @property {PathStep} current
 * @property {PathStep|null} next the step after it, unless it is the boss
 * @property {PathStep|null} boss the next boss (a mini-boss or the chapter's), the current one
 *   when the player stands on it
 */

/**
 * The path the dashboard proposes (chosen 2026-10-25): of the paths started and not finished, the
 * one played last; none started, the first one not finished (a new player's invitation); null when
 * all are finished.
 *
 * @param {PlayedPath[]} paths the brain's paths, in the map's order
 * @returns {PlayedPath|null}
 */
export function proposedPath(paths) {
  const open = paths.filter(p => currentStep(p) !== null)
  const started = open
    .filter(p => p.lastPlayedAt || pathProgress(p).done > 0)
    .sort((a, b) => (b.lastPlayedAt ?? '').localeCompare(a.lastPlayedAt ?? ''))
  return started[0] ?? open[0] ?? null
}

/**
 * The steps the path's card draws: the one before, the current one, the next one, then the next
 * boss at the end (chests and hidden bosses aside).
 *
 * @param {import('@/utils/mindMapPath').MindPath} path
 * @returns {PathTrack|null} null when nothing is left to play
 */
export function pathTrack(path) {
  const current = currentStep(path)
  if (!current) return null
  const steps = path.chapters
    .flatMap(chapterNodes)
    .map(n => n.step)
    .filter(s => s.kind !== 'chest' && s.kind !== 'hidden_boss')
  const at = steps.findIndex(s => s.key === current.key)
  const boss = isBoss(current.kind)
    ? current
    : (steps.slice(at + 1).find(s => isBoss(s.kind)) ?? null)
  const after = steps[at + 1] ?? null
  return {
    previous: steps[at - 1] ?? null,
    current,
    next: after && after !== boss && !isBoss(current.kind) ? after : null,
    boss
  }
}

/**
 * A path's progress in percent (its exercises and bosses passed or skipped).
 *
 * @param {import('@/utils/mindMapPath').MindPath} path
 */
export function pathPercent(path) {
  const { done, total } = pathProgress(path)
  return total ? Math.round((100 * done) / total) : 0
}

/** Deals shown on the dashboard. */
export const DEALS = 3

/**
 * The store's deals (chosen 2026-10-25): up to 3 offers on sale with a struck price or an end
 * date, the soonest to end first (then the biggest saving).
 *
 * @param {import('@/utils/shop').ShopOffer[]} offers
 * @param {Date} [now]
 */
export function dealsOf(offers, now = new Date()) {
  const ends = o => (o.endsAt ? Date.parse(o.endsAt) : Infinity)
  const saving = o => (o.wasPrice ? o.wasPrice - o.price : 0)
  return offers
    .filter(
      o =>
        !o.comingSoon &&
        ends(o) > now.getTime() &&
        ((o.wasPrice ?? 0) > o.price || o.endsAt)
    )
    .sort((a, b) => ends(a) - ends(b) || saving(b) - saving(a))
    .slice(0, DEALS)
}

/**
 * What a deal holds, from its real items: « Gel 7 jours + 3 × Aide Puzzle Streak ».
 *
 * @param {import('@/utils/shop').ShopOffer} offer
 */
export function dealContents(offer) {
  return partsOf(offer)
    .map(p => p.label)
    .join(' + ')
}

/**
 * When a deal ends: « Fin dans 2 j », « Fin dans 5 h », « Dernière heure », '' without an end.
 *
 * @param {string|null} endsAt
 * @param {Date} [now]
 */
export function dealEnds(endsAt, now = new Date()) {
  if (!endsAt) return ''
  const hours = (Date.parse(endsAt) - now.getTime()) / 3_600_000
  if (hours < 1) return t('dashboard.shop.endsSoon')
  if (hours < 24) return t('dashboard.shop.endsHours', { n: Math.floor(hours) })
  return t('dashboard.shop.endsDays', { n: Math.floor(hours / 24) })
}

/**
 * @typedef {'played'|'frozen'|'missed'|'none'|'future'} DayState
 *
 * @typedef {object} CalendarCell
 * @property {number|null} day null: a blank before the 1st
 * @property {DayState|null} state
 * @property {boolean} today
 */

/**
 * The streak's month as a Monday-first grid (the summary's `streak.month`).
 *
 * @param {{first: string, days: DayState[]}} month
 * @param {string} today Y-m-d
 * @returns {CalendarCell[]}
 */
export function monthGrid(month, today) {
  if (!month?.first) return []
  /** @type {CalendarCell[]} */
  const cells = Array.from({ length: weekIndex(month.first) }, () => ({
    day: null,
    state: null,
    today: false
  }))
  month.days.forEach((state, i) =>
    cells.push({
      day: i + 1,
      state,
      today: addDays(month.first, i) === today
    })
  )
  return cells
}

/**
 * The days played and frozen in the month.
 *
 * @param {{days: DayState[]}} month
 */
export function monthCounts(month) {
  const days = month?.days ?? []
  return {
    played: days.filter(d => d === 'played').length,
    frozen: days.filter(d => d === 'frozen').length
  }
}

/** A streak badge's chest (docs/SHOP.md, § 4 ter): 10 crystals a day, 1 000 at most. */
export function badgeCrystals(days) {
  return Math.min(10 * days, 1000)
}

/**
 * Four streak badges around the current streak (the design's row): up to two reached, the next
 * one, those after it.
 *
 * @param {number} streak
 * @returns {{goal: number, name: string, state: 'reached'|'next'|'later'}[]}
 */
export function badgeRow(streak) {
  const next = STREAK_BADGES.findIndex(b => b.goal > streak)
  const end =
    next === -1
      ? STREAK_BADGES.length
      : Math.min(STREAK_BADGES.length, Math.max(0, next - 2) + 4)
  return STREAK_BADGES.slice(Math.max(0, end - 4), end).map(b => ({
    goal: b.goal,
    name: b.name,
    state:
      b.goal <= streak
        ? 'reached'
        : b.goal === STREAK_BADGES[next]?.goal
          ? 'next'
          : 'later'
  }))
}

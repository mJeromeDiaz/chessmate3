/**
 * The puzzle streak (docs/PUZZLE_STREAK.md), what the pages show. Pure. The rules come from the API
 * (GET /puzzle-streak), the openings from GET /puzzle-streak/openings.
 *
 * A topic is a key of the API: "general", "theme:<key>" or "opening:<family tag>:<w|b>".
 *
 * @typedef {object} StreakRules
 * @property {{start: number, step: number, max: number}} ladder
 * @property {{start: number, width: number, size: number, last: number}} tiers
 * @property {{key: string, category: string}[]} topics
 * @property {number} minOpeningPuzzles
 * @property {number} singleBudgetSeconds
 * @property {number} recordMin
 *
 * @typedef {object} StreakRecord
 * @property {string} ramp
 * @property {string} topic
 * @property {number} best
 * @property {number} streaks
 * @property {string|null} at
 *
 * @typedef {object} MyOpening
 * @property {string} topic
 * @property {string} tag
 * @property {string} name
 * @property {'white'|'black'} color
 * @property {string[]} sources repertoire, games
 * @property {number} puzzles
 *
 * @typedef {{tag: string, name: string, white: number, black: number}} OpeningFamily
 *
 * @typedef {{mine: MyOpening[], all: OpeningFamily[]}} StreakOpenings
 *
 * @typedef {object} TopicOption
 * @property {string} value the topic's key
 * @property {string} label
 * @property {string} group
 */

import { t } from '@/i18n'

/**
 * Keys to their labels (messages under `prefix`), read when shown.
 *
 * @param {string} prefix
 * @param {string[]} keys
 * @returns {Record<string, string>}
 */
function labels(prefix, keys) {
  return Object.defineProperties(
    {},
    Object.fromEntries(
      keys.map(key => [
        key,
        { get: () => t(`${prefix}.${key}`), enumerable: true }
      ])
    )
  )
}

/** The ramps, as the API names them. */
export const RAMPS = labels('puzzleStreak.ramps', ['ladder', 'tiers'])

/** The general topic: the whole catalogue. */
export const GENERAL = 'general'

/** The topics' categories, in the order offered. */
export const CATEGORIES = labels('puzzleStreak.categories', [
  'endgame',
  'mate',
  'motif'
])

/** The theme topics' labels (keys of `rules.topics`); an unknown one shows its key. */
export const TOPICS = labels('puzzleStreak.topics', [
  'endgame',
  'pawnEndgame',
  'rookEndgame',
  'bishopEndgame',
  'knightEndgame',
  'queenEndgame',
  'queenRookEndgame',
  'mate',
  'mateIn1',
  'mateIn2',
  'mateIn3plus',
  'fork',
  'pin',
  'skewer',
  'discoveredAttack',
  'sacrifice',
  'deflection',
  'attraction',
  'doubleCheck',
  'hangingPiece',
  'trappedPiece'
])

/**
 * A side, from the API's letter or word.
 *
 * @param {string} side w, b, white or black
 */
const sideLabel = side =>
  t(
    `puzzleStreak.sides.${side === 'w' || side === 'white' ? 'white' : 'black'}`
  )

/**
 * How a ramp climbs: "+30 points par puzzle réussi", "5 puzzles par palier de 200 points".
 *
 * @param {string} ramp
 * @param {StreakRules} rules
 */
export function rampText(ramp, rules) {
  if (ramp === 'tiers') return t('puzzleStreak.rampText.tiers', rules.tiers)
  return t('puzzleStreak.rampText.ladder', rules.ladder)
}

/**
 * An opening family's readable name, from the openings when known ("Queens_Pawn_Game" otherwise
 * reads "Queens Pawn Game").
 *
 * @param {string} tag
 * @param {StreakOpenings|null} [openings]
 */
export function familyName(tag, openings = null) {
  return (
    openings?.all.find(f => f.tag === tag)?.name ??
    openings?.mine.find(m => m.tag === tag)?.name ??
    tag.replaceAll('_', ' ')
  )
}

/**
 * A topic for the user: "Général", "Finales de tours", "Italian Game (Blancs)".
 *
 * @param {string} key
 * @param {StreakOpenings|null} [openings]
 */
export function topicLabel(key, openings = null) {
  if (key === GENERAL) return t('puzzleStreak.general')
  if (key.startsWith('theme:')) {
    const theme = key.slice(6)
    return TOPICS[theme] ?? theme
  }
  const match = /^opening:(.+):([wb])$/.exec(key)
  if (match)
    return t('puzzleStreak.withSide', {
      name: familyName(match[1], openings),
      side: sideLabel(match[2])
    })
  return key
}

/**
 * Every topic to choose from, by group: general, the theme categories, the user's openings, then
 * every opening family with enough puzzles for a side (once per side).
 *
 * @param {StreakRules} rules
 * @param {StreakOpenings|null} [openings]
 * @returns {TopicOption[]}
 */
export function topicOptions(rules, openings = null) {
  /** @type {TopicOption[]} */
  const general = t('puzzleStreak.general')
  const options = [{ value: GENERAL, label: general, group: general }]
  for (const [category, group] of Object.entries(CATEGORIES)) {
    for (const topic of rules.topics.filter(t => t.category === category)) {
      options.push({
        value: `theme:${topic.key}`,
        label: TOPICS[topic.key] ?? topic.key,
        group
      })
    }
  }
  for (const mine of openings?.mine ?? []) {
    options.push({
      value: mine.topic,
      label: t('puzzleStreak.withSide', {
        name: mine.name,
        side: sideLabel(mine.color)
      }),
      group: t('puzzleStreak.myOpenings')
    })
  }
  for (const family of openings?.all ?? []) {
    for (const [side, count] of [
      ['w', family.white],
      ['b', family.black]
    ]) {
      if (count >= rules.minOpeningPuzzles)
        options.push({
          value: `opening:${family.tag}:${side}`,
          label: t('puzzleStreak.withSide', {
            name: family.name,
            side: sideLabel(side)
          }),
          group: t('puzzleStreak.allOpenings')
        })
    }
  }
  return options
}

/**
 * The options whose label holds `needle` (case and accents ignored); all of them when it is empty.
 *
 * @param {TopicOption[]} options
 * @param {string} needle
 */
export function filterTopics(options, needle) {
  const fold = (/** @type {string} */ s) =>
    s
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
  const n = fold(needle.trim())
  return n ? options.filter(o => fold(o.label).includes(n)) : options
}

/**
 * The difficulty a streak is at after `solved` puzzles: "≈ 900" on the ladder, "Palier 3 ·
 * 1000–1200" in tiers.
 *
 * @param {string} ramp
 * @param {number} solved
 * @param {StreakRules} rules
 */
export function levelAt(ramp, solved, rules) {
  if (ramp === 'tiers') {
    const index = Math.floor(solved / rules.tiers.size)
    const low = rules.tiers.start + rules.tiers.width * index
    return t('puzzleStreak.tier', {
      n: index + 1,
      range:
        low >= rules.tiers.last
          ? `${rules.tiers.last}+`
          : `${low}–${low + rules.tiers.width}`
    })
  }
  return `≈ ${Math.min(rules.ladder.max, rules.ladder.start + rules.ladder.step * solved)}`
}

/**
 * The user's best streak of a ramp and topic, 0 without one.
 *
 * @param {StreakRecord[]} records
 * @param {string} ramp
 * @param {string} topic
 */
export function recordOf(records, ramp, topic) {
  return records.find(r => r.ramp === ramp && r.topic === topic)?.best ?? 0
}

/**
 * "12 puzzles d’affilée".
 *
 * @param {number} count
 */
export function streakText(count) {
  return t('puzzleStreak.inARow', { n: count }, count)
}

/**
 * The puzzle streak badges (trophies, docs/PUZZLE_STREAK.md): puzzles solved in a row, any ramp
 * and topic, shortest first.
 *
 * @typedef {{key: string, goal: number, name: string}} PuzzleStreakBadge
 * @type {PuzzleStreakBadge[]}
 */
export const PUZZLE_STREAK_BADGES = [10, 20, 30, 50].map(goal => ({
  key: `streak_puzzles_${goal}`,
  goal,
  get name() {
    return t(`puzzleStreak.badges.streak_puzzles_${goal}`)
  }
}))

/**
 * The badges a run won (its summary's `metrics.badges`), shortest first.
 *
 * @param {Record<string, any>|null|undefined} metrics
 * @returns {PuzzleStreakBadge[]}
 */
export function wonBadges(metrics) {
  const keys = metrics?.badges ?? []
  return PUZZLE_STREAK_BADGES.filter(b => keys.includes(b.key))
}

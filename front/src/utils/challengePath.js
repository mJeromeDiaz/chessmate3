import { t } from '@/i18n'

/**
 * The défis (docs/DEFIS.md), Lizy's path: the mind map's path `challenges` as its page shows it.
 * Three fixed chapters (mates, endgames, tactics: five tiers and a boss each), then one chapter
 * per repertoire of the player (`op-<code>`, its repertoire in `meta`): lines, puzzles of its
 * opening, lines, puzzles, lines, then its boss. Nothing is ever locked. Pure, so that it can be
 * tested alone.
 *
 * @typedef {import('@/utils/mindMapPath').PathChapter} PathChapter
 *
 * @typedef {object} OpeningMeta what the API says of an opening chapter's repertoire
 * @property {string} repertoireId
 * @property {string} name
 * @property {'white'|'black'} color
 * @property {string} opening the opening's name ("Sicilian Defense")
 * @property {string} family its catalogue tag
 */

/** The défis' path (the API's capacity). */
export const CHALLENGES = 'challenges'

/** The fixed chapters, in the page's order. */
export const FIXED_CHAPTERS = ['mates', 'endgames', 'tactics']

/** A pass: 10 puzzles (an opening's lines tier: as many lines as its repertoire has, 10 at most). */
export const PASS_COUNT = 10

/** A boss: 10 puzzles, two per tier. */
export const BOSS_COUNT = 10

/** A boss's puzzles: from that far above the player's rating to that far (App\Parcours\ParcoursRules::BOSS_GAPS). */
export const BOSS_GAPS = [100, 300]

/** An opening chapter's tiers that are lines of its repertoire (1-based), the others puzzles. */
const LINE_TIERS = [1, 3, 5]

/** An opening chapter's key, or a step's of one: `op-<26 characters>`. */
const OPENING = /^op-[0-9a-z]{26}(?:-(\d|boss))?$/

/** @param {string} key a chapter's or a step's */
export function isOpening(key) {
  return OPENING.test(key)
}

/**
 * What a step of the défis plays: a fixed chapter's tier (`mates-2`), an opening's lines or
 * puzzles, a boss.
 *
 * @param {string} key
 * @returns {{chapter: string, tier: number|null, kind: 'puzzles'|'lines'|'boss'}}
 */
export function stepPlan(key) {
  const match = /^(.*)-(\d|boss)$/.exec(key)
  const chapter = match?.[1] ?? key
  const tier = match && match[2] !== 'boss' ? Number(match[2]) : null
  if (tier === null) return { chapter, tier, kind: 'boss' }
  return {
    chapter,
    tier,
    kind: isOpening(key) && LINE_TIERS.includes(tier) ? 'lines' : 'puzzles'
  }
}

/**
 * A step's title: "Mat en 2", "Lignes du répertoire", "Puzzles de l'ouverture"; a boss's, its
 * chapter's boss.
 *
 * @param {string} key
 */
export function challengeStepTitle(key) {
  const plan = stepPlan(key)
  if (isOpening(key))
    return t(
      plan.kind === 'boss'
        ? 'challenge.bosses.opening'
        : `challenge.opening.${plan.kind}`
    )
  if (plan.kind === 'boss') return t(`challenge.bosses.${plan.chapter}`)
  return t(`challenge.chapters.${plan.chapter}.steps.s${plan.tier}`)
}

/**
 * What a step asks, Lizy's words.
 *
 * @param {string} key
 */
export function challengeStepDesc(key) {
  const plan = stepPlan(key)
  if (isOpening(key))
    return t(
      plan.kind === 'boss'
        ? 'challenge.detail.openingBossDesc'
        : `challenge.opening.${plan.kind}Desc`
    )
  if (plan.kind === 'boss')
    return t('challenge.detail.bossDesc', {
      chapter: t(`challenge.chapters.${plan.chapter}.name`)
    })
  return t('challenge.detail.stepDesc', {
    title: challengeStepTitle(key)
  })
}

/**
 * A chapter's name: "Mats", or "Ouverture · Mon répertoire" for a repertoire's.
 *
 * @param {PathChapter} chapter
 */
export function challengeChapterName(chapter) {
  if (isOpening(chapter.key)) {
    const meta = /** @type {OpeningMeta|null} */ (chapter.meta ?? null)
    return t('challenge.chapters.opening.name', { name: meta?.name ?? '' })
  }
  return t(`challenge.chapters.${chapter.key}.name`)
}

/**
 * The line under a chapter's name: what its tiers play ("Du mat en 1 au mat en 5"), an opening's
 * repertoire ("Sicilian Defense · Blancs").
 *
 * @param {PathChapter} chapter
 */
export function challengeChapterLead(chapter) {
  if (isOpening(chapter.key)) {
    const meta = /** @type {OpeningMeta|null} */ (chapter.meta ?? null)
    return t('challenge.chapters.opening.lead', {
      opening: meta?.opening ?? '',
      color: t(`challenge.colors.${meta?.color ?? 'white'}`)
    })
  }
  return t(`challenge.chapters.${chapter.key}.lead`)
}

/** @param {import('@/utils/mindMapPath').MindPath} path */
export function hasOpeningChapter(path) {
  return path.chapters.some(c => isOpening(c.key))
}

import { t } from '@/i18n'

/**
 * The endgames path (docs/ENDGAMES.md, § 10; design « Parcours echiqueens »): the mind map's path
 * `endgames` as its page shows it. One chapter per mini-parcours (its lessons, then its boss), the
 * Roi des cendres' chapter last (its boss only). Nothing is ever locked. The défis' path
 * (docs/DEFIS.md) is drawn the same way, its tiers as lessons, without a Roi des cendres. Pure, so
 * that it can be tested alone.
 *
 * @typedef {import('@/utils/mindMapPath').MindPath} MindPath
 * @typedef {import('@/utils/mindMapPath').PathChapter} PathChapter
 * @typedef {import('@/utils/mindMapPath').PathStep} PathStep
 *
 * @typedef {'new'|'started'|'known'|'mastered'} LessonState
 */

/** Passes of a lesson that make it « known » (10, the step's `required`, master it). */
export const KNOWN_PASSES = 2

/** The Roi des cendres' chapter: the last one, its boss only. */
export const ASHES = 'ashes'

/** The positions of a lesson's pass, a mini-parcours' boss, the Roi des cendres (as the API). */
export const LESSON_POSITIONS = 5
export const BOSS_POSITIONS = 6
export const ASHES_POSITIONS = 10

/**
 * Where a lesson is: never played, one pass, known (2 passes), mastered (all of them).
 *
 * @param {PathStep} step
 * @returns {LessonState}
 */
export function lessonState(step) {
  if (step.status === 'done' || step.passes >= step.required) return 'mastered'
  if (step.passes >= KNOWN_PASSES) return 'known'
  return step.passes > 0 ? 'started' : 'new'
}

/**
 * A lesson's passes as a fraction of its mastery (its node's ring).
 *
 * @param {PathStep} step
 */
export function lessonProgress(step) {
  return step.required > 0 ? Math.min(1, step.passes / step.required) : 0
}

/**
 * The line under a lesson: « À commencer », « En cours · 1/10 », « Connue · 4/10 », « Maîtrisée ».
 *
 * @param {PathStep} step
 * @param {string} [texts] the messages of its path's page (the défis': `challenge.path`)
 */
export function lessonStatusText(step, texts = 'endgame.path') {
  return t(`${texts}.states.${lessonState(step)}`, {
    n: step.passes,
    total: step.required
  })
}

/**
 * The mode of the lesson's next pass: learn the first time (the textbook position, help allowed),
 * review afterwards (variants, no help).
 *
 * @param {PathStep} step
 * @returns {'learn'|'review'}
 */
export function nextMode(step) {
  return step.passes > 0 ? 'review' : 'learn'
}

/** @param {PathStep} step */
export function bossBeaten(step) {
  return step.status === 'done'
}

/**
 * The mini-parcours (every chapter but the Roi des cendres').
 *
 * @param {MindPath} path
 * @returns {PathChapter[]}
 */
export function miniPaths(path) {
  return path.chapters.filter(c => c.key !== ASHES)
}

/**
 * The Roi des cendres' chapter, null if the API has none.
 *
 * @param {MindPath} path
 * @returns {PathChapter|null}
 */
export function ashesChapter(path) {
  return path.chapters.find(c => c.key === ASHES) ?? null
}

/**
 * A chapter's lessons, in order.
 *
 * @param {PathChapter} chapter
 * @returns {PathStep[]}
 */
export function lessonsOf(chapter) {
  return chapter.sections.flatMap(s => s.steps)
}

/**
 * The step to play next (chosen 2026-10-24): in the page's order, the first lesson not known yet
 * (fewer than 2 passes); a mini-parcours whose lessons are all known offers its boss if it is not
 * beaten; then the Roi des cendres; once everything is known and beaten, the first lesson not
 * mastered. Null when all is done.
 *
 * @param {MindPath} path
 * @returns {PathStep|null}
 */
export function nextStep(path) {
  for (const chapter of miniPaths(path)) {
    const unknown = lessonsOf(chapter).find(s => s.passes < KNOWN_PASSES)
    if (unknown) return unknown
    if (!bossBeaten(chapter.boss)) return chapter.boss
  }
  const ashes = ashesChapter(path)
  if (ashes && !bossBeaten(ashes.boss)) return ashes.boss
  return (
    miniPaths(path)
      .flatMap(lessonsOf)
      .find(s => lessonState(s) !== 'mastered') ?? null
  )
}

/**
 * The path's totals: lessons mastered, bosses beaten (the Roi des cendres among them).
 *
 * @param {MindPath} path
 */
export function pathTotals(path) {
  const lessons = miniPaths(path).flatMap(lessonsOf)
  const bosses = path.chapters.map(c => c.boss)
  return {
    mastered: lessons.filter(s => lessonState(s) === 'mastered').length,
    lessons: lessons.length,
    beaten: bosses.filter(bossBeaten).length,
    bosses: bosses.length
  }
}

/**
 * A mini-parcours' count: its lessons mastered and its boss beaten, out of all of them.
 *
 * @param {PathChapter} chapter
 */
export function chapterTotals(chapter) {
  const lessons = lessonsOf(chapter)
  return {
    done:
      lessons.filter(s => lessonState(s) === 'mastered').length +
      (bossBeaten(chapter.boss) ? 1 : 0),
    total: lessons.length + 1
  }
}

/**
 * The chapter of a step, null if it is not on the path.
 *
 * @param {MindPath} path
 * @param {string} key
 * @returns {PathChapter|null}
 */
export function chapterOf(path, key) {
  return (
    path.chapters.find(
      c => c.boss.key === key || lessonsOf(c).some(s => s.key === key)
    ) ?? null
  )
}

/** @param {string} key a chapter's */
export function chapterName(key) {
  return t(`mindMapPath.endgames.chapters.${key}`)
}

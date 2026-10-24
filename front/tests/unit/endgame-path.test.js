import { describe, expect, it } from 'vitest'
import {
  ashesChapter,
  chapterOf,
  chapterTotals,
  lessonProgress,
  lessonState,
  lessonStatusText,
  lessonsOf,
  miniPaths,
  nextMode,
  nextStep,
  pathTotals
} from '@/utils/endgamePath'

/** A lesson's step as the API sends it (10 passes master it). */
const lesson = (key, passes = 0) => ({
  key,
  number: null,
  kind: 'exercise',
  status: passes >= 10 ? 'done' : 'open',
  passes,
  required: 10,
  crystals: 0,
  xp: 15,
  character: null,
  badges: [],
  skippable: false,
  chest: null
})

/** A boss's step. */
const boss = (key, beaten = false, character = null) => ({
  ...lesson(key),
  kind: 'boss',
  status: beaten ? 'done' : 'open',
  passes: beaten ? 1 : 0,
  required: 1,
  crystals: 120,
  xp: 60,
  character
})

/** A mini-parcours: its lessons, then its boss. */
const chapter = (key, number, lessons, beaten = false) => ({
  key,
  number,
  status: 'open',
  sections: [
    {
      key,
      number: null,
      status: 'open',
      steps: lessons,
      miniBosses: [],
      hidden: []
    }
  ],
  boss: boss(`${key}-boss`, beaten, 'bourreau')
})

/**
 * The path: mates (kqk, krk), pawns (opposition, square), then the Roi des cendres.
 *
 * @param {Record<string, number>} passes by lesson
 * @param {string[]} beaten bosses' chapters
 */
function path(passes = {}, beaten = []) {
  const l = key => lesson(key, passes[key] ?? 0)
  return {
    capacity: 'endgames',
    chapters: [
      chapter('mates', 1, [l('kqk'), l('krk')], beaten.includes('mates')),
      chapter(
        'pawns',
        2,
        [l('opposition'), l('square')],
        beaten.includes('pawns')
      ),
      {
        key: 'ashes',
        number: 3,
        status: 'open',
        sections: [],
        boss: boss('ashes-boss', beaten.includes('ashes'), 'roi-cendres')
      }
    ],
    badges: [],
    unopenedChests: []
  }
}

describe('endgames path', () => {
  it('tells where a lesson is: to start, started, known at 2 passes, mastered at 10', () => {
    expect(lessonState(lesson('kqk', 0))).toBe('new')
    expect(lessonState(lesson('kqk', 1))).toBe('started')
    expect(lessonState(lesson('kqk', 2))).toBe('known')
    expect(lessonState(lesson('kqk', 9))).toBe('known')
    expect(lessonState(lesson('kqk', 10))).toBe('mastered')
    expect(lessonProgress(lesson('kqk', 4))).toBe(0.4)
    expect(lessonProgress(lesson('kqk', 12))).toBe(1)
    expect(lessonStatusText(lesson('kqk', 0))).toBe('À commencer')
    expect(lessonStatusText(lesson('kqk', 1))).toBe('En cours · 1/10')
    expect(lessonStatusText(lesson('kqk', 4))).toBe('Connue · 4/10')
    expect(lessonStatusText(lesson('kqk', 10))).toBe('Maîtrisée')
  })

  it('learns a lesson the first time, reviews it afterwards', () => {
    expect(nextMode(lesson('kqk', 0))).toBe('learn')
    expect(nextMode(lesson('kqk', 1))).toBe('review')
  })

  it('splits the mini-parcours from the Roi des cendres', () => {
    const p = path()
    expect(miniPaths(p).map(c => c.key)).toEqual(['mates', 'pawns'])
    expect(ashesChapter(p)?.boss.key).toBe('ashes-boss')
    expect(lessonsOf(p.chapters[1]).map(s => s.key)).toEqual([
      'opposition',
      'square'
    ])
    expect(chapterOf(p, 'square')?.key).toBe('pawns')
    expect(chapterOf(p, 'mates-boss')?.key).toBe('mates')
    expect(chapterOf(p, 'ashes-boss')?.key).toBe('ashes')
    expect(chapterOf(p, 'nope')).toBeNull()
  })

  it('offers the first lesson not known yet, in the page’s order', () => {
    expect(nextStep(path())?.key).toBe('kqk')
    // Known but not mastered: the next lesson, not ten passes of the same one.
    expect(nextStep(path({ kqk: 2 }))?.key).toBe('krk')
    // A lesson played once only stays the one to play.
    expect(nextStep(path({ kqk: 1, krk: 5 }))?.key).toBe('kqk')
  })

  it('offers a mini-parcours’ boss once its lessons are all known, then the next one’s lessons', () => {
    expect(nextStep(path({ kqk: 2, krk: 3 }))?.key).toBe('mates-boss')
    expect(nextStep(path({ kqk: 2, krk: 3 }, ['mates']))?.key).toBe(
      'opposition'
    )
    // A later mini-parcours already known does not jump ahead of an earlier one.
    expect(nextStep(path({ kqk: 2, opposition: 2, square: 2 }))?.key).toBe(
      'krk'
    )
  })

  it('offers the Roi des cendres once every boss is beaten, then the lessons to master', () => {
    const known = { kqk: 2, krk: 2, opposition: 2, square: 2 }
    expect(nextStep(path(known, ['mates', 'pawns']))?.key).toBe('ashes-boss')
    expect(nextStep(path(known, ['mates', 'pawns', 'ashes']))?.key).toBe('kqk')
    expect(
      nextStep(path({ ...known, kqk: 10 }, ['mates', 'pawns', 'ashes']))?.key
    ).toBe('krk')
    const all = { kqk: 10, krk: 10, opposition: 10, square: 10 }
    expect(nextStep(path(all, ['mates', 'pawns', 'ashes']))).toBeNull()
  })

  it('counts the lessons mastered and the bosses beaten', () => {
    const p = path({ kqk: 10, krk: 4, square: 10 }, ['mates'])
    expect(pathTotals(p)).toEqual({
      mastered: 2,
      lessons: 4,
      beaten: 1,
      bosses: 3
    })
    expect(chapterTotals(p.chapters[0])).toEqual({ done: 2, total: 3 })
    expect(chapterTotals(p.chapters[1])).toEqual({ done: 1, total: 3 })
  })
})

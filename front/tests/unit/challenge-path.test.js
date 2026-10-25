import { describe, expect, it } from 'vitest'
import {
  CHALLENGES,
  challengeChapterLead,
  challengeChapterName,
  challengeStepDesc,
  challengeStepTitle,
  hasOpeningChapter,
  isOpening,
  stepPlan
} from '@/utils/challengePath'
import { nextStep, pathTotals } from '@/utils/endgamePath'
import {
  bossName,
  chestNote,
  pathChapterName,
  pathPage,
  stepTitle
} from '@/utils/mindMapPath'
import { subjectPath } from '@/utils/training'

/** An opening chapter's code: a repertoire's id in lower-case base32 (26 characters). */
const CODE = '01jabcdefghjkmnpqrstvwxyz0'

/** A step as the API sends it (10 passes master it). */
const step = (key, passes = 0) => ({
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

/** A chapter: its five tiers, then its boss. */
const chapter = (key, passes = [0, 0, 0, 0, 0], meta = null) => ({
  key,
  number: 1,
  status: 'open',
  sections: [
    {
      key,
      number: null,
      status: 'open',
      steps: passes.map((p, i) => step(`${key}-${i + 1}`, p)),
      miniBosses: [],
      hidden: []
    }
  ],
  boss: {
    ...step(`${key}-boss`),
    kind: 'boss',
    required: 1,
    crystals: 150,
    xp: 60,
    character: `defi-${key.startsWith('op-') ? 'opening' : key}`
  },
  meta
})

const SICILIAN = {
  repertoireId: 'r1',
  name: 'Mon Open Sicilien',
  color: 'black',
  opening: 'Sicilian Defense',
  family: 'Sicilian_Defense'
}

describe('the défis’ steps', () => {
  it('tells an opening chapter’s keys from the fixed ones', () => {
    expect(isOpening(`op-${CODE}`)).toBe(true)
    expect(isOpening(`op-${CODE}-3`)).toBe(true)
    expect(isOpening(`op-${CODE}-boss`)).toBe(true)
    expect(isOpening('mates-3')).toBe(false)
    expect(isOpening('op-short-1')).toBe(false)
  })

  it('reads what a step plays: tiers, lines on an opening’s odd tiers, bosses', () => {
    expect(stepPlan('tactics-4')).toEqual({
      chapter: 'tactics',
      tier: 4,
      kind: 'puzzles'
    })
    expect(stepPlan(`op-${CODE}-3`)).toEqual({
      chapter: `op-${CODE}`,
      tier: 3,
      kind: 'lines'
    })
    expect(stepPlan(`op-${CODE}-2`).kind).toBe('puzzles')
    expect(stepPlan('mates-boss')).toEqual({
      chapter: 'mates',
      tier: null,
      kind: 'boss'
    })
  })

  it('names the steps, the chapters and the bosses', () => {
    expect(challengeStepTitle('mates-2')).toBe('Mat en 2')
    expect(challengeStepTitle('tactics-4')).toBe('Attaque à la découverte')
    expect(challengeStepTitle(`op-${CODE}-1`)).toBe('Lignes du répertoire')
    expect(challengeStepTitle(`op-${CODE}-4`)).toBe('Puzzles de l’ouverture')
    expect(challengeStepTitle('endgames-boss')).toBe('Le boss des finales')
    expect(stepTitle(CHALLENGES, 'endgames-1')).toBe('Finales de pions')
    expect(challengeStepDesc('mates-3')).toContain('Mat en 3')
    expect(challengeStepDesc('tactics-boss')).toContain('Tactique')

    expect(challengeChapterName(chapter('mates'))).toBe('Mats')
    const opening = chapter(`op-${CODE}`, undefined, SICILIAN)
    expect(pathChapterName(CHALLENGES, opening)).toBe(
      'Ouverture · Mon Open Sicilien'
    )
    expect(challengeChapterLead(opening)).toBe(
      'Sicilian Defense · avec les Noirs'
    )
    expect(challengeChapterLead(chapter('mates'))).toBe(
      'Du mat en 1 au mat en 5.'
    )
    expect(bossName(opening.boss)).toBe('Le Portier')
  })

  it('advises the first step not known, then the chapter’s boss', () => {
    const path = {
      capacity: CHALLENGES,
      chapters: [chapter('mates', [2, 2, 2, 2, 2]), chapter('endgames')],
      badges: [],
      unopenedChests: []
    }
    expect(nextStep(path)?.key).toBe('mates-boss')
    expect(pathTotals(path)).toEqual({
      mastered: 0,
      lessons: 10,
      beaten: 0,
      bosses: 2
    })
    expect(hasOpeningChapter(path)).toBe(false)
    path.chapters.push(chapter(`op-${CODE}`, undefined, SICILIAN))
    expect(hasOpeningChapter(path)).toBe(true)
  })

  it('goes back to its page, its chests from the défis', () => {
    expect(pathPage(CHALLENGES)).toBe('/defis')
    expect(
      chestNote(CHALLENGES, {
        id: 'c',
        step: 'mates-boss',
        crystals: 150,
        opened: false
      })
    ).toBe('Défis · Le boss des mats')
    expect(
      subjectPath({ subjectType: 'parcours_player', subjectId: 'u' })
    ).toBe('/defis')
  })
})

import { describe, expect, it } from 'vitest'
import fr from '@/i18n/fr/mindMapPath.json'
import {
  apiCapacity,
  badgeName,
  bossImage,
  bossLine,
  bossName,
  chapterNodes,
  chapterProgress,
  chestReward,
  currentStep,
  findPlace,
  findStep,
  hasPath,
  livesLeft,
  mapKey,
  messageKey,
  moodImage,
  nodeOffset,
  outcomeKey,
  pathMood,
  pathPage,
  pathProgress,
  poseCrop,
  refusalText,
  sectionTitle,
  standingImage,
  stepTitle
} from '@/utils/mindMapPath'

/** A step as the API sends it. */
const step = (key, kind = 'exercise', status = 'locked', extra = {}) => ({
  key,
  number: null,
  kind,
  status,
  passes: 0,
  required: kind === 'exercise' ? 2 : 1,
  crystals: 0,
  xp: 15,
  character: null,
  badges: [],
  skippable: false,
  chest: null,
  ...extra
})

const path = {
  capacity: 'visualisation',
  chapters: [
    {
      key: 'c1',
      number: 1,
      status: 'open',
      sections: [
        {
          key: '1',
          number: null,
          status: 'open',
          steps: [
            step('1-1', 'exercise', 'done'),
            step('1-2', 'exercise', 'skipped'),
            step('1-chest', 'chest', 'locked'),
            step('1-3', 'exercise', 'soon'),
            step('1-4', 'exercise', 'locked')
          ],
          miniBosses: [],
          hidden: []
        }
      ],
      boss: step('1-boss', 'boss', 'locked', { character: 'jongleur' })
    },
    {
      key: 'c2',
      number: 2,
      status: 'locked',
      sections: [
        {
          key: '2-1',
          number: '2.1',
          status: 'locked',
          steps: [step('2-1-1')],
          miniBosses: [step('2-1-boss', 'mini_boss')],
          hidden: [step('2-1-hidden', 'hidden_boss', 'hidden')]
        }
      ],
      boss: step('2-boss', 'boss', 'locked', { character: 'cyclope' })
    }
  ],
  badges: [],
  unopenedChests: []
}

describe('mind map path', () => {
  it('maps the capacities of the map to the API’s, and back to their page', () => {
    expect(apiCapacity('visualization')).toBe('visualisation')
    expect(apiCapacity('calculation')).toBe('calculation')
    expect(apiCapacity('speed')).toBeNull()
    expect(hasPath('visualization')).toBe(true)
    expect(hasPath('speed')).toBe(false)
    expect(mapKey('visualisation')).toBe('visualization')
    expect(pathPage('visualisation')).toBe('/cerveau/visualization')
    expect(pathPage('nope')).toBe('/cerveau')
  })

  it('names steps, sections and bosses from their keys', () => {
    expect(messageKey('2-1-3')).toBe('s2_1_3')
    expect(messageKey('5-2-boss-1')).toBe('s5_2_boss_1')
    expect(stepTitle('visualisation', '1-1')).toBe('Cliquer la case')
    expect(sectionTitle('visualisation', path.chapters[1].sections[0])).toBe(
      'Mémoriser les cases et les pièces'
    )
    expect(sectionTitle('visualisation', path.chapters[0].sections[0])).toBe('')
    expect(bossName(path.chapters[0].boss)).toBe('Le Jongleur')
    expect(bossName(step('x', 'mini_boss'))).toBe('Mini-boss')
    expect(bossLine(path.chapters[1].boss, 'taunt')).toContain('regard')
    expect(badgeName('chapter_2')).toBe('Chapitre 2')
    expect(badgeName('section_2_1')).toBe('Section 2.1')
    expect(badgeName('hidden_5_1_2')).toBe('Secret 5.1.2')
    expect(badgeName('boss_fantome')).toBe('Chasseur de fantôme')
  })

  it('has a title and a text for every step it names', () => {
    for (const [key, texts] of Object.entries(fr.visualisation.steps)) {
      expect(key).toMatch(/^s[0-9a-z_]+$/)
      expect(texts.title, key).toBeTruthy()
      expect(texts.desc, key).toBeTruthy()
    }
    for (const character of Object.values(fr.characters))
      expect(Object.keys(character).sort()).toEqual([
        'defeat',
        'name',
        'tagline',
        'taunt',
        'win'
      ])
  })

  it('lays a chapter out: exercises and chests, mini-bosses, hidden bosses, then the boss', () => {
    expect(chapterNodes(path.chapters[1]).map(n => n.step.key)).toEqual([
      '2-1-1',
      '2-1-boss',
      '2-1-hidden',
      '2-boss'
    ])
    expect(chapterNodes(path.chapters[1]).map(n => n.sectionStart)).toEqual([
      true,
      false,
      false,
      false
    ])
    expect(chapterNodes(path.chapters[0]).some(n => n.sectionStart)).toBe(false)
    // The path winds by the place in the section; the bosses stand in the middle.
    const nodes = chapterNodes(path.chapters[1])
    expect(nodes.map(n => n.index)).toEqual([0, 1, 2, 0])
    expect(nodes.map(nodeOffset)).toEqual([-50, 0, 0, 0])
    const second = { ...nodes[0], index: 1 }
    expect(nodeOffset(second)).toBe(30)
    expect(nodeOffset({ ...nodes[0], index: 8 })).toBe(90)
  })

  it('finds what comes next and counts a chapter’s progress', () => {
    expect(currentStep(path)?.key).toBe('1-3')
    const open = structuredClone(path)
    open.chapters[0].sections[0].steps[4].status = 'open'
    expect(currentStep(open)?.key).toBe('1-4')
    expect(chapterProgress(path.chapters[0])).toEqual({ done: 2, total: 5 })
    // The whole path: chapter 2's exercise, mini-boss and boss too (its hidden boss aside).
    expect(pathProgress(path)).toEqual({ done: 2, total: 8 })
    expect(findStep(path, '2-1-boss')?.kind).toBe('mini_boss')
    expect(findStep(path, 'nope')).toBeNull()
    const place = findPlace(path, '2-1-boss')
    expect([place?.chapter.key, place?.section.key]).toEqual(['c2', '2-1'])
    // The chapter's boss: in its last section.
    expect(findPlace(path, '2-boss')?.section.key).toBe('2-1')
    expect(findPlace(path, 'nope')).toBeNull()
  })

  it('gives a boss its face on the path: proud when it waits, sad once beaten', () => {
    expect(pathMood(step('b', 'boss', 'open'))).toBe('pride')
    expect(pathMood(step('b', 'boss', 'done'))).toBe('sadness')
    expect(pathMood(step('b', 'boss', 'locked'))).toBe('neutral')
    expect(pathMood(step('b', 'boss', 'soon'))).toBe('neutral')
  })

  it('reads a run’s verdict', () => {
    const verdict = (kind, outcome, completed = false) => ({
      kind,
      outcome,
      completed
    })
    expect(outcomeKey(verdict('exercise', 'pending'))).toBe('pending')
    expect(outcomeKey(verdict('exercise', 'passed'))).toBe('pass')
    expect(outcomeKey(verdict('exercise', 'passed', true))).toBe('validated')
    expect(outcomeKey(verdict('exercise', 'failed'))).toBe('failed')
    expect(outcomeKey(verdict('boss', 'passed', true))).toBe('victory')
    expect(outcomeKey(verdict('mini_boss', 'passed'))).toBe('practice')
    expect(outcomeKey(verdict('boss', 'failed'))).toBe('defeat')
    expect(livesLeft({ itemCount: 7, successCount: 5, lives: 3 })).toBe(1)
    expect(livesLeft({ itemCount: 9, successCount: 4, lives: null })).toBe(0)
    // A recall boss: its pieces wrong or missing, not its positions.
    expect(
      livesLeft({ itemCount: 3, successCount: 2, failures: 2, lives: 3 })
    ).toBe(1)
  })

  it('says why a step was refused', () => {
    const refusal = (status, detail) => ({
      response: { status, data: { detail } }
    })
    expect(refusalText(refusal(409, 'step_locked'))).toBe(
      'Cette étape n’est pas encore ouverte.'
    )
    expect(refusalText(refusal(409, 'step_not_skippable'))).toBe(
      'Un boss ne se passe pas.'
    )
    expect(
      refusalText(refusal(409, 'Another training run is in progress.'))
    ).toBe('Une autre séance est en cours : termine-la d’abord.')
    expect(
      refusalText(
        refusal(
          409,
          'No repertoire of 10 segments (no_repertoire) (subject_unavailable)'
        )
      )
    ).toBe(
      'Il te faut un répertoire d’au moins 10 tronçons : crée-le dans « Répertoires ».'
    )
    expect(refusalText(refusal(500, 'boom'))).toBe('')
  })

  it('draws the bosses by their expressions when they have them, the others from a sheet', () => {
    expect(moodImage('metronome', 'joy')).toContain('metronome-joie.png')
    expect(moodImage('gargouille', 'anxiety')).toContain(
      'gargouille-angoisse.png'
    )
    expect(standingImage('metronome')).toContain('metronome-pose.png')
    expect(moodImage('jongleur', 'joy')).toContain('jongleur-joie.png')
    expect(moodImage('cyclope', 'joy')).toContain('cyclope-joie.png')
    expect(moodImage('fantome', 'joy')).toBe('')
    expect(standingImage('fantome')).toBe('')
    expect(moodImage(null, 'joy')).toBe('')
    expect(bossImage('metronome')).toContain('cyclope.png')
    expect(fr.characters.metronome.name).toBe('Le Métronome')
  })

  it('crops a pose out of the character sheet and hands a chest to the chest screen', () => {
    expect(poseCrop('angry')).toEqual({
      aspectRatio: '440 / 440',
      width: `${(1536 / 440) * 100}%`,
      left: `${(-1095 / 440) * 100}%`,
      top: `${(-110 / 440) * 100}%`
    })
    expect(
      chestReward({ id: 'c', step: '1-chest', crystals: 60, opened: false })
    ).toEqual({
      id: 'c',
      crowns: 60,
      paidMinutes: 0,
      tier: 'perfect',
      capped: false,
      opened: false
    })
  })
})

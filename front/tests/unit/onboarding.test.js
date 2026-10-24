import { beforeEach, describe, expect, it } from 'vitest'
import {
  END_CHOICES,
  VISITOR_ANSWERS_KEY,
  clearVisitorAnswers,
  readVisitorAnswers,
  writeVisitorAnswers,
  GOALS,
  LEVELS,
  MODULES,
  PROF_SCREENS,
  STEPS,
  TOUR_BOSSES,
  SAMPLE_PATH,
  SAMPLE_PATH_CURRENT,
  accentOn,
  chipOf,
  ctaOf,
  defaultEndChoice,
  footOf,
  lineOf,
  poseOf,
  progressOf,
  ratingNote,
  stepIndex,
  kickerOf,
  themeOf,
  titleOf,
  tourBoss,
  tourBossImage,
  tourBossName,
  tourBossPlace,
  tourStepFacts
} from '@/utils/onboarding'
import { chapterNodes } from '@/utils/mindMapPath'

/** @returns {import('@/utils/onboarding').OnboardingAnswers} */
const answers = (overrides = {}) => ({
  level: null,
  goal: null,
  suggestedLevel: null,
  lichess: { linked: false, perf: null, rating: null },
  canSeedRating: true,
  puzzleRating: null,
  ...overrides
})

describe('guided tour', () => {
  it('has a screen for each step and a module for each professor', () => {
    expect(STEPS).toEqual([
      'hello',
      'level',
      'goal',
      'albert',
      'aaron',
      'lizy',
      'ray',
      'bosses',
      'map',
      'end'
    ])
    for (const step of STEPS) {
      expect(titleOf(step)).toHaveLength(2)
      expect(themeOf(step).bg).toMatch(/^#/)
    }
    for (const prof of Object.values(PROF_SCREENS))
      for (const id of prof.modules) expect(MODULES[id]).toBeDefined()
    expect(LEVELS.map(l => [l.id, l.seed])).toEqual([
      ['beginner', 1000],
      ['amateur', 1400],
      ['confirmed', 1800],
      ['expert', 2200],
      ['pro', 2500]
    ])
    expect(GOALS.map(g => g.id)).toEqual([
      'rating',
      'blunders',
      'fun',
      'understanding',
      'kasparov'
    ])
  })

  it('fills the progress bar one professor at a time', () => {
    expect(progressOf('hello').map(s => s.percent)).toEqual([
      100, 0, 0, 0, 0, 0, 0
    ])
    expect(progressOf('aaron').map(s => s.percent)).toEqual([
      100, 100, 100, 50, 0, 0, 0
    ])
    expect(progressOf('aaron')[1]).toMatchObject({ done: true, first: 1 })
    expect(progressOf('end')[3]).toMatchObject({ percent: 100, first: 3 })
    expect(progressOf('map')[4]).toMatchObject({ done: true, first: 7 })
    expect(chipOf('level')).toEqual({ name: 'NIVEAU', count: '2 / 7' })
    expect(chipOf('ray')).toEqual({ name: 'LES PROFS', count: 'PROF 4 / 4' })
    expect(chipOf('bosses')).toEqual({ name: 'LES BOSS', count: '5 / 7' })
  })

  it('reads a stored step back within the tour', () => {
    expect(stepIndex('3')).toBe(3)
    expect(stepIndex('42')).toBe(STEPS.length - 1)
    expect(stepIndex('-2')).toBe(0)
    expect(stepIndex('nope')).toBe(0)
    expect(stepIndex(null)).toBe(0)
  })

  it('makes Albert answer the level and the goal', () => {
    const none = { level: null, goal: null, answers: answers() }
    expect(lineOf('level', none)).toContain('avoue ton niveau')
    expect(lineOf('level', { ...none, level: 'pro' })).toBe(LEVELS[4].line)
    expect(lineOf('level', { ...none, linking: true })).toContain('Lichess')

    const lichess = answers({
      suggestedLevel: 'confirmed',
      lichess: { linked: true, perf: 'rapid', rating: 1642 }
    })
    const suggested = lineOf('level', {
      level: 'confirmed',
      goal: null,
      answers: lichess
    })
    expect(suggested).toMatch(/^1\s642 en rapide, d’après Lichess\. /)
    // Corrected by the player: the level's own line.
    expect(
      lineOf('level', { level: 'expert', goal: null, answers: lichess })
    ).toBe(LEVELS[3].line)

    expect(lineOf('goal', none)).toContain('Un seul choix')
    expect(lineOf('goal', { ...none, goal: 'kasparov' })).toContain('Garry')
    expect(lineOf('ray', none)).toContain('yeux fermés')
    expect(lineOf('end', none)).toContain('tu lieras ton Lichess en chemin')
    expect(
      lineOf('end', {
        ...none,
        answers: answers({
          lichess: { linked: true, perf: null, rating: null }
        })
      })
    ).toContain('je m’occupe du reste')
  })

  it('poses Albert, and shows each professor on their screen', () => {
    expect(poseOf('level', { level: null, goal: null })).toBe('albert-v2-think')
    expect(poseOf('level', { level: 'pro', goal: null })).toBe('albert-v2-wow')
    expect(poseOf('level', { level: 'amateur', goal: null })).toBe(
      'albert-v2-cheer'
    )
    expect(poseOf('goal', { level: null, goal: 'kasparov' })).toBe(
      'albert-v2-wow'
    )
    expect(poseOf('aaron', { level: null, goal: null })).toBe('aaron-wink')
    expect(poseOf('ray', { level: null, goal: null })).toBe('')
    expect(poseOf('end', { level: null, goal: null })).toBe('albert-v2-book')
  })

  it('says what the level does to the puzzle rating', () => {
    expect(ratingNote('expert', answers())).toBe(
      'Tes puzzles classés démarreront vers 2 200. Ensuite, c’est toi qui décides.'
    )
    expect(ratingNote(null, answers())).toBe('')
    expect(
      ratingNote(
        'expert',
        answers({ canSeedRating: false, puzzleRating: 1734 })
      )
    ).toContain('(1 734) est déjà établi')
    expect(ratingNote('expert', null)).toBe('')
  })

  it('labels the main button, and needs a level and a goal', () => {
    const base = { level: null, goal: null, end: 'builder', linked: false }
    expect(ctaOf('hello', base)).toEqual({
      label: 'Enchanté, Albert',
      ok: true
    })
    expect(ctaOf('level', base).ok).toBe(false)
    expect(ctaOf('level', { ...base, level: 'amateur' }).ok).toBe(true)
    expect(
      ctaOf('level', { ...base, level: 'amateur', linking: true }).ok
    ).toBe(false)
    expect(ctaOf('goal', base).ok).toBe(false)
    expect(ctaOf('goal', { ...base, goal: 'fun' }).label).toBe(
      'Voilà mon objectif'
    )
    expect(ctaOf('albert', base).label).toBe('Prof suivant')
    expect(ctaOf('ray', base).label).toBe('Voir les boss')
    expect(ctaOf('bosses', base).label).toBe('Même pas peur')
    expect(ctaOf('map', base).label).toBe('Voir mon plan')
    expect(ctaOf('end', base).label).toBe('C’est parti !')
    // No choice links Lichess first: the session's and the repertoire's pages ask for it.
    for (const choice of END_CHOICES) {
      expect(ctaOf('end', { ...base, end: choice.id }).label).toBe(
        'C’est parti !'
      )
    }
    expect(footOf('lizy')).toContain('Pourquoi ça aide')
    expect(footOf('goal')).toBe('')
  })

  it('starts with the tailored session with Lichess, the mind map without', () => {
    expect(defaultEndChoice(true)).toBe('coach')
    expect(defaultEndChoice(false)).toBe('map')
    expect(END_CHOICES.map(c => [c.id, c.path, c.lichess])).toEqual([
      ['map', '/cerveau', false],
      ['coach', '/session', false],
      ['repertoire', '/repertoire', false],
      ['modules', '/', false]
    ])
  })

  it('takes a bright accent on the dark surface', () => {
    expect(accentOn(themeOf('aaron'), false)).toBe('#0F6BBA')
    expect(accentOn(themeOf('aaron'), true)).toBe('#4FB2FF')
    expect(accentOn(themeOf('ray'), true)).toBe('#C6F432')
    expect(accentOn(MODULES.aveugle, true)).toBe('#B3A1FF')
  })

  it('shows the bosses going round, the last ones in the shadow, never counted', () => {
    const others = TOUR_BOSSES[TOUR_BOSSES.length - 1]
    expect(others).toMatchObject({ id: 'others', character: null })
    expect(tourBoss(TOUR_BOSSES.length)).toBe(TOUR_BOSSES[0])
    expect(tourBoss(-1)).toBe(others)
    const jongleur = tourBoss(0)
    expect(tourBossName(jongleur)).toBe('Le Jongleur')
    expect(tourBossPlace(jongleur)).toBe('VISUALISATION · CH. 1')
    const ashes = TOUR_BOSSES.find(b => b.id === 'roi-cendres')
    expect(ashes && tourBossPlace(ashes)).toBe('FINALES · BOSS FINAL')
    expect(tourBossName(others)).toBe('Et d’autres…')
    expect(tourBossImage(others)).toBe('')
    for (const boss of TOUR_BOSSES.slice(0, -1))
      expect(tourBossImage(boss)).not.toBe('')

    const state = { level: null, goal: null, answers: null }
    expect(lineOf('bosses', { ...state, boss: 0 })).toBe(
      'Trois balles en l’air, et tu regardes encore l’échiquier ?'
    )
    expect(kickerOf('bosses', 0)).toBe('LE JONGLEUR · VISUALISATION · CH. 1')
    expect(themeOf('bosses', 0)).toMatchObject({
      bg: '#2B1630',
      line2: '#F2B640'
    })
    expect(chipOf('bosses').count).not.toMatch(/BOSS/)
  })

  it('draws a real piece of path on the map, opened on the step to play', () => {
    const steps = SAMPLE_PATH.flatMap(chapter =>
      chapterNodes(chapter).map(node => node.step)
    )
    expect(steps.map(s => s.kind)).toEqual(
      expect.arrayContaining(['exercise', 'mini_boss', 'hidden_boss', 'boss'])
    )
    expect(steps.find(s => s.key === SAMPLE_PATH_CURRENT)).toMatchObject({
      status: 'open',
      passes: 2,
      required: 3
    })
    /** @param {string} key */
    const step = key =>
      /** @type {import('@/utils/mindMapPath').PathStep} */ (
        steps.find(s => s.key === key)
      )
    expect(tourStepFacts(step(SAMPLE_PATH_CURRENT))).toEqual([
      'Passages : 2 / 3 à 80 %',
      '+15 XP'
    ])
    expect(tourStepFacts(step('2-1-boss'))).toEqual([
      '3 vies',
      '+80 cristaux',
      '+40 XP',
      '1 badge'
    ])
    expect(tourStepFacts(step('2-boss'))).toEqual([
      '3 vies',
      '+240 cristaux',
      '+100 XP',
      '2 badges'
    ])
    expect(tourStepFacts(step('2-3-hidden'))).toHaveLength(1)
    expect(poseOf('map', { level: null, goal: null })).toBe(
      'albert-v2-pointer'
    )
  })
})

describe('discovery tour (visitors)', () => {
  beforeEach(() => localStorage.clear())

  it('keeps the visitor’s level and goal in the browser, the unknown ones dropped', () => {
    expect(readVisitorAnswers()).toEqual({ level: null, goal: null })
    writeVisitorAnswers({ level: LEVELS[0].id, goal: GOALS[0].id })
    expect(readVisitorAnswers()).toEqual({
      level: LEVELS[0].id,
      goal: GOALS[0].id
    })
    localStorage.setItem(
      VISITOR_ANSWERS_KEY,
      JSON.stringify({ level: 'nope', goal: GOALS[1].id })
    )
    expect(readVisitorAnswers()).toEqual({ level: null, goal: GOALS[1].id })
    localStorage.setItem(VISITOR_ANSWERS_KEY, '{oops')
    expect(readVisitorAnswers()).toEqual({ level: null, goal: null })
    clearVisitorAnswers()
    expect(localStorage.getItem(VISITOR_ANSWERS_KEY)).toBeNull()
  })

  it('ends on the sign-up, without Lichess', () => {
    const state = {
      level: null,
      goal: null,
      end: 'coach',
      linked: false,
      visitor: true
    }
    expect(ctaOf('end', state)).toEqual({ label: 'Créer mon compte', ok: true })
    expect(ctaOf('end', { ...state, visitor: false }).label).not.toBe(
      'Créer mon compte'
    )
    expect(
      lineOf('end', { level: null, goal: null, answers: null, visitor: true })
    ).toContain('Crée ton compte')
    expect(
      lineOf('level', { level: null, goal: null, answers: null, visitor: true })
    ).not.toContain('Lichess')
    expect(footOf('hello', true)).toContain('Sans compte')
    expect(footOf('end', true)).toContain('ce navigateur')
    expect(footOf('end')).toBe('Visite à revoir dans Profil › Visite guidée')
  })
})

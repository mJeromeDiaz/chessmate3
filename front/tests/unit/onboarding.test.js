import { describe, expect, it } from 'vitest'
import {
  END_CHOICES,
  GOALS,
  LEVELS,
  MODULES,
  PROF_SCREENS,
  STEPS,
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
  themeOf,
  titleOf
} from '@/utils/onboarding'

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
    expect(progressOf('hello').map(s => s.percent)).toEqual([100, 0, 0, 0, 0])
    expect(progressOf('aaron').map(s => s.percent)).toEqual([
      100, 100, 100, 50, 0
    ])
    expect(progressOf('aaron')[1]).toMatchObject({ done: true, first: 1 })
    expect(progressOf('end')[3]).toMatchObject({ percent: 100, first: 3 })
    expect(chipOf('level')).toEqual({ name: 'NIVEAU', count: '2 / 5' })
    expect(chipOf('ray')).toEqual({ name: 'LES PROFS', count: 'PROF 4 / 4' })
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
    expect(lineOf('end', none)).toContain('il me faudra ton Lichess')
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
    expect(ctaOf('ray', base).label).toBe('Voir mon plan')
    expect(ctaOf('end', base).label).toBe('C’est parti !')
    expect(ctaOf('end', { ...base, end: 'coach' }).label).toBe(
      'Lier Lichess et continuer'
    )
    expect(ctaOf('end', { ...base, end: 'coach', linked: true }).label).toBe(
      'C’est parti !'
    )
    expect(footOf('lizy')).toContain('Pourquoi ça aide')
    expect(footOf('goal')).toBe('')
  })

  it('starts with the tailored session when Lichess is linked', () => {
    expect(defaultEndChoice(true)).toBe('coach')
    expect(defaultEndChoice(false)).toBe('builder')
    expect(END_CHOICES.map(c => [c.id, c.path, c.lichess])).toEqual([
      ['coach', '/session/auto', true],
      ['repertoire', '/repertoire/auto', true],
      ['builder', '/session/new', false]
    ])
  })

  it('takes a bright accent on the dark surface', () => {
    expect(accentOn(themeOf('aaron'), false)).toBe('#0F6BBA')
    expect(accentOn(themeOf('aaron'), true)).toBe('#4FB2FF')
    expect(accentOn(themeOf('ray'), true)).toBe('#C6F432')
    expect(accentOn(MODULES.aveugle, true)).toBe('#B3A1FF')
  })
})

import { describe, expect, it } from 'vitest'
import { fromStep } from '@/utils/session/catalog'
import { catalogModule } from '@/utils/runEnd'
import { subjectPath } from '@/utils/training'
import {
  parcoursScore,
  ratingZone,
  stepPlan,
  tierName,
  tierOfRun
} from '@/utils/parcours'

describe('ratingZone', () => {
  it('puts the first third below, the last third above, the middle at the rating', () => {
    const zones = n => Array.from({ length: n }, (_, i) => ratingZone(i, n))
    expect(zones(10)).toEqual([
      'below',
      'below',
      'below',
      'at',
      'at',
      'at',
      'at',
      'above',
      'above',
      'above'
    ])
    expect(zones(6)).toEqual(['below', 'below', 'at', 'at', 'above', 'above'])
    expect(zones(3)).toEqual(['below', 'at', 'above'])
  })
})

describe('tierName', () => {
  it('names a tier by its theme, its lines or its opening puzzles', () => {
    expect(tierName({ kind: 'puzzles', themes: ['mateIn2'] })).toBe('Mat en 2')
    expect(tierName({ kind: 'puzzles', themes: ['discoveredAttack'] })).toBe(
      'Attaque à la découverte'
    )
    expect(tierName({ kind: 'lines', themes: [] })).toBe('Lignes du répertoire')
    expect(tierName({ kind: 'puzzles', themes: [] })).toBe(
      "Puzzles de l'ouverture"
    )
  })

  it('shows an unknown theme as it is', () => {
    expect(tierName({ kind: 'puzzles', themes: ['newTheme'] })).toBe('newTheme')
  })
})

describe('stepPlan', () => {
  it('reads a tier from a step of the parcours session', () => {
    expect(
      stepPlan({ module: 'parcours_puzzles', settings: { themes: ['pin'] } })
    ).toEqual({ kind: 'puzzles', themes: ['pin'] })
    expect(
      stepPlan({ module: 'repertoire', settings: { unit: 'line', limit: 6 } })
    ).toEqual({ kind: 'lines', themes: [] })
    expect(
      stepPlan({ module: 'parcours_puzzles', settings: { family: 'Sicilian' } })
    ).toEqual({ kind: 'puzzles', themes: [] })
  })
})

describe('tierOfRun', () => {
  const session = {
    steps: [
      { index: 0, runId: 'r0' },
      { index: 1, runId: 'r1' },
      { index: 2, runId: null }
    ]
  }

  it('finds the step a run plays', () => {
    expect(tierOfRun(session, 'r1')).toBe(1)
    expect(tierOfRun(session, 'other')).toBe(-1)
    expect(tierOfRun(null, 'r1')).toBe(-1)
  })
})

describe('parcoursScore', () => {
  it('adds the tiers played, of puzzles (count) and of lines (limit)', () => {
    const session = {
      steps: [
        { summary: { itemCount: 6, successCount: 5 }, settings: { count: 6 } },
        { summary: { itemCount: 6, successCount: 6 }, settings: { limit: 6 } },
        { summary: null, settings: { count: 6 } }
      ]
    }
    expect(parcoursScore(session)).toEqual({
      played: 12,
      solved: 11,
      total: 18
    })
  })
})

describe('the parcours module', () => {
  it('belongs to Lizy, back to the défis, never in a hand-made session', () => {
    expect(catalogModule('parcours_puzzles')?.profSlug).toBe('lizy')
    expect(
      subjectPath({ subjectType: 'parcours_player', subjectId: 'u' })
    ).toBe('/defis')
    expect(
      fromStep({
        module: 'parcours_puzzles',
        minutes: 60,
        notes: '',
        settings: { tier: 0, themes: ['mateIn1'], count: 3 }
      })
    ).toBeNull()
  })
})

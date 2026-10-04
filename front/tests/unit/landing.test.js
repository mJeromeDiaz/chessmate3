import { describe, expect, it } from 'vitest'
import {
  EXAMPLE_PROGRAM,
  LANDING_PROFS,
  WOODPECKER_LEGEND,
  WOODPECKER_TONES,
  profRole,
  woodpeckerCells
} from '@/utils/landing/content'

describe('landing content', () => {
  it('fills the solved Woodpecker cells with result tones, the rest with the to-do colour', () => {
    const cells = woodpeckerCells(100, 37, '#000')
    expect(cells).toHaveLength(100)
    const tones = Object.values(WOODPECKER_TONES)
    expect(cells.slice(0, 37).every(c => tones.includes(c))).toBe(true)
    expect(cells.slice(37).every(c => c === '#000')).toBe(true)
    // Every tone shows up, and the grid is the same on each render.
    expect(new Set(cells.slice(0, 37))).toEqual(new Set(tones))
    expect(woodpeckerCells(100, 37, '#000')).toEqual(cells)
  })

  it('has a legend entry per tone plus the to-do cells', () => {
    expect(WOODPECKER_LEGEND.map(l => l.label)).toEqual([
      'Rapide',
      'Réussi',
      'Avec aide',
      'Raté',
      'À faire'
    ])
  })

  it('builds the example session from catalogue modules', () => {
    expect(EXAMPLE_PROGRAM.map(i => i.module?.id)).toEqual([
      'repertoire',
      'puzzles',
      'finales'
    ])
  })

  it('shows the three professors with their speciality', () => {
    expect(LANDING_PROFS.map(p => p.slug)).toEqual([
      'lizy',
      'aaron',
      'albert-stein'
    ])
    expect(LANDING_PROFS.map(profRole)).toEqual([
      'FINALES',
      'RÉPERTOIRE & ÉVALUATION',
      'PUZZLES & WOODPECKER'
    ])
  })
})

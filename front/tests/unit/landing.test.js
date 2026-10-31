import { describe, expect, it } from 'vitest'
import {
  CALCULATION_TRACK,
  EXAMPLE_MINUTES,
  EXAMPLE_PROGRAM,
  HERO_CAST,
  LANDING_BOSSES,
  LANDING_CAPACITIES,
  LANDING_MODULES,
  LANDING_PROFS,
  MODULE_VARIANTS,
  WOODPECKER_LEGEND,
  WOODPECKER_TONES,
  profRole,
  settingLabel,
  variantRows,
  woodpeckerCells
} from '@/utils/landing/content'
import { MODULES_BY_ID } from '@/utils/session/catalog'

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

  it('shows only real settings of real modules', () => {
    for (const v of [...MODULE_VARIANTS, ...EXAMPLE_PROGRAM]) {
      expect(v.module).toBeDefined()
      expect(v.module.available).toBe(true)
      for (const [key, value] of Object.entries(v.values)) {
        if (v.module.id === 'parcours') continue
        const field = v.module.fields.find(f => f.key === key)
        expect(field, `${v.module.id}.${key}`).toBeDefined()
        if (field?.type === 'one') expect(field.options).toContain(value)
        if (field?.type === 'many') {
          for (const x of value) expect(field.options).toContain(x)
        }
        if (field?.type === 'slider') {
          expect(value).toBeGreaterThanOrEqual(
            /** @type {number} */ (field.min)
          )
          expect(value).toBeLessThanOrEqual(/** @type {number} */ (field.max))
        }
      }
      expect(v.meta).not.toMatch(/undefined|session\.catalog|challenge\./)
    }
  })

  it('labels settings as the session builder does', () => {
    const puzzles = MODULES_BY_ID.puzzles
    expect(settingLabel(puzzles, 'duree', 20)).toBe('20 min')
    expect(settingLabel(puzzles, 'themes', ['fork', 'pin'])).toBe(
      'Fourchette, Clouage'
    )
    expect(settingLabel(MODULES_BY_ID.finales, 'elo', 2600)).toBe('Parfait')
    expect(settingLabel(MODULES_BY_ID.evaluation, 'duree', 2)).toBe('2 min')
    expect(settingLabel(MODULES_BY_ID.aveugle, 'longueur', 4)).toBe(
      '4 coups et +'
    )
    expect(settingLabel(MODULES_BY_ID.parcours, 'chapter', 'mates')).toBe(
      'Mats'
    )
    expect(settingLabel(MODULES_BY_ID.parcours, 'step', 'tactics-4')).toBe(
      'Attaque à la découverte'
    )
    expect(() => settingLabel(puzzles, 'nope', 1)).toThrow()
  })

  it('splits the variants into rows of equal length', () => {
    const rows = variantRows(3)
    expect(rows).toHaveLength(3)
    expect(rows.flat()).toEqual(MODULE_VARIANTS)
  })

  it('builds the example session from catalogue modules', () => {
    expect(EXAMPLE_PROGRAM.map(i => i.module.id)).toEqual([
      'aveugle',
      'puzzles',
      'evaluation',
      'finales'
    ])
    // The blindfold is Ray's.
    expect(EXAMPLE_PROGRAM[0].module.profSlug).toBe('ray')
    expect(EXAMPLE_PROGRAM[0].meta).toBe('Moyen · 3 coups')
    expect(EXAMPLE_MINUTES).toBe(45)
  })

  it('lists the modules but the free study, Brillant ou Blunder and Woodpecker first, then those announced', () => {
    const ids = LANDING_MODULES.map(m => m.id)
    expect(ids.slice(0, 4)).toEqual([
      'finales',
      'puzzles',
      'brillant',
      'woodpecker'
    ])
    // The streak and the rush between the blindfold and the recall.
    expect(
      ids.slice(ids.indexOf('aveugle'), ids.indexOf('aveugle') + 4)
    ).toEqual(['aveugle', 'streak', 'rush', 'reconstitution'])
    expect(ids).toContain('parcours')
    expect(ids).not.toContain('libre')
    expect(new Set(ids).size).toBe(ids.length)
    expect(MODULE_VARIANTS.map(v => v.module.id)).not.toContain('libre')
    expect(LANDING_MODULES.filter(m => !m.available).map(m => m.id)).toEqual([
      'analyse',
      'knightJump',
      'pawnStructures',
      'famousGames',
      'timeManagement'
    ])
    expect(LANDING_MODULES.find(m => m.id === 'knightJump')?.title).toBe(
      'Bond du cavalier'
    )
    expect(LANDING_MODULES[0].title).toBe('Étude des finales')
    expect(LANDING_MODULES.find(m => m.id === 'repertoire')?.title).toBe(
      'Mon répertoire'
    )
  })

  it('puts the four professors at the bottom of the hero', () => {
    expect(HERO_CAST.map(p => p.name)).toEqual([
      'Lizy',
      'Ray',
      'Aaron',
      'Albert Stein'
    ])
    for (const prof of HERO_CAST) expect(prof.image).not.toBe('')
  })

  it('draws the calculation path’s first chapter, the Roi des cendres at the end', () => {
    expect(CALCULATION_TRACK.map(n => n.key)).toEqual([
      's1_1',
      's1_2',
      's1_chest',
      's1_3',
      's1_4',
      's1_mini',
      's1_boss'
    ])
    expect(CALCULATION_TRACK[0].title).toBe('Le coup évident')
    expect(CALCULATION_TRACK[2].title).toBe('+60 cristaux')
    expect(CALCULATION_TRACK[6].title).toBe('Le Roi des cendres')
    expect(CALCULATION_TRACK[6].image).not.toBe('')
  })

  it('tags the capacities without a path', () => {
    expect(LANDING_CAPACITIES).toHaveLength(6)
    expect(LANDING_CAPACITIES.filter(c => c.available).map(c => c.key)).toEqual(
      expect.arrayContaining(['calculation', 'visualization'])
    )
  })

  it('shows the four professors facing four bosses', () => {
    expect(LANDING_PROFS.map(p => p.slug)).toEqual([
      'lizy',
      'aaron',
      'albert-stein',
      'ray'
    ])
    expect(LANDING_PROFS.map(profRole)).toEqual([
      'FINALES',
      'RÉPERTOIRE & ÉVALUATION',
      'PUZZLES & WOODPECKER',
      'VISUALISATION'
    ])
    expect(LANDING_BOSSES.map(b => b.key)).toEqual([
      'jongleur',
      'requin',
      'gargouille',
      'cavalier'
    ])
    expect(LANDING_BOSSES[3].name).toBe('Cavalier sans tête')
    for (const boss of LANDING_BOSSES) expect(boss.image).not.toBe('')
  })
})

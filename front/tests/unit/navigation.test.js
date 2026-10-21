import { describe, expect, it } from 'vitest'
import {
  DASHBOARD_LINK,
  FREE_TRAINING_LINKS,
  isNavActive
} from '@/utils/layout/navigation'

const link = id => FREE_TRAINING_LINKS.find(l => l.id === id)

describe('FREE_TRAINING_LINKS', () => {
  it('lists the modules played on their own', () => {
    expect(FREE_TRAINING_LINKS.map(l => l.label)).toEqual([
      'Puzzles',
      'Puzzle Rush',
      'Puzzle streak',
      'Woodpecker',
      'Répertoires',
      'Coordonnées',
      'Notation',
      'Jeu à l’aveugle',
      'Reconstitution',
      'Évaluation',
      'Parcours'
    ])
  })
})

describe('isNavActive', () => {
  it('matches the page and its sub-pages', () => {
    expect(isNavActive(link('puzzle'), '/puzzle')).toBe(true)
    expect(isNavActive(link('puzzle'), '/puzzle/themes')).toBe(true)
    expect(isNavActive(link('repertoire'), '/repertoire/12/stats')).toBe(true)
  })

  it('ignores a mere common prefix', () => {
    expect(isNavActive(link('puzzle'), '/puzzles')).toBe(false)
  })

  it('never marks a link that opens a dialog', () => {
    expect(isNavActive(link('rush'), '/puzzle')).toBe(false)
  })

  it('keeps the dashboard for itself and the statistics', () => {
    expect(isNavActive(DASHBOARD_LINK, '/')).toBe(true)
    expect(isNavActive(DASHBOARD_LINK, '/stats')).toBe(true)
    expect(isNavActive(DASHBOARD_LINK, '/puzzle')).toBe(false)
  })
})

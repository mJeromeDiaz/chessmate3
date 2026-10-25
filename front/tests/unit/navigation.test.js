import { describe, expect, it } from 'vitest'
import {
  CHALLENGE_LINKS,
  DASHBOARD_LINK,
  FREE_TRAINING_LINKS,
  FREE_TRAINING_SECTIONS,
  isNavActive
} from '@/utils/layout/navigation'

const link = id => FREE_TRAINING_LINKS.find(l => l.id === id)

describe('FREE_TRAINING_SECTIONS', () => {
  it('lists the modules played on their own, by phase of the game', () => {
    expect(
      FREE_TRAINING_SECTIONS.map(({ label, links }) => [
        label,
        links.map(l => l.label)
      ])
    ).toEqual([
      ['Les ouvertures', ['Répertoires']],
      [
        'Milieu de jeu',
        ['Woodpecker', 'Puzzles', 'Puzzle Rush', 'Puzzle streak']
      ],
      ['Fin de jeu', ['Finales']],
      [
        'Vision et réflexion',
        [
          'Coordonnées',
          'Notation',
          'Jeu à l’aveugle',
          'Reconstitution',
          'Évaluation'
        ]
      ]
    ])
    expect(FREE_TRAINING_LINKS).toHaveLength(11)
  })

  it('sends the endgames to their free mode, the défis to the card', () => {
    expect(link('endgames').to).toBe('/finales/libre')
    expect(link('challenges')).toBeUndefined()
    expect(CHALLENGE_LINKS.map(l => [l.label, l.to])).toEqual([
      ['Finales', '/finales'],
      ['Défis', '/defis']
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

  it('keeps an exact link to its own page', () => {
    const [path] = CHALLENGE_LINKS
    expect(isNavActive(path, '/finales')).toBe(true)
    expect(isNavActive(path, '/finales/libre')).toBe(false)
    expect(isNavActive(link('endgames'), '/finales')).toBe(false)
  })

  it('keeps the dashboard for itself and the statistics', () => {
    expect(isNavActive(DASHBOARD_LINK, '/')).toBe(true)
    expect(isNavActive(DASHBOARD_LINK, '/stats')).toBe(true)
    expect(isNavActive(DASHBOARD_LINK, '/puzzle')).toBe(false)
  })
})

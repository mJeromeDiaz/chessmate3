import { describe, expect, it } from 'vitest'
import { NAV_LINKS, isNavActive } from '@/utils/layout/navigation'

const link = to => NAV_LINKS.find(l => l.to === to)

describe('NAV_LINKS', () => {
  it('lists the pages, home only in the drawer (the logo in the header)', () => {
    expect(NAV_LINKS.map(l => l.label)).toEqual([
      'Accueil',
      'Sessions',
      'Puzzles',
      'Woodpecker',
      'Répertoires',
      'Coordonnées',
      'Aveugle'
    ])
    expect(NAV_LINKS.filter(l => l.header).map(l => l.to)).toEqual([
      '/session',
      '/puzzle',
      '/woodpecker',
      '/repertoire',
      '/coordinates',
      '/blindfold'
    ])
  })
})

describe('isNavActive', () => {
  it('matches the page and its sub-pages', () => {
    expect(isNavActive(link('/puzzle'), '/puzzle')).toBe(true)
    expect(isNavActive(link('/puzzle'), '/puzzle/themes')).toBe(true)
    expect(isNavActive(link('/repertoire'), '/repertoire/12/edit')).toBe(true)
  })

  it('ignores a mere common prefix', () => {
    expect(isNavActive(link('/puzzle'), '/puzzles')).toBe(false)
    expect(isNavActive(link('/session'), '/sessions')).toBe(false)
  })

  it('keeps home for itself and the statistics', () => {
    expect(isNavActive(link('/'), '/')).toBe(true)
    expect(isNavActive(link('/'), '/stats')).toBe(true)
    expect(isNavActive(link('/'), '/puzzle')).toBe(false)
  })
})

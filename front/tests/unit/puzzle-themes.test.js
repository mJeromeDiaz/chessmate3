import { describe, expect, it } from 'vitest'
import { filtersActive, groupThemes } from '@/utils/puzzle'

/** @param {string} key @param {string} category */
const theme = (key, category) => ({
  key,
  category,
  categoryLabelFr: category.toUpperCase(),
  labelFr: key,
  descriptionFr: '',
  puzzleCount: 1
})

describe('groupThemes', () => {
  it('groups the themes by category in the API order', () => {
    const groups = groupThemes([
      theme('fork', 'motif'),
      theme('mateIn1', 'goal'),
      theme('pin', 'motif')
    ])

    expect(groups.map(g => [g.category, g.label])).toEqual([
      ['motif', 'MOTIF'],
      ['goal', 'GOAL']
    ])
    expect(groups[0].themes.map(t => t.key)).toEqual(['fork', 'pin'])
  })

  it('returns no group without themes', () => {
    expect(groupThemes([])).toEqual([])
  })
})

describe('filtersActive', () => {
  it('is off with the default filters only', () => {
    expect(filtersActive({ themes: [], difficulty: 'normal' })).toBe(false)
    expect(filtersActive({ themes: ['fork'], difficulty: 'normal' })).toBe(true)
    expect(filtersActive({ themes: [], difficulty: 'harder' })).toBe(true)
  })
})

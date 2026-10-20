import { describe, expect, it } from 'vitest'
import {
  CAPACITIES,
  LEFT,
  MODULES,
  OUTLINE,
  RIGHT,
  capacity,
  capacityModules,
  capacityName,
  closedCurve,
  moduleName,
  moduleWhy,
  zoneMask
} from '@/utils/mindMap'

describe('mind map', () => {
  it('has six capacities, numbered, three on each side of the brain', () => {
    expect(CAPACITIES.map(c => c.num)).toEqual([1, 2, 3, 4, 5, 6])
    expect([...LEFT, ...RIGHT].sort()).toEqual(
      CAPACITIES.map(c => c.key).sort()
    )
    expect(capacity('strategy')?.num).toBe(5)
    expect(capacity('nope')).toBeNull()
    expect(capacityName('calculation')).toBe('La profondeur de calcul')
  })

  it('lists each capacity’s modules, main ones first, each with a page and a reason', () => {
    expect(capacityModules(capacity('visualization'))).toEqual([
      { key: 'coordinates', primary: true, to: '/coordinates' },
      { key: 'blindfold', primary: true, to: '/blindfold' },
      { key: 'recall', primary: true, to: '/reconstitution' },
      { key: 'notation', primary: false, to: '/notation' },
      { key: 'repertoire', primary: false, to: '/repertoire' }
    ])
    for (const c of CAPACITIES) {
      expect(c.primary.length).toBeGreaterThan(0)
      for (const m of capacityModules(c)) {
        expect(MODULES[m.key]).toBe(m.to)
        expect(moduleName(m.key)).not.toContain('mindMap.')
        expect(moduleWhy(m.key)).not.toContain('mindMap.')
      }
    }
  })

  it('draws the zones as masks and the outline as a closed curve', () => {
    expect(zoneMask('M0,0 L1,1 Z')).toMatch(/^url\("data:image\/svg\+xml,/)
    expect(decodeURIComponent(zoneMask('M0,0 L1,1 Z'))).toContain(
      "viewBox='0 0 1374 1145'"
    )
    expect(OUTLINE.startsWith('M112.0,560.0 C')).toBe(true)
    expect(OUTLINE.endsWith(' Z')).toBe(true)
    // One cubic per point, back to the start.
    expect(
      closedCurve([
        [0, 0],
        [10, 0],
        [10, 10]
      ]).match(/ C/g)
    ).toHaveLength(3)
  })
})

import { describe, expect, it } from 'vitest'
import {
  CAPACITIES,
  LEFT,
  OUTLINE,
  RIGHT,
  capacity,
  capacityName,
  closedCurve,
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

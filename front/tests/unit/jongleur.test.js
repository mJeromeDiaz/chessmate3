import { describe, expect, it } from 'vitest'
import { distractionOf, jongleurVerdict } from '@/utils/jongleur'

describe('jongleur', () => {
  it('draws a distraction for about one question in three, the same after a reload', () => {
    const ids = Array.from(
      { length: 600 },
      (_, i) =>
        `0199a${i.toString(16).padStart(3, '0')}-7c2e-7000-8000-00000000${i}`
    )
    const drawn = ids.map(distractionOf)
    expect(new Set(drawn)).toEqual(new Set(['shake', 'drift', null]))
    const share = drawn.filter(Boolean).length / ids.length
    expect(share).toBeGreaterThan(0.2)
    expect(share).toBeLessThan(0.5)
    expect(ids.map(distractionOf)).toEqual(drawn)
  })

  it('says the verdict with the right answer, in the language of the UI', () => {
    expect(
      jongleurVerdict('find', 'e4', { status: 'right', expected: 'e4' })
    ).toBe('Juste !')
    expect(
      jongleurVerdict('name', 'e4', { status: 'wrong', expected: 'e4' })
    ).toBe('Raté : e4')
    expect(
      jongleurVerdict('color', 'e4', { status: 'wrong', expected: 'light' })
    ).toBe('Raté : e4 est claire')
    expect(
      jongleurVerdict('write', undefined, {
        status: 'late',
        expected: { uci: 'g1f3', san: 'Nf3' }
      })
    ).toBe('Trop tard ! Cf3')
  })
})

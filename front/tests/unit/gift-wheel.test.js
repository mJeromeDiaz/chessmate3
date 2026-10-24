import { describe, expect, it } from 'vitest'
import {
  PRIZE_CROWNS,
  REST_ROTATION,
  SEGMENTS,
  SEGMENT_DEG,
  countUpMs,
  landingRotation,
  segmentOf,
  spinCurve,
  wheelGradient,
  wheelHead
} from '@/utils/giftWheel'

/**
 * The segment under the pointer (at the top) for a rotation.
 *
 * @param {number} rotation
 */
const under = rotation =>
  Math.floor((((360 - (rotation % 360)) % 360) + 360) % 360 / SEGMENT_DEG) %
  SEGMENTS.length

describe('the gift wheel (docs/SHOP.md, § 4 ter)', () => {
  it('has the design segments', () => {
    expect(SEGMENTS.map(s => s.prize)).toEqual([
      'c5',
      'lose',
      'c5',
      'gift',
      'c5',
      'c30',
      'c5',
      'c300'
    ])
    expect(PRIZE_CROWNS).toEqual({ c5: 5, c30: 30, c300: 300, gift: 0, lose: 0 })
    expect(under(REST_ROTATION)).toBe(0)
    expect(wheelGradient()).toMatch(/^conic-gradient\(#221D30 0deg 45deg,#0B0910 45deg 90deg,/)
  })

  it('stops on a segment of the prize the server drew', () => {
    expect(segmentOf('c300')).toBe(7)
    expect(segmentOf('gift')).toBe(3)
    expect(segmentOf('lose')).toBe(1)
    expect(segmentOf('c5', () => 0)).toBe(0)
    expect(segmentOf('c5', () => 0.99)).toBe(6)

    for (const from of [REST_ROTATION, 1234.5, -50])
      for (const index of [0, 3, 5, 7])
        for (const jitter of [-8, 0, 8]) {
          const to = landingRotation(from, index, 5, jitter)
          expect(under(to)).toBe(index)
          // Six whole turns at least for a 5-second spin.
          expect(to - from).toBeGreaterThanOrEqual(360 * 6)
        }
  })

  it('eases the spin and ends exactly on its stop', () => {
    const to = landingRotation(REST_ROTATION, 3, 5, 2)
    const curve = spinCurve(REST_ROTATION, to, 5, 2)
    expect(curve.duration).toBe(5000)
    expect(curve.at(0)).toBeCloseTo(REST_ROTATION, 6)
    expect(curve.at(curve.duration)).toBe(to)
    let last = curve.at(0)
    for (let ms = 100; ms <= curve.duration; ms += 100) {
      const now = curve.at(ms)
      expect(now).toBeGreaterThanOrEqual(last - 1e-6)
      last = now
    }
    // The final 1.8 s crawl from 10° before the last peg to the stop.
    expect(to - curve.at(curve.duration - 1800)).toBeCloseTo(
      10 + SEGMENT_DEG / 2 - 2,
      6
    )
  })

  it('counts up quicker for the jackpot', () => {
    expect([countUpMs(5), countUpMs(30), countUpMs(300)]).toEqual([2000, 1800, 1400])
  })

  it('titles each stage', () => {
    expect(wheelHead('wheel')).toEqual([
      'Roue des cadeaux',
      'Lance la roue : cristaux, cadeau surprise… ou tête de mort.'
    ])
    expect(wheelHead('chests', { picked: null })[0]).toBe('Choisis un coffre')
    expect(wheelHead('chests', { picked: 1, hits: 1 })).toEqual([
      'Encore !',
      'Plus que 2 coups.'
    ])
    expect(wheelHead('chests', { picked: 1, hits: 2 })[1]).toBe('Un dernier coup !')
    expect(wheelHead('chests', { picked: 1, hits: 3, open: true })).toEqual([
      'Il s’ouvre !',
      ''
    ])
    expect(wheelHead('gain', { gain: 30 })[0]).toBe('+30 cristaux')
    expect(wheelHead('gain', { gain: 300 })[0]).toBe('Jackpot !')
    expect(wheelHead('gift', { gift: 'freeze' })[1]).toBe(
      'Le coffre renfermait un gel de série.'
    )
    expect(wheelHead('gift', { gift: 'costume' })[1]).toBe(
      'Le coffre renfermait une surprise pour Albert.'
    )
  })
})

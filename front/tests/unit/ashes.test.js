import { describe, expect, it } from 'vitest'
import {
  ashSquares,
  clockLabel,
  decoyMove,
  drawEffect,
  effectsOf,
  LIGHTNING_GAP_MS,
  nextShower,
  rainDrops,
  rainSplashes,
  remainingMs,
  SEND_AFTER_MS,
  sendDelayMs,
  squareBox
} from '@/utils/ashes'

/** Chance always landing on the effect at `index` of `count`. */
const pick = (index, count) => () => (index + 0.5) / count

describe('the Roi des cendres', () => {
  it('adds distractions tier by tier', () => {
    expect(effectsOf(null)).toEqual([])
    expect(effectsOf(1)).toEqual(['shake', 'drift', 'thunder'])
    expect(effectsOf(2)).toEqual(
      expect.arrayContaining(['lightning', 'parasite', 'decoy'])
    )
    expect(effectsOf(2)).not.toContain('flip')
    expect(effectsOf(3)).toEqual(expect.arrayContaining(['ash', 'flip']))
    // Out of range: the nearest tier.
    expect(effectsOf(7)).toEqual(effectsOf(3))
  })

  it('keeps only what neither moves nor flashes when the system asks for less motion', () => {
    expect(effectsOf(3, true)).toEqual(['thunder', 'parasite', 'decoy', 'ash'])
    expect(effectsOf(1, true)).toEqual(['thunder'])
  })

  it('draws an effect, never two lightnings within 4 seconds', () => {
    const lightning = pick(3, effectsOf(2).length)
    expect(drawEffect(2, { now: 10_000, random: lightning })).toBe('lightning')
    expect(
      drawEffect(2, { now: 10_000, lastLightningAt: 8000, random: lightning })
    ).toBeNull()
    expect(
      drawEffect(2, {
        now: 8000 + LIGHTNING_GAP_MS,
        lastLightningAt: 8000,
        random: lightning
      })
    ).toBe('lightning')
    expect(drawEffect(null, { now: 0 })).toBeNull()
    expect(drawEffect(1, { now: 0, random: () => 0.999 })).toBe('thunder')
  })

  it('reads the clock by the server’s time', () => {
    const deadline = '2026-10-24T10:01:00.000Z'
    const at = Date.parse('2026-10-24T10:00:00.000Z')
    expect(remainingMs(deadline, 0, at)).toBe(60_000)
    // The server's clock 2 s ahead of this one.
    expect(remainingMs(deadline, 2000, at)).toBe(58_000)
    expect(remainingMs(deadline, 0, at + 90_000)).toBe(0)
    expect(clockLabel(65_000)).toBe('1:05')
    expect(clockLabel(8_200)).toBe('0:09')
    expect(clockLabel(0)).toBe('0:00')
  })

  it('sends a position out of time once the server finds it late', () => {
    const deadline = '2026-10-24T10:01:00.000Z'
    const at = Date.parse(deadline)
    expect(sendDelayMs(deadline, 0, at)).toBe(SEND_AFTER_MS)
    expect(SEND_AFTER_MS).toBeGreaterThan(2000)
    expect(sendDelayMs(deadline, 1000, at)).toBe(SEND_AFTER_MS - 1000)
    expect(sendDelayMs(deadline, 0, at + 10_000)).toBe(0)
  })

  it('places a square on the board as it is shown', () => {
    expect(squareBox('a1', 'white')).toEqual({ left: 0, top: 87.5 })
    expect(squareBox('h8', 'white')).toEqual({ left: 87.5, top: 0 })
    expect(squareBox('a1', 'black')).toEqual({ left: 87.5, top: 0 })
    expect(squareBox('e4', 'black')).toEqual({ left: 37.5, top: 37.5 })
  })

  it('veils one or two pieces with ashes, and draws a legal false arrow', () => {
    const fen = '8/8/8/P7/8/8/5k2/7K w - - 0 1'
    expect(ashSquares(fen, () => 0)).toHaveLength(1)
    const two = ashSquares(fen, () => 0.9)
    expect(two).toHaveLength(2)
    expect(new Set(two).size).toBe(2)
    for (const square of two) expect(['a5', 'f2', 'h1']).toContain(square)
    expect(decoyMove(fen, () => 0)).toEqual(
      expect.objectContaining({ from: expect.any(String) })
    )
    // Mated: no move to draw.
    expect(decoyMove('7k/6Q1/6K1/8/8/8/8/8 b - - 0 1')).toBeNull()
  })

  it('rains in showers, never all along', () => {
    expect(nextShower(true, () => 0)).toEqual({ dryMs: 1000, showerMs: 6000 })
    expect(nextShower(false, () => 1)).toEqual({
      dryMs: 14_000,
      showerMs: 10_000
    })
  })

  it('draws light drops on two planes, and splashes on the board', () => {
    const drops = rainDrops(30, () => 0.5)
    expect(drops).toHaveLength(30)
    expect(drops.filter(d => d.back)).toHaveLength(10)
    const [back, front] = drops
    expect(back.back).toBe(true)
    // The far plane: slower, shorter, fainter.
    expect(back.duration).toBeGreaterThan(front.duration)
    expect(back.length).toBeLessThan(front.length)
    expect(back.opacity).toBeLessThan(front.opacity)
    for (const drop of drops) {
      expect(drop.left).toBeGreaterThanOrEqual(0)
      expect(drop.left).toBeLessThanOrEqual(100)
      expect(drop.opacity).toBeLessThanOrEqual(0.5)
    }
    const splashes = rainSplashes(8, () => 0.99)
    expect(splashes).toHaveLength(8)
    expect(splashes[0].left).toBeLessThan(100)
  })
})

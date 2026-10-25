import { t } from '@/i18n'

/**
 * The gift wheel (design "Roue des cadeaux", docs/SHOP.md, § 4 ter): its segments, where it stops
 * for the prize the server drew, and the words of each stage. Pure.
 *
 * @typedef {'c5'|'c30'|'c300'|'gift'|'lose'} WheelPrize
 *
 * @typedef {object} WheelSpin a spin (API, POST /shop/wheel/spins)
 * @property {string} id
 * @property {WheelPrize} prize
 * @property {'freeze'|'costume'|null} gift
 * @property {number} crowns credited
 * @property {boolean} ticket
 * @property {number} balance after it
 * @property {number} tickets left
 *
 * @typedef {object} Segment
 * @property {WheelPrize} prize
 * @property {string} bg
 */

/** The segments clockwise from the top (design): 45° each. */
export const SEGMENTS = /** @type {Segment[]} */ ([
  { prize: 'c5', bg: '#221D30' },
  { prize: 'lose', bg: '#0B0910' },
  { prize: 'c5', bg: '#2A2440' },
  { prize: 'gift', bg: '#FF8A3D' },
  { prize: 'c5', bg: '#221D30' },
  { prize: 'c30', bg: '#2A2440' },
  { prize: 'c5', bg: '#221D30' },
  { prize: 'c300', bg: '#C6F432' }
])

export const SEGMENT_DEG = 360 / SEGMENTS.length

/** Crystals of each prize. */
export const PRIZE_CROWNS = { c5: 5, c30: 30, c300: 300, gift: 0, lose: 0 }

/** The wheel's rotation at rest: segment 0 under the pointer. */
export const REST_ROTATION = -SEGMENT_DEG / 2

/** The wheel's painted background (a conic gradient of the segments). */
export function wheelGradient() {
  const stops = SEGMENTS.map(
    (s, i) => `${s.bg} ${i * SEGMENT_DEG}deg ${(i + 1) * SEGMENT_DEG}deg`
  )
  return `conic-gradient(${stops.join(',')})`
}

/**
 * A segment of the prize drawn, at random among its own.
 *
 * @param {WheelPrize} prize
 * @param {() => number} [random]
 */
export function segmentOf(prize, random = Math.random) {
  const own = SEGMENTS.map((s, i) => (s.prize === prize ? i : -1)).filter(
    i => i >= 0
  )
  return own[Math.floor(random() * own.length)] ?? 0
}

/**
 * Where the wheel stops: whole turns from $from (about one per second of spin), then the segment
 * $index under the pointer, $jitter degrees off its centre.
 *
 * @param {number} from the rotation now (degrees)
 * @param {number} index
 * @param {number} seconds the spin's length
 * @param {number} [jitter] within ±(SEGMENT_DEG / 2 − a little)
 */
export function landingRotation(from, index, seconds, jitter = 0) {
  const want = 360 - (index * SEGMENT_DEG + SEGMENT_DEG / 2 + jitter)
  const delta = (((want - (from % 360)) % 360) + 360) % 360
  return from + 360 * Math.round(seconds + 1) + delta
}

/**
 * The cubic Hermite curve the design eases the spin with: from 0 to 1, slopes $m0 and $m1.
 *
 * @param {number} s 0 to 1
 * @param {number} m0
 * @param {number} m1
 */
export function hermite(s, m0, m1) {
  return (
    (s * s * s - 2 * s * s + s) * m0 +
    (-2 * s * s * s + 3 * s * s) +
    (s * s * s - s * s) * m1
  )
}

/**
 * The spin's rotation over time (design): fast then slowing down to just before the last peg,
 * then the last 10° crawled over in 1.8 s.
 *
 * @param {number} from
 * @param {number} to {@link landingRotation}
 * @param {number} seconds
 * @param {number} jitter the same as for {@link landingRotation}
 * @returns {{duration: number, at: (ms: number) => number}}
 */
export function spinCurve(from, to, seconds, jitter = 0) {
  const peg = to - (SEGMENT_DEG / 2 - jitter)
  const pre = peg - 10
  const tA = Math.max(1.5, seconds - 1.8) * 1000
  const tB = 1800
  const dA = pre - from
  const dB = to - pre
  const v = dB / tB
  return {
    duration: tA + tB,
    at: ms => {
      if (ms < tA) return from + dA * hermite(ms / tA, 3, (v * tA) / dA)
      if (ms < tA + tB) return pre + dB * hermite((ms - tA) / tB, 1, 0)
      return to
    }
  }
}

/**
 * The count-up's length after a gain (ms): the jackpot quicker and buzzing.
 *
 * @param {number} crowns
 */
export function countUpMs(crowns) {
  return crowns >= 300 ? 1400 : crowns >= 30 ? 1800 : 2000
}

/**
 * The title and line above the wheel for a stage.
 *
 * @param {'wheel'|'chests'|'gift'|'gain'} stage
 * @param {{picked?: number|null, hits?: number, open?: boolean, gain?: number, gift?: 'freeze'|'costume'|null, ticket?: boolean}} [s]
 * @returns {[string, string]}
 */
export function wheelHead(stage, s = {}) {
  if (stage === 'chests') {
    if (s.picked == null)
      return [t('shop.wheel.chests.title'), t('shop.wheel.chests.sub')]
    if (s.open) return [t('shop.wheel.chests.open'), '']
    return [
      t('shop.wheel.chests.again'),
      s.hits === 1 ? t('shop.wheel.chests.two') : t('shop.wheel.chests.one')
    ]
  }
  if (stage === 'gift')
    return s.gift === 'freeze'
      ? [t('shop.wheel.freeze.head'), t('shop.wheel.freeze.headSub')]
      : [t('shop.wheel.costume.head'), t('shop.wheel.costume.headSub')]
  if (stage === 'gain')
    return (s.gain ?? 0) >= 300
      ? [t('shop.wheel.gain.jackpot'), t('shop.wheel.gain.jackpotSub')]
      : [
          t('shop.wheel.gain.title', { n: s.gain ?? 0 }),
          t('shop.wheel.gain.sub')
        ]
  return [
    t('shop.wheel.title'),
    s.ticket ? t('shop.wheel.ticketSub') : t('shop.wheel.sub')
  ]
}

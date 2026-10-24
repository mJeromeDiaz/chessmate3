import { Chess } from 'chess.js'

/**
 * The Roi des cendres' fight (docs/ENDGAMES.md, § 10): its clock per position, held by the server
 * (each position served with its `deadline`), and its distractions by tier (1: positions 1 to 3,
 * 2: 4 to 6, 3: 7 to 10). Pure, so that it can be tested alone; chance is passed in.
 *
 * @typedef {'shake'|'drift'|'thunder'|'lightning'|'parasite'|'decoy'|'ash'|'flip'} AshesEffect
 */

/** The server's tolerance after a deadline (EndgameRules::ASHES_TOLERANCE_SECONDS). */
export const TOLERANCE_MS = 2000
/** A position out of time is sent this long after its deadline: judged late (`timeout`). */
export const SEND_AFTER_MS = TOLERANCE_MS + 500
/** The clock turns red under this. */
export const URGENT_MS = 10_000
/** It ticks under this. */
export const TICKING_MS = 5000

/** Each tier adds its effects to the previous ones'. */
export const TIER_EFFECTS = /** @type {Record<number, AshesEffect[]>} */ ({
  1: ['shake', 'drift', 'thunder'],
  2: ['shake', 'drift', 'thunder', 'lightning', 'parasite', 'decoy'],
  3: [
    'shake',
    'drift',
    'thunder',
    'lightning',
    'parasite',
    'decoy',
    'ash',
    'flip'
  ]
})

/** How often an effect is drawn, by tier (ms). */
export const TIER_EVERY_MS = /** @type {Record<number, number>} */ ({
  1: 4000,
  2: 3000,
  3: 2000
})

/** The effects kept when the system asks for less motion: nothing moves, nothing flashes. */
export const STILL_EFFECTS = /** @type {AshesEffect[]} */ ([
  'thunder',
  'parasite',
  'decoy',
  'ash'
])

/** Photosensitivity: one lightning at most in this long, for everyone. */
export const LIGHTNING_GAP_MS = 4000

/** How long an effect lasts on screen (ms). */
export const EFFECT_MS = /** @type {Record<AshesEffect, number>} */ ({
  shake: 900,
  drift: 3200,
  thunder: 1600,
  lightning: 500,
  parasite: 0,
  decoy: 1500,
  ash: 2000,
  flip: 3000
})

/**
 * The effects a tier may play.
 *
 * @param {number|null} tier
 * @param {boolean} reducedMotion the system asks for less motion
 * @returns {AshesEffect[]}
 */
export function effectsOf(tier, reducedMotion = false) {
  const effects = tier
    ? (TIER_EFFECTS[Math.min(3, Math.max(1, tier))] ?? [])
    : []
  return reducedMotion
    ? effects.filter(e => STILL_EFFECTS.includes(e))
    : effects
}

/**
 * The effect drawn now, among the tier's, none when the lightning drawn came too soon after the
 * last one.
 *
 * @param {number|null} tier
 * @param {{reducedMotion?: boolean, now: number, lastLightningAt?: number|null, random?: () => number}} options
 * @returns {AshesEffect|null}
 */
export function drawEffect(
  tier,
  { reducedMotion = false, now, lastLightningAt = null, random = Math.random }
) {
  const effects = effectsOf(tier, reducedMotion)
  if (!effects.length) return null
  const at = Math.floor(random() * effects.length)
  const effect = effects[Math.min(effects.length - 1, at)]
  if (
    effect === 'lightning' &&
    lastLightningAt !== null &&
    now - lastLightningAt < LIGHTNING_GAP_MS
  )
    return null
  return effect
}

/**
 * The time left on a position's clock, by the server's clock (ms, never below 0).
 *
 * @param {string} deadline ISO 8601, UTC
 * @param {number} offsetMs the server's clock minus this one's
 * @param {number} now this clock (Date.now())
 */
export function remainingMs(deadline, offsetMs, now) {
  return Math.max(0, Date.parse(deadline) - (now + offsetMs))
}

/**
 * How long to wait before sending a position out of time, so that the server finds it late.
 *
 * @param {string} deadline ISO 8601, UTC
 * @param {number} offsetMs
 * @param {number} now
 */
export function sendDelayMs(deadline, offsetMs, now) {
  return Math.max(0, Date.parse(deadline) + SEND_AFTER_MS - (now + offsetMs))
}

/**
 * The clock as it reads: « 1:05 », « 0:09 » (seconds rounded up: 0:00 only once out).
 *
 * @param {number} ms
 */
export function clockLabel(ms) {
  const seconds = Math.ceil(Math.max(0, ms) / 1000)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

const FILES = 'abcdefgh'

/**
 * Where a square stands on the board, in percent of its side from the top left corner.
 *
 * @param {string} square e.g. "e4"
 * @param {'white'|'black'} orientation the side at the bottom
 */
export function squareBox(square, orientation) {
  const file = FILES.indexOf(square[0])
  const rank = Number(square[1]) - 1
  const col = orientation === 'white' ? file : 7 - file
  const row = orientation === 'white' ? 7 - rank : rank
  return { left: col * 12.5, top: row * 12.5 }
}

/**
 * The squares of 1 or 2 pieces to veil with ashes (kings included: any piece may vanish).
 *
 * @param {string} fen
 * @param {() => number} [random]
 * @returns {string[]}
 */
export function ashSquares(fen, random = Math.random) {
  const occupied = new Chess(fen)
    .board()
    .flat()
    .filter(p => p !== null)
    .map(p => /** @type {{square: string}} */ (p).square)
  const count = Math.min(occupied.length, random() < 0.5 ? 1 : 2)
  const chosen = []
  const left = [...occupied]
  for (let i = 0; i < count; i++) {
    const at = Math.min(left.length - 1, Math.floor(random() * left.length))
    chosen.push(left.splice(at, 1)[0])
  }
  return chosen
}

/**
 * A false arrow: a legal move of the side to move, drawn at random (it may even be good).
 *
 * @param {string} fen
 * @param {() => number} [random]
 * @returns {{from: string, to: string}|null}
 */
export function decoyMove(fen, random = Math.random) {
  const moves = new Chess(fen).moves({ verbose: true })
  if (!moves.length) return null
  const at = Math.floor(random() * moves.length)
  const move = moves[Math.min(moves.length - 1, at)]
  return { from: move.from, to: move.to }
}

/** The rain comes in showers (chosen 2026-10-24: never all along), each this long (ms)… */
export const SHOWER_MS = /** @type {const} */ ([6000, 10_000])
/** …after a dry spell this long (ms; the first one shorter). */
export const DRY_MS = /** @type {const} */ ([8000, 14_000])
export const FIRST_DRY_MS = /** @type {const} */ ([1000, 4000])

/**
 * A length drawn between two bounds.
 *
 * @param {readonly [number, number]} range
 * @param {() => number} random
 */
function between([min, max], random) {
  return Math.round(min + random() * (max - min))
}

/**
 * The next shower: the dry spell before it, then its length (ms).
 *
 * @param {boolean} first the position's first one
 * @param {() => number} [random]
 */
export function nextShower(first, random = Math.random) {
  return {
    dryMs: between(first ? FIRST_DRY_MS : DRY_MS, random),
    showerMs: between(SHOWER_MS, random)
  }
}

/**
 * @typedef {object} RainDrop
 * @property {number} left where it falls, % of the board's width
 * @property {number} delay s, so that drops do not fall together
 * @property {number} duration s, to cross the board
 * @property {number} length px
 * @property {number} opacity
 * @property {boolean} back on the far plane: thinner, blurred, slower
 *
 * @typedef {object} RainSplash
 * @property {number} left %
 * @property {number} top %
 * @property {number} delay s
 */

/** @param {number} value */
const round2 = value => Math.round(value * 100) / 100

/**
 * A shower's drops: thin streaks of various lengths, speeds and strengths, a third of them on a
 * far plane; light, so that the board stays readable.
 *
 * @param {number} count
 * @param {() => number} [random]
 * @returns {RainDrop[]}
 */
export function rainDrops(count, random = Math.random) {
  return Array.from({ length: count }, (_, i) => {
    const back = i % 3 === 0
    return {
      left: Math.round(random() * 1000) / 10,
      delay: Math.round(random() * 1000) / 1000,
      duration: round2((back ? 0.9 : 0.55) * (0.8 + random() * 0.4)),
      length: Math.round((back ? 14 : 22) + random() * 14),
      opacity: round2((back ? 0.12 : 0.25) * (1 + random())),
      back
    }
  })
}

/**
 * Small splashes where drops land on the board.
 *
 * @param {number} count
 * @param {() => number} [random]
 * @returns {RainSplash[]}
 */
export function rainSplashes(count, random = Math.random) {
  return Array.from({ length: count }, () => ({
    left: Math.round(random() * 950) / 10,
    top: Math.round(random() * 950) / 10,
    delay: Math.round(random() * 1200) / 1000
  }))
}

import { profImage } from '@/utils/prof/images'
import { MODULE_FAIL_RATE } from '@/utils/sounds'
import { catalogModule } from '@/utils/runEnd'

/**
 * The end-of-run result (designs "Résultat de leçon" and "Corriger ses erreurs", docs/TRAINING.md
 * § 5 quater): which of the three screens, its words and colours, the professor's pose, the
 * buttons, the animation's steps; then the screen that deals the mistakes to correct. Pure, so that
 * it can be tested alone.
 *
 * @typedef {'perfect'|'close'|'fail'|'done'} ResultKind `done`: free study, no score
 *
 * @typedef {'close'|'next'|'fix'} ResultAction close the screen, the next lesson, correct the mistakes
 *
 * @typedef {object} ResultButton
 * @property {string} label
 * @property {ResultAction} action
 */

/** From this share of items solved, "Presque parfait" (below: "Raté total"), as the end sound. */
export const CLOSE_RATE = MODULE_FAIL_RATE

/**
 * Which screen ends the run: perfect (everything solved), close (80 % or more), fail; `done` for
 * free study; null when nothing was played (no screen).
 *
 * @param {{module: string, summary: {itemCount: number, successCount: number}|null}} run
 * @returns {ResultKind|null}
 */
export function resultKind(run) {
  if (run.module === 'free') return 'done'
  const s = run.summary
  if (!s || !s.itemCount) return null
  if (s.successCount >= s.itemCount) return 'perfect'
  return s.successCount >= s.itemCount * CLOSE_RATE ? 'close' : 'fail'
}

/**
 * The share solved, in whole percent rounded down (99.5 % never reads as a perfect 100); free
 * study: its minutes.
 *
 * @param {{module: string, summary: {itemCount: number, successCount: number, durationMs?: number}|null}} run
 */
export function resultScore(run) {
  const s = run.summary
  if (run.module === 'free') return Math.floor((s?.durationMs ?? 0) / 60_000)
  if (!s || !s.itemCount) return 0
  return Math.floor((s.successCount / s.itemCount) * 100 + 1e-9)
}

/** The screens' words and colours (the design's, whatever the theme). */
export const RESULT_SCREENS = {
  perfect: {
    line1: 'Leçon',
    line2: 'parfaite !',
    bg: '#2ED3A8',
    deep: '#0FA582',
    ink: '#0B5E4A',
    accentInk: '#FFFFFF',
    track: '#DAF8EF',
    fill: '#0B5E4A',
    unit: '%',
    caption: 'DE RÉUSSITE'
  },
  close: {
    line1: 'Presque',
    line2: 'parfait !',
    bg: '#FFD43B',
    deep: '#E0AE00',
    ink: '#6B5200',
    accentInk: '#6B5200',
    track: '#FFF5D1',
    fill: '#8A6A00',
    unit: '%',
    caption: 'DE RÉUSSITE'
  },
  fail: {
    line1: 'Raté',
    line2: 'total !',
    bg: '#FF6FAE',
    deep: '#E03C86',
    ink: '#8A1450',
    accentInk: '#FFFFFF',
    track: '#FFE6F1',
    fill: '#B4231A',
    unit: '%',
    caption: 'DE RÉUSSITE'
  },
  done: {
    line1: 'Séance',
    line2: 'terminée !',
    bg: '#2ED3A8',
    deep: '#0FA582',
    ink: '#0B5E4A',
    accentInk: '#FFFFFF',
    track: '#DAF8EF',
    fill: '#0B5E4A',
    unit: 'min',
    caption: 'D’ÉTUDE'
  }
}

/** Confetti of a perfect lesson. */
export const RESULT_CONFETTI = [
  '#1B1530',
  '#FFFFFF',
  '#C6F432',
  '#FFD43B',
  '#8B6BFF',
  '#4FB2FF'
]

/**
 * The professors' poses on these screens (src/assets/profs): one per result, and, on the screen of
 * the mistakes, thinking then pointing at the cards. A professor without a card shows its glyph.
 *
 * @type {Record<string, {perfect: string, close: string, fail: string, think: string, pointer: string}>}
 */
export const RESULT_POSES = {
  'albert-stein': {
    perfect: 'albert-v2-wow',
    close: 'albert-v2-idea',
    fail: 'albert-v2-angry',
    think: 'albert-v2-think',
    pointer: 'albert-v2-pointer'
  },
  aaron: {
    perfect: 'aaron-laugh',
    close: 'aaron-wink',
    fail: 'aaron-cross',
    think: 'aaron-bust',
    pointer: 'aaron-wow'
  },
  lizy: {
    perfect: 'lizy-joy',
    close: 'lizy-cool',
    fail: 'lizy-think',
    think: 'lizy-think',
    pointer: 'lizy-board'
  }
}

/**
 * The run's professor in a pose: its picture ('' without a card: the glyph is shown) and glyph.
 *
 * @param {string} module the API's module
 * @param {ResultKind|'think'|'pointer'} pose
 */
export function resultProf(module, pose) {
  const m = catalogModule(module)
  const poses = m?.profSlug ? RESULT_POSES[m.profSlug] : null
  const name = poses ? poses[pose === 'done' ? 'perfect' : pose] : ''
  return {
    name: m?.prof ?? 'Ton prof',
    image: name ? profImage(name) : '',
    glyph: m?.glyph ?? '♞︎'
  }
}

/**
 * The two buttons of a screen. The mistakes are offered only when some can be played again
 * (`fixable`); otherwise the next lesson takes their place.
 *
 * @param {ResultKind} kind
 * @param {number} fixable items missed that can be played again
 * @returns {{primary: ResultButton, secondary: ResultButton}}
 */
export function resultButtons(kind, fixable) {
  /** @type {ResultButton} */
  const next = { label: 'Leçon suivante →', action: 'next' }
  /** @type {ResultButton} */
  const carryOn = { label: 'Continuer', action: 'close' }
  if (kind === 'close')
    return {
      primary: carryOn,
      secondary: fixable
        ? { label: 'Revoir mes erreurs', action: 'fix' }
        : next
    }
  if (kind === 'fail')
    return fixable
      ? {
          primary: { label: 'Recommencer la leçon', action: 'fix' },
          secondary: { label: 'Plus tard', action: 'close' }
        }
      : { primary: carryOn, secondary: next }
  return { primary: carryOn, secondary: next }
}

/** Ms from the opening at which the result screen's steps 1 to 6 begin. */
export const RESULT_STEPS_MS = [120, 650, 1000, 1600, 3000, 3400]
/** The step from which the score counts up, and how long it takes. */
export const RESULT_COUNT_STEP = 4
export const RESULT_COUNT_MS = 1300

/** Ms from the opening at which the mistakes screen's steps 1 to 6 begin. */
export const FIX_STEPS_MS = [120, 650, 1000, 2000, 2400, 3100]
export const FIX_COUNT_STEP = 5
export const FIX_COUNT_MS = 700

/** "Presque parfait" counts up to this, then back down to the score. */
const CLOSE_PEAK = 96

/**
 * The number shown while the score counts: eased up; "Presque parfait" overshoots to 96 first,
 * as if it were going to be perfect.
 *
 * @param {ResultKind} kind
 * @param {number} score
 * @param {number} k progress, 0 to 1
 */
export function countAt(kind, score, k) {
  const eased = 1 - Math.pow(1 - Math.min(1, Math.max(0, k)), 3)
  if (kind !== 'close' || score >= CLOSE_PEAK) return Math.round(score * eased)
  if (eased < 0.72) return Math.round((eased / 0.72) * CLOSE_PEAK)
  return Math.round(CLOSE_PEAK - (CLOSE_PEAK - score) * ((eased - 0.72) / 0.28))
}

/**
 * The letters of a title line falling into place from step 3 (`offset`: letters before them).
 *
 * @param {string} text
 * @param {number} step
 * @param {number} [offset]
 */
export function fallingLetters(text, step, offset = 0) {
  return text.split('').map((ch, i) => ({
    ch,
    delay: `${(i + offset) * 0.045}s`,
    op: step >= 3 ? 1 : 0,
    tf:
      step >= 3
        ? 'none'
        : `translateY(-90px) rotate(${(i + offset) % 2 ? 14 : -14}deg) scale(.5)`
  }))
}

/** The mistakes screen's title; "erreurs" stands out. */
export const FIX_TITLE = 'Il est temps de corriger ses erreurs !'

/**
 * The title's words falling into place from step 3.
 *
 * @param {number} step
 */
export function fixWords(step) {
  return FIX_TITLE.split(' ').map((t, i) => ({
    t,
    accent: /erreurs/i.test(t),
    delay: `${i * 0.08}s`,
    op: step >= 3 ? 1 : 0,
    tf:
      step >= 3
        ? 'none'
        : `translateY(-70px) rotate(${i % 2 ? 12 : -12}deg) scale(.5)`
  }))
}

/**
 * "6 PUZZLES À REVOIR", in the module's words.
 *
 * @param {string} module the API's module
 * @param {number} count
 * @param {string} [unit] repertoire: segment or line
 */
export function fixLabel(module, count, unit) {
  const s = count > 1 ? 'S' : ''
  if (module === 'repertoire')
    return `${unit === 'line' ? 'LIGNE' : 'TRONÇON'}${s} À REVOIR`
  return `PUZZLE${s} À REVOIR`
}

const CARD_GLYPHS = ['♞︎', '♝︎', '♛︎', '♜︎', '♟︎', '♚︎']
/** Cards dealt at most, whatever the number of mistakes. */
export const MAX_CARDS = 7

/**
 * The cards dealt on the mistakes screen: up to seven, fanned out, each with its item's number.
 *
 * @param {number[]} numbers the items' numbers, in order
 * @param {boolean} dealt
 */
export function fixCards(numbers, dealt) {
  const shown = numbers.slice(0, MAX_CARDS)
  const mid = (shown.length - 1) / 2
  return shown.map((number, i) => {
    const d = i - mid
    const rot = d * 9
    const x = d * (shown.length > 5 ? 40 : 48)
    const y = d * d * 4
    return {
      number,
      glyph: CARD_GLYPHS[i % CARD_GLYPHS.length],
      transform: dealt
        ? `translate(${x}px,${y}px) rotate(${rot}deg)`
        : `translate(${-d * 20}px,420px) rotate(${-rot * 2}deg)`,
      delay: `${i * 0.09}s`,
      liftDelay: `${1 + i * 0.4}s`,
      stampDelay: `${0.55 + i * 0.09}s`
    }
  })
}

/** Whether the user asked for reduced motion: the screens show their last step at once. */
export function prefersReducedMotion() {
  return !!globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches
}

/**
 * The missed items that can be corrected: those the review can play again (a coordinate has
 * nothing to replay).
 *
 * @template {{item: {type: string}, replayable: boolean}} T
 * @param {T[]} missed `missedItems()`
 * @returns {T[]}
 */
export function fixableItems(missed) {
  return missed.filter(m => m.replayable && m.item.type !== 'coordinate')
}

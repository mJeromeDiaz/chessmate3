import { useAuthStore } from '@/stores/auth'

const BASE = `${import.meta.env.BASE_URL ?? '/'}media/son/`

/** The outcome sounds: end of a puzzle, of a repertoire unit, of a module, of a session. */
export const OUTCOME_SOUND_FILES = {
  puzzleDone: `${BASE}puzzleIsDone.mp3`,
  puzzleMissed: `${BASE}puzzleIsMissed.mp3`,
  unitFailed: `${BASE}funnyFail.mp3`,
  moduleDone: `${BASE}success3.mp3`,
  moduleFailed: `${BASE}bigFail.mp3`,
  sessionDone: `${BASE}success.mp3`
}

/** @typedef {keyof typeof OUTCOME_SOUND_FILES} OutcomeSound */

/** Below this share of items succeeded, a module is failed... */
export const MODULE_FAIL_RATE = 0.8
/** ...once it has at least this many items finished. */
export const MODULE_FAIL_MIN_ITEMS = 3

/**
 * The sound of a closed run (a module): failed when too few of its items succeeded, done
 * otherwise; free study has nothing to fail.
 *
 * @param {{module: string, summary: {itemCount: number, successCount: number}|null}} run
 * @returns {OutcomeSound}
 */
export function moduleEndSound(run) {
  const summary = run.summary
  if (
    run.module !== 'free' &&
    // A puzzle streak always ends on a miss: never a failed module.
    run.module !== 'puzzle_streak' &&
    summary &&
    summary.itemCount >= MODULE_FAIL_MIN_ITEMS &&
    summary.successCount < summary.itemCount * MODULE_FAIL_RATE
  ) {
    return 'moduleFailed'
  }

  return 'moduleDone'
}

/** One preloaded element per file, replayed from the start. */
const players = new Map()

/**
 * Plays an outcome sound, when the signed-in user has the sounds on (profile preference; visitors
 * hear nothing). Silent failures: no file, no audio support, autoplay refused.
 *
 * @param {OutcomeSound} name
 */
export function playOutcomeSound(name) {
  if (useAuthStore().profile?.moveSound !== true) return
  const src = OUTCOME_SOUND_FILES[name]
  try {
    let audio = players.get(src)
    if (!audio) {
      audio = new Audio(src)
      players.set(src, audio)
    }
    audio.currentTime = 0
    audio.play()?.catch(() => {})
  } catch {
    // No audio support.
  }
}

const MIND_MAP = `${BASE}mindMap/`

/**
 * The ambience of the mind map bosses (docs/MIND_MAP.md, § 2): every boss's verdicts, the
 * Gargouille's music and cries, the Métronome's theme and ticking.
 */
export const AMBIENT_SOUND_FILES = {
  /** 30 s: its card, before the fight. */
  gargoyleTheme: `${MIND_MAP}halloween_theme.mp3`,
  /** 1 min 43: the fight, looped. */
  gargoyleFight: `${MIND_MAP}halloween_theme_long.mp3`,
  /** A miss, a question late: its cry. */
  wolf: `${MIND_MAP}wolf.mp3`,
  /** The lightning of the strong distractions. */
  thunder: `${MIND_MAP}thunder.mp3`,
  /** The fight starts: the doors open. */
  doors: `${MIND_MAP}doors.mp3`,
  /** The position goes dark. */
  bell: `${MIND_MAP}bell.mp3`,
  /** Under the moves announced, looped. */
  whispers: `${MIND_MAP}whispers.mp3`,
  /** The boss wins: the last life lost. */
  evilLaugh: `${MIND_MAP}evil-laugh.mp3`,
  /** Every boss and mini-boss: an answer right. */
  ding: `${MIND_MAP}ding.mp3`,
  /** Every boss and mini-boss: an answer wrong or late. */
  off: `${MIND_MAP}off.mp3`,
  /** 3 min: the Métronome's card, then its fight, looped. */
  metronomeTheme: `${MIND_MAP}metronome-theme.mp3`,
  /** A few seconds of ticking, looped while a line is thought over. */
  metronomeTick: `${MIND_MAP}metronome-tick.mp3`,
  /** The Jongleur's card, then its fight, looped. */
  jongleurTheme: `${MIND_MAP}jongleur-theme.mp3`,
  /** The Cyclope's card, then its fight, looped. */
  cyclopeTheme: `${MIND_MAP}cyclope-theme.mp3`
}

/** @typedef {keyof typeof AMBIENT_SOUND_FILES} AmbientSound */

/**
 * Plays an ambient sound when the signed-in user has the sounds on, and gives what stops it (a
 * music fades nothing: it stops). Silent failures, as for the outcome sounds.
 *
 * @param {AmbientSound} name
 * @param {{loop?: boolean, volume?: number, rate?: number}} [options] `rate`: its speed (1: as recorded)
 * @returns {() => void} stops it
 */
export function playAmbientSound(
  name,
  { loop = false, volume = 1, rate = 1 } = {}
) {
  if (useAuthStore().profile?.moveSound !== true) return () => {}
  try {
    const audio = new Audio(AMBIENT_SOUND_FILES[name])
    audio.loop = loop
    audio.volume = volume
    audio.playbackRate = rate
    audio.play()?.catch(() => {})
    return () => {
      audio.pause()
      audio.currentTime = 0
    }
  } catch {
    return () => {}
  }
}

/**
 * A boss's or a mini-boss's verdict on an answer (docs/MIND_MAP.md, § 2): the ding, or the « off »
 * (a boss of its own may cry instead: the Gargouille's wolf).
 *
 * @param {boolean} right
 * @param {AmbientSound} [miss]
 */
export function playBossVerdict(right, miss = 'off') {
  playAmbientSound(right ? 'ding' : miss, { volume: 0.8 })
}

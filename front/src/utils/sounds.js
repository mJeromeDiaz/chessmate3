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

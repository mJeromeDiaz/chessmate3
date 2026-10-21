import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import {
  AMBIENT_SOUND_FILES,
  OUTCOME_SOUND_FILES,
  moduleEndSound,
  playAmbientSound,
  playBossVerdict,
  playOutcomeSound
} from '@/utils/sounds'

vi.mock('@/services/api', () => ({ authApi: {}, profileApi: {} }))

/**
 * @param {number} itemCount
 * @param {number} successCount
 * @param {string} [module]
 */
const run = (itemCount, successCount, module = 'puzzles') => ({
  module,
  summary: { itemCount, successCount }
})

describe('moduleEndSound', () => {
  it('fails a module under 80 % succeeded, from 3 items finished', () => {
    expect(moduleEndSound(run(5, 3))).toBe('moduleFailed')
    expect(moduleEndSound(run(3, 2))).toBe('moduleFailed')
    expect(moduleEndSound(run(10, 7, 'repertoire'))).toBe('moduleFailed')
  })

  it('passes a module at 80 % or more', () => {
    expect(moduleEndSound(run(5, 4))).toBe('moduleDone')
    expect(moduleEndSound(run(10, 8))).toBe('moduleDone')
    expect(moduleEndSound(run(3, 3))).toBe('moduleDone')
  })

  it('never fails a module with fewer than 3 items, nor free study', () => {
    expect(moduleEndSound(run(2, 0))).toBe('moduleDone')
    expect(moduleEndSound(run(0, 0))).toBe('moduleDone')
    expect(moduleEndSound(run(5, 0, 'free'))).toBe('moduleDone')
    // Every puzzle streak ends on a miss.
    expect(moduleEndSound(run(4, 1, 'puzzle_streak'))).toBe('moduleDone')
    expect(moduleEndSound({ module: 'puzzles', summary: null })).toBe(
      'moduleDone'
    )
  })
})

describe('playOutcomeSound', () => {
  /** @type {string[]} */
  let played

  beforeEach(() => {
    setActivePinia(createPinia())
    played = []
    vi.stubGlobal(
      'Audio',
      class {
        /** @param {string} src */
        constructor(src) {
          this.src = src
          this.currentTime = 0
        }

        play() {
          played.push(this.src)
          return Promise.resolve()
        }

        pause() {
          played.push(`paused ${this.src}`)
        }
      }
    )
  })

  afterEach(() => vi.unstubAllGlobals())

  it('plays only when the signed-in user has the sounds on', () => {
    const auth = useAuthStore()
    playOutcomeSound('puzzleDone')
    auth.profile = { moveSound: false }
    playOutcomeSound('puzzleDone')
    expect(played).toEqual([])

    auth.profile = { moveSound: true }
    playOutcomeSound('sessionDone')
    playOutcomeSound('sessionDone')
    expect(played).toEqual([
      OUTCOME_SOUND_FILES.sessionDone,
      OUTCOME_SOUND_FILES.sessionDone
    ])
  })
  it('gives every boss its verdicts, the Gargouille its own cry', () => {
    useAuthStore().profile = { moveSound: true }
    playBossVerdict(true)
    playBossVerdict(false)
    playBossVerdict(false, 'wolf')
    expect(played).toEqual([
      AMBIENT_SOUND_FILES.ding,
      AMBIENT_SOUND_FILES.off,
      AMBIENT_SOUND_FILES.wolf
    ])
  })

  it('plays an ambience at its speed, and stops it', () => {
    useAuthStore().profile = { moveSound: true }
    const stop = playAmbientSound('metronomeTick', { loop: true, rate: 1.5 })
    stop()
    expect(played).toEqual([
      AMBIENT_SOUND_FILES.metronomeTick,
      `paused ${AMBIENT_SOUND_FILES.metronomeTick}`
    ])
  })
})

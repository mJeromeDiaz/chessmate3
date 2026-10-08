import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { woodpeckerApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * @typedef {object} CycleView
 * @property {number} number
 * @property {number} run
 * @property {'resting'|'active'|'completed'|'lost'} status
 * @property {number|null} durationDays null for a light round
 * @property {string} availableAt
 * @property {string|null} deadlineAt null for a light round
 * @property {string|null} completedAt
 * @property {number} played
 * @property {number} solved
 * @property {number} failed
 * @property {number|null} accuracy
 * @property {number} activeMs
 * @property {number|null} averageMs
 * @property {number|null} calendarMs
 * @property {boolean|null} onTime
 * @property {number|null} daysLeft
 *
 * @typedef {object} WoodpeckerSet
 * @property {string} id
 * @property {string} name
 * @property {'classic'|'light'} mode
 * @property {'active'|'paused'|'completed'|'abandoned'} status
 * @property {boolean} archived
 * @property {number} puzzleCount grows in light mode
 * @property {number|null} cycleCount null in light mode
 * @property {number|null} firstCycleDays null in light mode
 * @property {number|null} reductionFactor null in light mode
 * @property {number|null} minCycleDays null in light mode
 * @property {number|null} restDays null in light mode
 * @property {boolean} shuffle
 * @property {string|null} completedAt
 * @property {string|null} abandonedAt
 * @property {string} timezone
 * @property {CycleView|null} current
 * @property {CycleView[]} cycles rounds, in light mode
 * @property {{occurredAt: string, round: number, added: number, puzzleCount: number}[]} growths light mode, oldest first
 * @property {{id: string, budgetSeconds: number, startedAt: string, closedAt: string|null, closeReason: string|null, summary: import('@/composables/training/useTimeboxedRun').RunSummary|null}[]} runs closed timed runs, newest first
 *
 * @typedef {object} WoodpeckerAttempt
 * @property {string} id
 * @property {'pending'|'solved'|'failed'} status
 * @property {{id: string, fen: string, moves: string[], playerColor: 'white'|'black', rating: number, gameUrl: string}} puzzle
 * @property {WoodpeckerSet} set
 */

/**
 * Woodpecker state: the user's sets, the set being viewed or played, the current puzzle and the
 * server's verdict on it.
 */
export const useWoodpeckerStore = defineStore('woodpecker', () => {
  /** @type {import('vue').Ref<WoodpeckerSet[]>} */
  const sets = ref([])
  /** @type {import('vue').Ref<WoodpeckerSet|null>} */
  const set = ref(null)
  /** @type {import('vue').Ref<WoodpeckerAttempt|null>} */
  const attempt = ref(null)
  /** @type {import('vue').Ref<WoodpeckerAttempt|null>} The server's answer to the last submission. */
  const result = ref(null)
  /**
   * The cycle run that the last submission completed (for the recap), null otherwise.
   *
   * @type {import('vue').Ref<{completed: CycleView, previous: CycleView|null, setCompleted: boolean}|null>}
   */
  const recap = ref(null)

  // Nothing of a user may remain for the next one in this tab.
  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) reset()
    }
  )

  function reset() {
    sets.value = []
    set.value = null
    attempt.value = null
    result.value = null
    recap.value = null
  }

  /** @param {boolean} [archived] */
  async function fetchSets(archived = false) {
    sets.value = await woodpeckerApi.sets({ archived })
    return sets.value
  }

  /** @param {string} id */
  async function fetchSet(id) {
    set.value = await woodpeckerApi.set(id)
    return set.value
  }

  /** @param {object} payload */
  async function create(payload) {
    set.value = await woodpeckerApi.create(payload)
    return set.value
  }

  /**
   * @param {string} id
   * @param {'pause'|'resume'|'abandon'|'archive'} action
   */
  async function act(id, action) {
    set.value = await woodpeckerApi.act(id, action)
    return set.value
  }

  /** @param {string} setId */
  async function next(setId) {
    result.value = null
    recap.value = null
    attempt.value = await woodpeckerApi.next(setId)
    set.value = attempt.value.set
    return attempt.value
  }

  /**
   * Sends the move log; detects the end of the cycle run (or of the set) for the recap.
   *
   * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
   */
  async function submit(report) {
    if (!attempt.value) throw new Error('No Woodpecker puzzle in progress')
    const before = attempt.value.set.current
    const answer = await woodpeckerApi.submit(attempt.value.id, report)
    result.value = answer
    set.value = answer.set
    recap.value = before ? cycleRecap(answer.set, before) : null
    return answer
  }

  return {
    sets,
    set,
    attempt,
    result,
    recap,
    reset,
    fetchSets,
    fetchSet,
    create,
    act,
    next,
    submit
  }
})

/**
 * If the run that was open before the submission is now completed, its figures and the previous
 * completed cycle's (for the comparison).
 *
 * @param {WoodpeckerSet} set
 * @param {CycleView} before
 */
export function cycleRecap(set, before) {
  const completed = set.cycles.find(
    c =>
      c.number === before.number &&
      c.run === before.run &&
      c.status === 'completed'
  )
  if (!completed) return null
  const previous =
    set.cycles
      .filter(c => c.number === before.number - 1 && c.status === 'completed')
      .at(-1) ?? null
  return { completed, previous, setCompleted: set.status === 'completed' }
}

/**
 * The modes that already have an ongoing (active or paused) set: one per mode at most.
 *
 * @param {WoodpeckerSet[]} sets
 * @returns {Set<string>}
 */
export function ongoingModes(sets) {
  return new Set(
    sets
      .filter(s => s.status === 'active' || s.status === 'paused')
      .map(s => s.mode ?? 'classic')
  )
}

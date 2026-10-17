import { computed, ref, watch } from 'vue'
import { sessionApi } from '@/services/api'
import {
  TIERS,
  parcoursScore,
  stepPlan,
  tierName,
  tierOfRun
} from '@/utils/parcours'

/**
 * The run on screen as a tier of a parcours (docs/PARCOURS.md), when it is one: its session (read
 * when the run starts, then the one the page reads at its end), the tier, what it plays, the
 * exercise in progress out of its count, the whole parcours' score. A tier of lines is a
 * repertoire run: only its session tells it belongs to a parcours.
 *
 * @param {ReturnType<typeof import('@/composables/training/useTimeboxedRun').useTimeboxedRun>} runner
 * @param {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} endSession the
 *   session the page reads once the run is over
 */
export function useParcoursRun(runner, endSession) {
  /** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
  const session = ref(null)

  watch(
    () => runner.run.value?.parentId ?? null,
    async parentId => {
      if (!parentId) {
        session.value = null
        return
      }
      if (session.value?.id === parentId) return
      const read = await sessionApi.get(parentId).catch(() => null)
      if (runner.run.value?.parentId === parentId)
        session.value = read?.parcours ? read : null
    },
    { immediate: true }
  )
  watch(endSession, s => {
    if (s?.parcours) session.value = s
  })

  const active = computed(() => !!session.value)
  /** The tier (0 to 4) the run plays: its step, else the session's current one. */
  const tier = computed(() => {
    const s = session.value
    if (!s) return -1
    const index = tierOfRun(s, runner.run.value?.id)
    return index >= 0 ? index : s.currentIndex
  })
  const step = computed(() => session.value?.steps[tier.value] ?? null)
  /** Exercises of the tier (its puzzles' `count`, its lines' `limit`). */
  const count = computed(
    () => step.value?.settings?.count ?? step.value?.settings?.limit ?? 0
  )
  const name = computed(() =>
    step.value ? tierName(stepPlan(step.value)) : ''
  )

  /** The exercise in progress (1-based): the puzzle's position, or the lines played before. */
  const exercise = computed(() => {
    const data = runner.item.value?.data
    if (!data) return 0
    const done =
      runner.item.value?.type === 'parcours_puzzle'
        ? data.position
        : (data.played ?? 0)
    return Math.min(count.value, Number(done) + 1)
  })
  /** The whole parcours so far: exercises played and solved out of its total. */
  const score = computed(() =>
    session.value ? parcoursScore(session.value) : null
  )

  return {
    session,
    active,
    tier,
    tiers: TIERS,
    count,
    name,
    exercise,
    score
  }
}

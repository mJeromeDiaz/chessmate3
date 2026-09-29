import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { trainingApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/** Durations offered when launching a run, in minutes (plus a custom one). */
export const RUN_DURATIONS = [5, 10, 15, 20, 30]
export const MIN_RUN_MINUTES = 1
export const MAX_RUN_MINUTES = 60

/**
 * The user's active timed run, if any (docs/TRAINING.md): launching one, finding the one in
 * progress (one at a time), ending it. The run being played lives in `useTimeboxedRun`.
 */
export const useTrainingStore = defineStore('training', () => {
  /** @type {import('vue').Ref<import('@/composables/training/useTimeboxedRun').TrainingRun|null>} */
  const current = ref(null)

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) current.value = null
    }
  )

  async function fetchCurrent() {
    current.value = await trainingApi.current()
    return current.value
  }

  /**
   * Starts a run. On a 409, `current` tells whether another run is in progress (the caller offers
   * to resume or end it) or the subject cannot be played now.
   *
   * @param {{module: string, subjectId: string, minutes: number}} options
   */
  async function start({ module, subjectId, minutes }) {
    try {
      current.value = await trainingApi.start({
        module,
        subjectId,
        budgetSeconds: Math.round(minutes * 60)
      })
      return current.value
    } catch (e) {
      if (/** @type {any} */ (e)?.response?.status === 409) {
        await fetchCurrent().catch(() => {})
      }
      throw e
    }
  }

  /** @param {string} id */
  async function stop(id) {
    const run = await trainingApi.stop(id)
    if (current.value?.id === id) current.value = null
    return run
  }

  return { current, fetchCurrent, start, stop }
})

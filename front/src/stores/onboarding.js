import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { onboardingApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePuzzleStore } from '@/stores/puzzle'

/**
 * The guided tour's answers (docs/ONBOARDING.md), shared by the tour and the profile. A saved
 * level may set the starting puzzle rating: the header's rating is read again then.
 */
export const useOnboardingStore = defineStore('onboarding', () => {
  /** @type {import('vue').Ref<import('@/utils/onboarding').OnboardingAnswers|null>} */
  const answers = ref(null)

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) answers.value = null
    }
  )

  /** Reads the answers (again). */
  async function load() {
    answers.value = await onboardingApi.get()
    return answers.value
  }

  /**
   * Saves the answers, when they changed.
   *
   * @param {{level: string|null, goal: string|null}} next
   */
  async function save(next) {
    if (
      answers.value &&
      answers.value.level === next.level &&
      answers.value.goal === next.goal
    )
      return answers.value
    const seeded = answers.value?.canSeedRating && next.level !== null
    answers.value = await onboardingApi.save(next)
    if (seeded)
      usePuzzleStore()
        .fetchRating()
        .catch(() => {})
    return answers.value
  }

  return { answers, load, save }
})

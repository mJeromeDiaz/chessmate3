import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { planApi, sessionApi } from '@/services/api'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { requestNotifications } from '@/utils/alerts'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * Launches a saved session and starts its first module. Another session in progress today: it is
 * offered (`inProgress`) to resume or abandon; a module that cannot be played now: an error.
 */
export function usePlanLaunch() {
  const router = useRouter()
  const step = useSessionStep()
  /** The plan being launched. */
  const launching = ref(/** @type {string|null} */ (null))
  const error = ref('')
  /** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
  const inProgress = ref(null)
  /** The plan refused because of `inProgress`, to launch once that one is abandoned. */
  let pending = /** @type {string|null} */ (null)

  /** @param {string} planId */
  async function launch(planId) {
    // Still in the click: browsers only ask for the permission from a user gesture.
    requestNotifications().catch(() => {})
    launching.value = planId
    error.value = ''
    inProgress.value = null
    try {
      const created = await planApi.launch(planId)
      if (!(await step.start(created.id))) {
        await router.push(`/session/${created.id}`)
      }
    } catch (e) {
      const status = /** @type {any} */ (e)?.response?.status
      if (status === 409) {
        inProgress.value = await sessionApi.current().catch(() => null)
        pending = planId
      }
      if (!inProgress.value) {
        error.value = apiErrorMessage(e, {
          404: 'Session introuvable.',
          409: 'Une session est déjà en cours.',
          422: 'Un module ne peut pas être joué maintenant (set light en pause ou absent, répertoire supprimé…) : modifie la session.'
        })
      }
    } finally {
      launching.value = null
    }
  }

  /** Abandons the session in progress, then launches the plan it blocked. */
  async function abandonAndLaunch() {
    const current = inProgress.value
    if (!current || !pending) return
    await sessionApi.abandon(current.id).catch(() => {})
    await launch(pending)
  }

  return { launching, error, inProgress, launch, abandonAndLaunch }
}

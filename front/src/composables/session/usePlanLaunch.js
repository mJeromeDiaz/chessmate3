import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { planApi, sessionApi } from '@/services/api'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { requestNotifications } from '@/utils/alerts'
import { apiErrorMessage } from '@/utils/apiError'
import { replayProgram } from '@/utils/session/steps'

/**
 * Launches a saved session, or replays a played one, and starts its first module. Another session
 * in progress today: it is offered (`inProgress`) to resume or abandon; a module that cannot be
 * played now: an error.
 */
export function usePlanLaunch() {
  const router = useRouter()
  const step = useSessionStep()
  /** What is being launched: the plan's id, or the replayed session's. */
  const launching = ref(/** @type {string|null} */ (null))
  const error = ref('')
  /** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
  const inProgress = ref(null)
  /** The launch refused because of `inProgress`, to retry once that one is abandoned. */
  let pending = /** @type {(() => Promise<void>)|null} */ (null)

  /**
   * @param {string} key what `launching` holds meanwhile
   * @param {() => Promise<{id: string}>} create creates the session to play
   */
  async function begin(key, create) {
    // Still in the click: browsers only ask for the permission from a user gesture.
    requestNotifications().catch(() => {})
    launching.value = key
    error.value = ''
    inProgress.value = null
    try {
      const created = await create()
      if (!(await step.start(created.id))) {
        await router.push(`/session/${created.id}`)
      }
    } catch (e) {
      const status = /** @type {any} */ (e)?.response?.status
      if (status === 409) {
        inProgress.value = await sessionApi.current().catch(() => null)
        pending = () => begin(key, create)
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

  /** @param {string} planId */
  function launch(planId) {
    return begin(planId, () => planApi.launch(planId))
  }

  /**
   * Plays a session again: its saved session as it is now when it still exists, else the very
   * program it played.
   *
   * @param {import('@/utils/session/steps').TrainingSession} session
   */
  function replay(session) {
    return session.planId
      ? begin(session.id, () =>
          planApi.launch(/** @type {string} */ (session.planId))
        )
      : begin(session.id, () => sessionApi.create(replayProgram(session)))
  }

  /** Abandons the session in progress, then launches what it blocked. */
  async function abandonAndLaunch() {
    const current = inProgress.value
    const retry = pending
    if (!current || !retry) return
    await sessionApi.abandon(current.id).catch(() => {})
    await retry()
  }

  return { launching, error, inProgress, launch, replay, abandonAndLaunch }
}

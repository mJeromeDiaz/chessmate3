import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { sessionApi } from '@/services/api'
import { requestNotifications } from '@/utils/alerts'
import { apiErrorMessage } from '@/utils/apiError'
import { nextErrorText } from '@/utils/session/steps'

/**
 * Starts the current step of a session and goes to its run. A refusal (step blocked, another run
 * in progress, session over) leaves an error; the session itself then tells a blocked step's reason.
 *
 * @param {object} [options]
 * @param {(sessionId: string) => void} [options.onRefused] e.g. reload the session
 */
export function useSessionStep(options = {}) {
  const router = useRouter()
  const starting = ref(false)
  const error = ref('')

  /**
   * @param {string} sessionId
   * @returns {Promise<boolean>} whether the run started (and the page changed)
   */
  async function start(sessionId) {
    starting.value = true
    error.value = ''
    // From the click: the end of a free module can then be notified.
    requestNotifications().catch(() => {})
    try {
      const { run } = await sessionApi.next(sessionId)
      await router.push(`/training/${run.id}`)
      return true
    } catch (e) {
      error.value = nextErrorText(e) || apiErrorMessage(e)
      options.onRefused?.(sessionId)
      return false
    } finally {
      starting.value = false
    }
  }

  return { starting, error, start }
}

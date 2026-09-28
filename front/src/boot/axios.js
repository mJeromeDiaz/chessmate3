import { defineBoot } from '#q-app'
import { setAuthHandlers } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * Connects the shared HTTP client to the auth store: which token to send, how to refresh it, and
 * what to do when the session is gone (back to the login page, returning here afterwards).
 */
export default defineBoot(({ router, store }) => {
  const auth = useAuthStore(store)

  setAuthHandlers({
    getAccessToken: () => auth.accessToken,
    refresh: () => auth.refresh(),
    onAuthFailure: () => {
      auth.clearSession()
      const current = router.currentRoute.value

      if (current.meta?.auth === 'required') {
        router.replace({
          path: '/login',
          query: { redirect: current.fullPath, expired: '1' }
        })
      }
    }
  })
})

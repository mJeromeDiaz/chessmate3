import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Signs out and goes to the login page (a guest: the landing page), from the account menu or the
 * drawer.
 *
 * @returns {{loggingOut: import('vue').Ref<boolean>, logout: () => Promise<void>}}
 */
export function useLogout() {
  const auth = useAuthStore()
  const router = useRouter()
  const loggingOut = ref(false)

  async function logout() {
    loggingOut.value = true
    // A guest leaving (docs/GUEST.md) has no account to sign in to: back to the landing page.
    const guest = auth.isGuest
    try {
      await auth.logout()
    } catch {
      // Signed out locally anyway (see the store).
    } finally {
      loggingOut.value = false
      router.push(guest ? '/' : '/login')
    }
  }

  return { loggingOut, logout }
}

import { watch } from 'vue'
import { defineBoot } from '#q-app'
import { useAuthStore } from '@/stores/auth'
import { useThemeStore } from '@/stores/theme'

/**
 * Applies the theme stored in this browser before the app mounts (no flash of the other theme),
 * then follows the account's choice whenever the profile is loaded.
 */
export default defineBoot(({ store }) => {
  const theme = useThemeStore(store)
  theme.apply()

  const auth = useAuthStore(store)
  watch(
    () => auth.profile,
    profile => theme.adopt(profile)
  )
})

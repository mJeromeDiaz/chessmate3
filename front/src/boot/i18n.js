import { watch } from 'vue'
import { defineBoot } from '#q-app'
import { i18n } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { useLocaleStore } from '@/stores/locale'

/**
 * The languages (docs/I18N.md): the language chosen in this browser (or detected) applies before
 * the app mounts, then follows the account's choice whenever the profile is loaded.
 */
export default defineBoot(({ app, store }) => {
  app.use(i18n)
  const locale = useLocaleStore(store)
  locale.apply()

  const auth = useAuthStore(store)
  watch(
    () => auth.profile,
    profile => locale.adopt(profile)
  )
})

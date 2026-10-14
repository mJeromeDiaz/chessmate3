import { defineStore } from 'pinia'
import { ref } from 'vue'
import { Lang } from 'quasar'
import langEn from 'quasar/lang/en-GB'
import langFr from 'quasar/lang/fr'
import { browserLocale, i18n, isLocale } from '@/i18n'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/** Where this browser keeps the choice, to apply it before the profile is loaded. */
export const LOCALE_KEY = 'dontstayrooky.locale'

/** Quasar's own texts (dialog buttons, date pickers) in each language. */
const QUASAR_PACKS = { fr: langFr, en: langEn }

/** @returns {import('@/i18n').Locale|null} the choice stored in this browser, if any */
function readStored() {
  try {
    const value = localStorage.getItem(LOCALE_KEY)
    return isLocale(value) ? value : null
  } catch {
    return null
  }
}

/**
 * The language (docs/I18N.md): the browser's at first (French for a French browser, English
 * otherwise), then the user's choice, kept in this browser and, once signed in, on the account so
 * it follows the user to other devices. The account's choice wins when the profile loads; an
 * account without a choice receives the browser's one.
 */
export const useLocaleStore = defineStore('locale', () => {
  const stored = readStored()
  /** @type {import('vue').Ref<import('@/i18n').Locale>} */
  const locale = ref(stored ?? browserLocale())
  /** Whether a choice was made (in this browser or on the account), not just detected. */
  let chosen = stored !== null

  /** The messages, Quasar's texts and the page's `lang` follow the language. */
  function apply() {
    i18n.global.locale.value = locale.value
    Lang.set(QUASAR_PACKS[locale.value])
    document.documentElement.lang = locale.value
  }

  function remember() {
    try {
      localStorage.setItem(LOCALE_KEY, locale.value)
    } catch {
      // Storage disabled: the choice lasts for this visit (and on the account).
    }
  }

  /** @param {import('@/i18n').Locale} next */
  function setLocal(next) {
    locale.value = next
    chosen = true
    apply()
    remember()
  }

  /**
   * The user's choice: applied at once, kept in this browser and, when signed in, on the account
   * (a failed save only costs the other devices).
   *
   * @param {import('@/i18n').Locale} next
   */
  async function choose(next) {
    if (!isLocale(next)) return
    setLocal(next)
    const auth = useAuthStore()
    if (!auth.isAuthenticated) return
    try {
      auth.profile = await profileApi.setLocale(next)
    } catch {
      // Kept locally.
    }
  }

  /**
   * Reconciles with a freshly loaded profile: its language wins; without one, it receives this
   * browser's choice.
   *
   * @param {{locale?: string|null}|null} profile
   */
  function adopt(profile) {
    if (!profile) return
    if (isLocale(profile.locale)) {
      if (profile.locale !== locale.value || !chosen) setLocal(profile.locale)
      return
    }
    if (!chosen) return
    const auth = useAuthStore()
    profileApi
      .setLocale(locale.value)
      .then(updated => (auth.profile = updated))
      .catch(() => {})
  }

  return { locale, apply, choose, adopt }
})

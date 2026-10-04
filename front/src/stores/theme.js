import { defineStore } from 'pinia'
import { ref } from 'vue'
import { Dark } from 'quasar'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/** Where this browser keeps the choice, to apply it before the profile is loaded. */
export const THEME_KEY = 'chessmate.theme'

/** @typedef {'auto'|'light'|'dark'} Theme */

/** @type {Theme[]} */
export const THEMES = ['auto', 'light', 'dark']

/**
 * @param {unknown} value
 * @returns {value is Theme}
 */
function isTheme(value) {
  return THEMES.includes(/** @type {Theme} */ (value))
}

/** @returns {Theme|null} the choice stored in this browser, if any */
function readStored() {
  try {
    const value = localStorage.getItem(THEME_KEY)
    return isTheme(value) ? value : null
  } catch {
    return null
  }
}

/**
 * The colour theme: automatic (the OS setting), light or dark. Kept in this browser (applied at
 * start-up, visitors included) and, once signed in, on the account so it follows the user to
 * other devices. The account's choice wins when the profile loads; an account without a choice
 * receives the browser's one.
 */
export const useThemeStore = defineStore('theme', () => {
  const stored = readStored()
  /** @type {import('vue').Ref<Theme>} */
  const theme = ref(stored ?? 'auto')
  /** Whether a choice was made (in this browser or on the account), not just the default. */
  let chosen = stored !== null

  function apply() {
    Dark.set(theme.value === 'auto' ? 'auto' : theme.value === 'dark')
  }

  function remember() {
    try {
      localStorage.setItem(THEME_KEY, theme.value)
    } catch {
      // Storage disabled: the choice lasts for this visit (and on the account).
    }
  }

  /** @param {Theme} next */
  function setLocal(next) {
    theme.value = next
    chosen = true
    apply()
    remember()
  }

  /**
   * The user's choice: applied at once, kept in this browser and, when signed in, on the account
   * (a failed save only costs the other devices).
   *
   * @param {Theme} next
   */
  async function choose(next) {
    if (!isTheme(next)) return
    setLocal(next)
    const auth = useAuthStore()
    if (!auth.isAuthenticated) return
    try {
      auth.profile = await profileApi.setTheme(next)
    } catch {
      // Kept locally.
    }
  }

  /**
   * Reconciles with a freshly loaded profile: its theme wins; without one, it receives this
   * browser's choice.
   *
   * @param {{theme?: string|null}|null} profile
   */
  function adopt(profile) {
    if (!profile) return
    if (isTheme(profile.theme)) {
      if (profile.theme !== theme.value || !chosen) setLocal(profile.theme)
      return
    }
    if (!chosen) return
    const auth = useAuthStore()
    profileApi
      .setTheme(theme.value)
      .then(updated => (auth.profile = updated))
      .catch(() => {})
  }

  return { theme, apply, choose, adopt }
})

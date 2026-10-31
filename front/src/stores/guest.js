import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * What a guest would have earned with an account (docs/GUEST.md): shown once at the end of a run,
 * a path step or an arena game, with the sign-up.
 */
export const useGuestStore = defineStore('guest', () => {
  /** @type {import('vue').Ref<{xp: number, crystals: number}|null>} */
  const missed = ref(null)
  /** @type {Set<string>} what was already told in this tab (a run, a game: once) */
  const told = new Set()

  /**
   * Tells a guest what it missed; nothing for an account, nothing to tell, or told already.
   *
   * @param {string} key the run's or the game's id
   * @param {number} xp
   * @param {number} crystals
   */
  function tell(key, xp, crystals) {
    if (!useAuthStore().isGuest || told.has(key)) return
    const gains = {
      xp: Math.max(0, xp || 0),
      crystals: Math.max(0, crystals || 0)
    }
    if (gains.xp === 0 && gains.crystals === 0) return
    told.add(key)
    missed.value = gains
  }

  function dismiss() {
    missed.value = null
  }

  return { missed, tell, dismiss }
})

import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { arenaApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * The bosses arrived in the player's arena and not shown yet (docs/ARENA.md, § 5): the layout's
 * animated arrival shows them, then says so to the server.
 */
export const useArenaStore = defineStore('arena', () => {
  /** @type {import('vue').Ref<import('@/utils/arena').ArenaBoss[]>} */
  const pending = ref([])
  /**
   * A path's boss was just beaten and its victory and chests closed: its arrival may show on the
   * run's page (any other moment of a run, never).
   */
  const afterRun = ref(false)

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) {
        pending.value = []
        afterRun.value = false
      }
    }
  )

  /** Asks the server (which writes the bosses that just arrived); a failure keeps what was there. */
  async function load() {
    try {
      pending.value = (await arenaApi.unlocks()).bosses
    } catch {
      // Next visit or tab return.
    }
  }

  /**
   * The arrivals of the arena just read (GET /arena, a purchase).
   *
   * @param {import('@/utils/arena').ArenaOverview} overview
   */
  function fromOverview(overview) {
    const byCharacter = new Map(
      overview.bosses.map(boss => [boss.character, boss])
    )
    pending.value = overview.pendingUnlocks
      .map(character => byCharacter.get(character))
      .filter(boss => !!boss)
  }

  /**
   * Shown: gone at once; never rejects (a failure brings them back with the next reading).
   *
   * @param {string[]} characters
   */
  async function announce(characters) {
    if (characters.length === 0) return
    pending.value = pending.value.filter(
      boss => !characters.includes(boss.character)
    )
    await arenaApi.announce(characters).catch(() => {})
  }

  return { pending, afterRun, load, fromOverview, announce }
})

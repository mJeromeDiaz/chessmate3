import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { gamificationApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * The gamification (docs/GAMIFICATION.md): summary (level, XP, streaks), trophies and the weekly
 * quest. Each part loads and fails on its own; a failure keeps the part empty (its block hides
 * or says so).
 *
 * @typedef {'summary'|'trophies'|'quest'} Part
 */
export const useGamificationStore = defineStore('gamification', () => {
  /** @type {import('vue').Ref<import('@/utils/gamification').Summary|null>} */
  const summary = ref(null)
  /** @type {import('vue').Ref<import('@/utils/gamification').Trophy[]>} */
  const trophies = ref([])
  /** @type {import('vue').Ref<import('@/utils/gamification').Quest|null>} */
  const quest = ref(null)
  /** @type {import('vue').Ref<Partial<Record<Part, string>>>} */
  const errors = ref({})

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) reset()
    }
  )

  function reset() {
    summary.value = null
    trophies.value = []
    quest.value = null
    errors.value = {}
  }

  /**
   * Loads the parts asked (all by default).
   *
   * @param {Part[]} [parts]
   * @returns {Promise<void>}
   */
  async function load(parts = ['summary', 'trophies', 'quest']) {
    const message = 'Impossible de charger ces données. Réessayez.'
    /** @type {Record<Part, () => Promise<void>>} */
    const loaders = {
      summary: async () => {
        summary.value = await gamificationApi.summary()
      },
      trophies: async () => {
        trophies.value = await gamificationApi.trophies()
      },
      quest: async () => {
        quest.value = await gamificationApi.quest()
      }
    }
    const results = await Promise.allSettled(parts.map(part => loaders[part]()))
    const next = { ...errors.value }
    results.forEach((result, i) => {
      if (result.status === 'rejected') next[parts[i]] = message
      else delete next[parts[i]]
    })
    errors.value = next
  }

  return { summary, trophies, quest, errors, load, reset }
})

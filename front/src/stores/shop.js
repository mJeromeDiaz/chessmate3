import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { shopApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * The store (docs/SHOP.md): the player's crowns, the offers and what the player holds. Read by
 * the store page and the menus (the balance).
 */
export const useShopStore = defineStore('shop', () => {
  /** @type {import('vue').Ref<import('@/utils/shop').ShopState|null>} */
  const state = ref(null)
  /**
   * Session rewards whose chest was seen in this tab (docs/SHOP.md, § 4 bis): not shown again
   * while the server is still marking it.
   *
   * @type {import('vue').Ref<Set<string>>}
   */
  const seenChests = ref(new Set())

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) {
        state.value = null
        seenChests.value = new Set()
      }
    }
  )

  /** Reads the store (again). */
  async function load() {
    state.value = await shopApi.get()
    return state.value
  }

  /** Reads it once, for the menus' balance. */
  async function loadOnce() {
    if (!state.value) await load()
    return state.value
  }

  /**
   * @param {string} offerId
   * @param {number} quantity
   */
  async function buy(offerId, quantity) {
    state.value = await shopApi.buy(offerId, quantity)
    return state.value
  }

  /**
   * Marks a session reward's chest seen (idempotent on the server), then reads the store again
   * for the new balance.
   *
   * @param {string} rewardId
   */
  async function openChest(rewardId) {
    if (seenChests.value.has(rewardId)) return
    seenChests.value = new Set([...seenChests.value, rewardId])
    await shopApi.openReward(rewardId)
    await load()
  }

  return { state, seenChests, load, loadOnce, buy, openChest }
})

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

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) state.value = null
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

  return { state, load, loadOnce, buy }
})

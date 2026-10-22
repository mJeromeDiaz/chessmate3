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
  /**
   * The daily chests on screen, oldest first (docs/SHOP.md, § 4 ter): shown after the streak's
   * celebration (`DailyChest` in the layout), one after the other.
   *
   * @type {import('vue').Ref<import('@/utils/shopReward').DailyReward[]>}
   */
  const dailyChests = ref([])
  /** @type {(() => void)[]} */
  let onDailyClosed = []

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) {
        state.value = null
        seenChests.value = new Set()
        dailyChests.value = []
        onDailyClosed.splice(0).forEach(resolve => resolve())
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

  /**
   * Shows the daily chests not seen yet (those seen in this tab left out); resolves once the
   * player closed the last one, at once when there is none. Never rejects: a failure only skips
   * them (they come back on the dashboard).
   *
   * @returns {Promise<void>}
   */
  async function showDailyChests() {
    if (dailyChests.value.length)
      return new Promise(resolve => onDailyClosed.push(resolve))
    try {
      const list = await shopApi.unopenedDailyRewards()
      dailyChests.value = list.filter(c => !seenChests.value.has(c.id))
    } catch {
      return
    }
    if (!dailyChests.value.length) return
    return new Promise(resolve => onDailyClosed.push(resolve))
  }

  /**
   * Marks a daily chest seen (idempotent on the server); the menus get the new balance.
   *
   * @param {string} rewardId
   */
  async function openDailyChest(rewardId) {
    if (seenChests.value.has(rewardId)) return
    seenChests.value = new Set([...seenChests.value, rewardId])
    await shopApi.openDailyReward(rewardId)
  }

  /** The daily chest on screen left (opened or not): the next one, or the player goes on. */
  function nextDailyChest() {
    dailyChests.value = dailyChests.value.slice(1)
    if (!dailyChests.value.length)
      onDailyClosed.splice(0).forEach(resolve => resolve())
  }

  return {
    state,
    seenChests,
    dailyChests,
    load,
    loadOnce,
    buy,
    openChest,
    showDailyChests,
    openDailyChest,
    nextDailyChest
  }
})

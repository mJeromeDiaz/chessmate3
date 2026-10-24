import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
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
   * The days on screen, oldest first (docs/SHOP.md, § 4 ter): today's gift wheel to spin, then
   * a badge's chest; shown after the streak's celebration (`DailyChest` in the layout), one after
   * the other.
   *
   * @type {import('vue').Ref<import('@/utils/shopReward').DailyReward[]>}
   */
  const dailyChests = ref([])
  /**
   * The days whose gift wheel was spun or left in this tab: their badge chest next, if any.
   *
   * @type {import('vue').Ref<Set<string>>}
   */
  const wheelsDone = ref(new Set())
  /** The gift wheel opened from the store, a ticket's (docs/SHOP.md, § 4 ter). */
  const ticketWheel = ref(false)
  /**
   * Today's gift wheel opened by hand (the header's wheel, the menu): its daily reward's id.
   *
   * @type {import('vue').Ref<string|null>}
   */
  const handWheel = ref(null)
  /** The wheels the player can spin now: the tickets held, and today's wheel still to spin. */
  const wheelsToSpin = computed(
    () =>
      (state.value?.inventory?.wheel_ticket ?? 0) +
      (state.value?.dailyWheel ? 1 : 0)
  )
  /** @type {(() => void)[]} */
  let onDailyClosed = []

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) {
        state.value = null
        seenChests.value = new Set()
        dailyChests.value = []
        wheelsDone.value = new Set()
        ticketWheel.value = false
        handWheel.value = null
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
      // Left out: the chests seen in this tab, and the wheels left in it with no chest to open.
      dailyChests.value = list.filter(
        c =>
          !seenChests.value.has(c.id) &&
          !(wheelsDone.value.has(c.id) && !(c.crowns > 0 && !c.opened))
      )
      // Today's wheel, just offered by the day's first exercise: the header counts it.
      const today = list.find(c => c.wheelOpen)
      if (today && state.value && !state.value.dailyWheel)
        state.value = { ...state.value, dailyWheel: today.id }
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

  /**
   * Spins the gift wheel, the day's or a ticket's (null); the store gets the new balance, tickets
   * and freezes. The prize was drawn and credited by the server.
   *
   * @param {string|null} dailyRewardId
   */
  async function spinWheel(dailyRewardId) {
    const spin = await shopApi.spinWheel(dailyRewardId)
    if (state.value)
      state.value = {
        ...state.value,
        balance: spin.balance,
        inventory: { ...state.value.inventory, wheel_ticket: spin.tickets },
        dailyWheel: dailyRewardId ? null : state.value.dailyWheel
      }
    if (spin.gift === 'freeze') load().catch(() => {})
    return spin
  }

  /**
   * « Pas aujourd'hui »: the day's wheel lost.
   *
   * @param {string} rewardId
   */
  async function declineWheel(rewardId) {
    await shopApi.declineDailyWheel(rewardId)
    if (state.value?.dailyWheel === rewardId)
      state.value = { ...state.value, dailyWheel: null }
  }

  /**
   * The day's wheel left (spun, declined or closed): its badge chest, if any, else the next day.
   *
   * @param {string} rewardId
   */
  function leaveWheel(rewardId) {
    wheelsDone.value = new Set([...wheelsDone.value, rewardId])
    const day = dailyChests.value[0]
    if (day?.id === rewardId && !(day.crowns > 0 && !day.opened))
      nextDailyChest()
  }

  /** The gift wheel from the store, with a ticket. */
  function openTicketWheel() {
    ticketWheel.value = true
  }

  /**
   * The header's wheel, the menu's: today's wheel first (even left with ✕), else a ticket's.
   */
  function openWheel() {
    if (state.value?.dailyWheel) handWheel.value = state.value.dailyWheel
    else ticketWheel.value = true
  }

  /** Today's wheel opened by hand, left: not shown again in this tab after an exercise. */
  function closeHandWheel() {
    if (handWheel.value)
      wheelsDone.value = new Set([...wheelsDone.value, handWheel.value])
    handWheel.value = null
  }

  function closeTicketWheel() {
    ticketWheel.value = false
  }

  /**
   * An admin's gifts not seen yet (docs/SHOP.md, § 4 quater), their message just shown: gone from
   * the state at once; never rejects (a failure brings them back with the next reading).
   */
  async function markGiftsSeen() {
    const ids = (state.value?.gifts ?? []).map(g => g.id)
    if (!state.value || !ids.length) return
    state.value = { ...state.value, gifts: [] }
    await shopApi.markGiftsSeen(ids).catch(() => {})
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
    wheelsDone,
    ticketWheel,
    handWheel,
    wheelsToSpin,
    load,
    loadOnce,
    buy,
    openChest,
    showDailyChests,
    openDailyChest,
    nextDailyChest,
    spinWheel,
    declineWheel,
    leaveWheel,
    openTicketWheel,
    openWheel,
    closeHandWheel,
    closeTicketWheel,
    markGiftsSeen
  }
})

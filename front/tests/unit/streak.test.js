import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  gamificationApi: {
    summary: vi.fn(),
    trophies: vi.fn(),
    quest: vi.fn(),
    streakNotice: vi.fn(),
    acknowledgeStreak: vi.fn(),
    streakReminder: vi.fn(),
    saveStreakReminder: vi.fn()
  },
  shopApi: {
    unopenedDailyRewards: vi.fn(),
    openDailyReward: vi.fn()
  }
}))

const { gamificationApi, shopApi } = await import('@/services/api')
const { useGamificationStore } = await import('@/stores/gamification')
const { useShopStore } = await import('@/stores/shop')
const {
  FLAME_BASE,
  FLAME_STEP,
  STREAK_BADGES,
  flameScale,
  localToday,
  nextMilestoneText,
  streakBadge,
  streakMessage,
  weekRow
} = await import('@/utils/streak')

/**
 * @param {Partial<import('@/utils/streak').StreakNotice>} [overrides]
 * @returns {import('@/utils/streak').StreakNotice}
 */
function notice(overrides = {}) {
  return {
    pending: true,
    streak: 12,
    previousStreak: 11,
    badge: null,
    week: [true, true, true, false, false, false, false],
    nextMilestone: 14,
    localDate: localToday('UTC'),
    ...overrides
  }
}

describe('streak badges', () => {
  it('has the 12 milestones, 7 and 30 being the older trophies', () => {
    expect(STREAK_BADGES.map(b => b.goal)).toEqual([
      3, 7, 14, 30, 50, 100, 200, 300, 365, 450, 500, 1000
    ])
    expect(streakBadge('on_fire')?.name).toBe('En feu')
    expect(streakBadge('streak_1000')?.name).toBe('Légende')
    expect(streakBadge(null)).toBeNull()
  })

  it('grows the flame with each badge reached, small below a week', () => {
    expect(flameScale(0)).toBe(FLAME_BASE)
    expect(flameScale(2)).toBe(FLAME_BASE)
    expect(flameScale(3)).toBeCloseTo(FLAME_BASE + FLAME_STEP, 5)
    expect(flameScale(6)).toBe(flameScale(3))
    expect(flameScale(7)).toBeGreaterThan(flameScale(6))
    expect(flameScale(29)).toBeLessThan(flameScale(30))
    expect(flameScale(5000)).toBeCloseTo(FLAME_BASE + 12 * FLAME_STEP, 5)
  })
})

describe('streak words', () => {
  it('celebrates a birth, a restart, milestones and badges', () => {
    expect(streakMessage(notice({ streak: 1, previousStreak: 0 }))).toContain(
      'Une série est née'
    )
    expect(streakMessage(notice({ streak: 1, previousStreak: 12 }))).toContain(
      'Ta série de 12 jours s’est arrêtée'
    )
    expect(streakMessage(notice({ streak: 7 }))).toContain('Une semaine')
    expect(streakMessage(notice({ streak: 100 }), 'Léa')).toContain(
      'chapeau bas, Léa.'
    )
    expect(streakMessage(notice({ streak: 100 }))).toContain('chapeau bas.')
    expect(streakMessage(notice({ streak: 50, badge: 'streak_50' }))).toContain(
      '« Brasier »'
    )
    expect(streakMessage(notice({ streak: 12 }))).toBe(
      '12 jours d’affilée ! Chaque séance compte, même les courtes. Continue comme ça.'
    )
  })

  it('says how far the next milestone is', () => {
    expect(nextMilestoneText(12, 14)).toBe(
      'Prochain palier : 14 jours · encore 2'
    )
    expect(nextMilestoneText(1000, null)).toBe('Tu es une légende.')
  })

  it('lays the week out from Monday, today marked', () => {
    // Wednesday 7 October 2026.
    const row = weekRow([true, false, true], '2026-10-07')
    expect(row.map(d => d.label).join('')).toBe('LMMJVSD')
    expect(row[0]).toMatchObject({ done: true, today: false, future: false })
    expect(row[1].done).toBe(false)
    expect(row[2]).toMatchObject({ done: true, today: true })
    expect(row[3].future).toBe(true)
    expect(weekRow([], '2026-10-11')[6].today).toBe(true)
  })

  it('shows the days a freeze kept as frozen, never an active one', () => {
    const row = weekRow([true, false, false, true], '2026-10-08', [
      true,
      true,
      true
    ])
    expect(row.map(d => d.frozen)).toEqual([
      false,
      true,
      true,
      false,
      false,
      false,
      false
    ])
    expect(row[0].done).toBe(true)
    expect(weekRow([true], '2026-10-08')[1].frozen).toBe(false)
  })

  it('counts the local day like the server', () => {
    const at = new Date('2026-10-07T22:30:00Z')
    expect(localToday('Europe/Paris', at)).toBe('2026-10-08')
    expect(localToday('America/New_York', at)).toBe('2026-10-07')
    expect(localToday(null, at)).toBe('2026-10-07')
  })
})

describe('streak celebration', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
    gamificationApi.acknowledgeStreak.mockResolvedValue({})
    shopApi.unopenedDailyRewards.mockResolvedValue([])
  })

  it('shows today’s announcement until it is closed, then updates the summary', async () => {
    const store = useGamificationStore()
    store.summary = /** @type {any} */ ({
      streak: {
        current: 11,
        best: 11,
        playedToday: false,
        week: [],
        nextMilestone: 14
      },
      today: { date: 'yesterday', exerciseXp: 0, cap: 500 }
    })
    gamificationApi.streakNotice.mockResolvedValue(notice())

    let closed = false
    const shown = store.celebrateStreak().then(() => (closed = true))
    await vi.waitFor(() => expect(store.celebration?.streak).toBe(12))
    expect(closed).toBe(false)
    // Asked again meanwhile: the same celebration.
    store.celebrateStreak()
    expect(gamificationApi.streakNotice).toHaveBeenCalledTimes(1)

    store.closeCelebration()
    await shown
    expect(closed).toBe(true)
    expect(store.celebration).toBeNull()
    expect(gamificationApi.acknowledgeStreak).toHaveBeenCalledTimes(1)
    expect(store.summary?.streak).toMatchObject({
      current: 12,
      best: 12,
      playedToday: true,
      nextMilestone: 14
    })

    // Shown today: not asked again.
    await store.celebrateStreak()
    expect(gamificationApi.streakNotice).toHaveBeenCalledTimes(1)
  })

  it('stops asking after an exercise once none is pending, not on the dashboard', async () => {
    const store = useGamificationStore()
    gamificationApi.streakNotice.mockResolvedValue({ pending: false })

    await store.celebrateStreak({ afterExercise: false })
    await store.celebrateStreak({ afterExercise: false })
    expect(gamificationApi.streakNotice).toHaveBeenCalledTimes(2)

    await store.celebrateStreak()
    await store.celebrateStreak()
    expect(gamificationApi.streakNotice).toHaveBeenCalledTimes(3)
    expect(store.celebration).toBeNull()
  })

  it('shows the daily chests after the celebration, then lets the player go on', async () => {
    const store = useGamificationStore()
    const shop = useShopStore()
    gamificationApi.streakNotice.mockResolvedValue(notice())
    shopApi.unopenedDailyRewards.mockResolvedValue([
      { id: 'd1', crowns: 9 },
      { id: 'd2', crowns: 79 }
    ])

    let done = false
    const shown = store.celebrateStreak().then(() => (done = true))
    await vi.waitFor(() => expect(store.celebration).not.toBeNull())
    expect(shopApi.unopenedDailyRewards).not.toHaveBeenCalled()
    store.closeCelebration()
    await vi.waitFor(() => expect(shop.dailyChests).toHaveLength(2))
    shop.nextDailyChest()
    expect(shop.dailyChests.map(c => c.id)).toEqual(['d2'])
    await Promise.resolve()
    expect(done).toBe(false)
    shop.nextDailyChest()
    await shown
    expect(done).toBe(true)
  })

  it('shows a daily chest left unseen on the dashboard, not after an exercise', async () => {
    const store = useGamificationStore()
    const shop = useShopStore()
    gamificationApi.streakNotice.mockResolvedValue({ pending: false })
    shopApi.unopenedDailyRewards.mockResolvedValue([{ id: 'd1', crowns: 9 }])

    const shown = store.celebrateStreak({ afterExercise: false })
    await vi.waitFor(() => expect(shop.dailyChests).toHaveLength(1))
    // Seen in this tab: not shown again while the server is marking it.
    shopApi.openDailyReward.mockResolvedValue({})
    await shop.openDailyChest('d1')
    expect(shopApi.openDailyReward).toHaveBeenCalledWith('d1')
    shop.nextDailyChest()
    await shown
    await store.celebrateStreak({ afterExercise: false })
    expect(shopApi.unopenedDailyRewards).toHaveBeenCalledTimes(2)
    expect(shop.dailyChests).toHaveLength(0)

    // After an exercise, none pending: shown elsewhere, its chest left to the dashboard.
    await store.celebrateStreak()
    expect(shopApi.unopenedDailyRewards).toHaveBeenCalledTimes(2)
  })

  it('never blocks the player when the API fails', async () => {
    const store = useGamificationStore()
    gamificationApi.streakNotice.mockRejectedValue(new Error('500'))

    await expect(store.celebrateStreak()).resolves.toBeUndefined()
    expect(store.celebration).toBeNull()

    gamificationApi.streakNotice.mockResolvedValue({ pending: false })
    shopApi.unopenedDailyRewards.mockRejectedValue(new Error('500'))
    await expect(
      store.celebrateStreak({ afterExercise: false })
    ).resolves.toBeUndefined()
  })
})

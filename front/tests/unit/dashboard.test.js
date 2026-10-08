import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  dashboardApi: {
    activity: vi.fn(),
    ratingHistory: vi.fn(),
    lichessRatingHistory: vi.fn()
  },
  puzzleApi: { rating: vi.fn() },
  woodpeckerApi: { sets: vi.fn() },
  repertoireApi: { overview: vi.fn() }
}))

const { dashboardApi, puzzleApi, woodpeckerApi, repertoireApi } =
  await import('@/services/api')
const { useDashboardStore } = await import('@/stores/dashboard')
const { useAuthStore } = await import('@/stores/auth')
const { addDays } = await import('@/utils/dashboard/days')
const { buildCurve, monthTicks } = await import('@/utils/dashboard/curve')
const { buildModuleRows, DASHBOARD_MODULES } =
  await import('@/utils/dashboard/modules')

describe('days', () => {
  it('adds days across month ends and DST changes', () => {
    expect(addDays('2026-10-24', 2)).toBe('2026-10-26')
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28')
  })
})

describe('rating curve', () => {
  it('spans the period, carries the last rating to today and reports the delta', () => {
    const curve = buildCurve(
      [
        { date: '2026-07-01', rating: 1500 },
        { date: '2026-07-06', rating: 1600 }
      ],
      '2026-07-01',
      '2026-07-11'
    )
    expect(curve).toMatchObject({
      current: 1600,
      delta: 100,
      min: 1500,
      max: 1600
    })
    // x: day 0, 5, 10 of 10; y: 15 % headroom (1485..1615).
    expect(curve.line).toBe('M0.00 88.46 L50.00 11.54 L100.00 11.54')
    expect(curve.area).toBe(`${curve.line} L100.00 100 L0.00 100 Z`)
    expect(curve.dotTop).toBeCloseTo(11.54, 2)
  })

  it('draws a flat line in the middle for a single rating', () => {
    const curve = buildCurve(
      [{ date: '2026-07-11', rating: 1500 }],
      '2026-07-01',
      '2026-07-11'
    )
    expect(curve).toMatchObject({ current: 1500, delta: 0, dotTop: 50 })
  })

  it('has nothing to draw without points', () => {
    expect(buildCurve([], '2026-07-01', '2026-07-11')).toBeNull()
  })

  it('names the months starting inside the period', () => {
    expect(monthTicks('2026-07-05', '2026-10-03').map(t => t.label)).toEqual([
      'Août',
      'Septembre',
      'Octobre'
    ])
    expect(monthTicks('2026-07-01', '2026-07-31')[0]).toEqual({
      label: 'Juillet',
      left: 0
    })
  })
})

describe('module rows', () => {
  const empty = { totals: {}, puzzleRating: null, sets: [], repertoires: null }

  it('lists the design modules, the missing ones as coming soon', () => {
    const rows = buildModuleRows(empty)
    expect(rows.map(r => r.module.id)).toEqual(DASHBOARD_MODULES)
    expect(rows.filter(r => r.to === null).map(r => r.module.id)).toEqual([
      'finales',
      'analyse'
    ])
    expect(rows.find(r => r.module.id === 'evaluation')).toMatchObject({
      to: '/evaluation',
      stat: 'Aucune position jouée'
    })
    expect(rows.find(r => r.module.id === 'finales').stat).toBe('Bientôt')
    expect(rows.find(r => r.module.id === 'puzzles').stat).toBe(
      'Aucun puzzle joué'
    )
    expect(rows.find(r => r.module.id === 'woodpecker').stat).toBe(
      'Aucun set en cours'
    )
    expect(rows.find(r => r.module.id === 'repertoire').stat).toBe(
      'Aucun répertoire'
    )
  })

  it('shows real figures for the modules that exist', () => {
    const rows = buildModuleRows({
      totals: {
        puzzle_rated: { count: 1500, successCount: 1200, durationMs: 0 },
        puzzle_unrated: { count: 100, successCount: 84, durationMs: 0 },
        position_evaluation: { count: 8, successCount: 5, durationMs: 0 }
      },
      puzzleRating: { rating: 1742, provisional: false },
      sets: [
        {
          id: 'old',
          name: 'Old',
          status: 'completed',
          archived: false,
          mode: 'classic',
          puzzleCount: 50,
          cycleCount: 7,
          current: null
        },
        {
          id: 's1',
          name: 'Tactiques',
          status: 'active',
          archived: false,
          mode: 'classic',
          puzzleCount: 100,
          cycleCount: 7,
          current: { number: 2, status: 'active', played: 37, accuracy: 0.81 }
        }
      ],
      repertoires: {
        cards: { total: 142, due: 9 },
        repertoires: [
          { successRate30: 0.9 },
          { successRate30: null },
          { successRate30: 0.8 }
        ]
      }
    })
    const [puzzles, woodpecker, , repertoire] = rows
    expect(puzzles.stat).toBe('1\u202f284 résolus · Elo 1742')
    expect(puzzles.ratio).toBeCloseTo(1284 / 1600)
    expect(woodpecker).toMatchObject({
      to: '/woodpecker/s1',
      stat: 'Cycle 2/7 · 37/100 · précision 81 %',
      ratio: 0.37
    })
    expect(repertoire.stat).toBe('142 coups · 9 à revoir · 85 % sur 30 j')
    expect(repertoire.ratio).toBeCloseTo(0.85)
    expect(rows.find(r => r.module.id === 'evaluation')).toMatchObject({
      to: '/evaluation',
      stat: '8 positions · 63 % justes',
      ratio: 5 / 8
    })
  })
})

describe('dashboard store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
    dashboardApi.activity.mockResolvedValue({
      timezone: 'UTC',
      from: 'a',
      today: 'b',
      days: [],
      totals: {}
    })
    dashboardApi.ratingHistory.mockResolvedValue({
      from: 'a',
      today: 'b',
      points: []
    })
    puzzleApi.rating.mockResolvedValue({ rating: 1500, provisional: true })
    woodpeckerApi.sets.mockResolvedValue([])
    repertoireApi.overview.mockResolvedValue({
      cards: { total: 0, due: 0 },
      repertoires: []
    })
  })

  it('welcomes a user who has played nothing yet', async () => {
    const store = useDashboardStore()
    await store.load()
    expect(store.loaded).toBe(true)
    expect(store.isNewUser).toBe(true)
    expect(dashboardApi.lichessRatingHistory).not.toHaveBeenCalled()

    dashboardApi.activity.mockResolvedValue({
      timezone: 'UTC',
      from: 'a',
      today: 'b',
      days: [],
      totals: { puzzle_rated: { count: 1, successCount: 1, durationMs: 1 } }
    })
    await store.load()
    expect(store.isNewUser).toBe(false)
  })

  it('keeps the sections that loaded when another one fails', async () => {
    woodpeckerApi.sets.mockRejectedValue(new Error('boom'))
    const store = useDashboardStore()
    await store.load()
    expect(store.errors).toEqual({ woodpecker: expect.any(String) })
    expect(store.rating.points).toEqual([])
    expect(store.isNewUser).toBe(true)
  })

  it('asks Lichess once per visit, again after a failure', async () => {
    const store = useDashboardStore()
    dashboardApi.lichessRatingHistory.mockRejectedValueOnce({
      response: { status: 503, headers: { 'x-lichess-unavailable': 'busy' } }
    })
    await store.loadLichess()
    expect(store.lichessError).toMatch('Lichess est occupé')

    dashboardApi.lichessRatingHistory.mockResolvedValue({
      linked: false,
      perfs: {}
    })
    await store.loadLichess()
    expect(store.lichessError).toBeNull()
    expect(store.lichess.linked).toBe(false)
    await store.loadLichess()
    expect(dashboardApi.lichessRatingHistory).toHaveBeenCalledTimes(2)
  })

  it('forgets everything on sign-out', async () => {
    const store = useDashboardStore()
    const auth = useAuthStore()
    auth.accessToken = 'token'
    await nextTick()
    await store.load()
    auth.accessToken = null
    await nextTick()
    expect(store.activity).toBeNull()
    expect(store.loaded).toBe(false)
  })
})

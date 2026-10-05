import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  dashboardApi: {
    activity: vi.fn(),
    ratingHistory: vi.fn(),
    lichessRatingHistory: vi.fn(),
    training: vi.fn(),
    themes: vi.fn(),
    repertoire: vi.fn()
  },
  puzzleApi: { rating: vi.fn() },
  woodpeckerApi: { sets: vi.fn() },
  repertoireApi: { overview: vi.fn() }
}))

const { dashboardApi } = await import('@/services/api')
const { useDashboardStore } = await import('@/stores/dashboard')
const {
  DEFAULT_PERIOD,
  dayLabel,
  formatHours,
  labelledBars,
  scaleMax,
  sessionFigures,
  validPeriod,
  weakestTheme,
  weekChart
} = await import('@/utils/dashboard/stats')

const MIN = 60_000

describe('statistics utils', () => {
  it('keeps a known period only', () => {
    expect(validPeriod('90')).toBe(90)
    expect(validPeriod(365)).toBe(365)
    expect(validPeriod('12')).toBe(DEFAULT_PERIOD)
    expect(validPeriod(null)).toBe(DEFAULT_PERIOD)
  })

  it('writes training times in minutes, then hours', () => {
    expect(formatHours(0)).toBe('0 min')
    expect(formatHours(44 * MIN + 20_000)).toBe('44 min')
    expect(formatHours(120 * MIN)).toBe('2 h')
    expect(formatHours(125 * MIN)).toBe('2 h 05')
    expect(formatHours(null)).toBe('—')
  })

  it('labels a calendar day without a timezone shift', () => {
    expect(dayLabel('2026-07-13')).toBe('13 juil.')
    expect(dayLabel('2026-01-01')).toBe('1 janv.')
  })

  it('rounds the scale up', () => {
    expect(scaleMax(0)).toBe(15 * MIN)
    expect(scaleMax(20 * MIN)).toBe(30 * MIN)
    expect(scaleMax(31 * MIN)).toBe(60 * MIN)
    expect(scaleMax(61 * MIN)).toBe(120 * MIN)
    expect(scaleMax(300 * MIN)).toBe(300 * MIN)
  })

  it('stacks the modules of each week in the fixed order, empty ones left out', () => {
    const chart = weekChart([
      {
        start: '2026-07-13',
        durationMs: {
          woodpecker: 15 * MIN,
          repertoire: 0,
          puzzles: 30 * MIN,
          free: 0
        }
      },
      {
        start: '2026-07-20',
        durationMs: {
          woodpecker: 0,
          repertoire: 5 * MIN,
          puzzles: 0,
          free: 55 * MIN
        }
      }
    ])
    expect(chart.bars.map(b => b.total)).toEqual([45 * MIN, 60 * MIN])
    expect(chart.bars[0].label).toBe('13 juil.')
    expect(chart.bars[0].segments.map(s => s.module)).toEqual([
      'puzzles',
      'woodpecker'
    ])
    expect(chart.bars[0].segments[0].height).toBeCloseTo(50)
    expect(chart.bars[1].segments.map(s => [s.module, s.height])).toEqual([
      ['repertoire', (5 / 60) * 100],
      ['free', (55 / 60) * 100]
    ])
    expect(chart.gridlines.map(g => [g.label, g.bottom])).toEqual([
      ['0', 0],
      ['30 min', 50],
      ['1 h', 100]
    ])
  })

  it('labels about six bars, the last one always', () => {
    expect([...labelledBars(2)].sort((a, b) => a - b)).toEqual([0, 1])
    const year = labelledBars(53)
    expect(year.has(52)).toBe(true)
    expect(year.size).toBeLessThanOrEqual(6)
  })

  it('lists the sessions figures and the weakest theme', () => {
    expect(
      sessionFigures({
        closed: 3,
        completed: 1,
        abandoned: 1,
        expired: 1,
        playedMs: 620_000,
        averageMs: 310_000
      }).map(f => f.value)
    ).toEqual(['1', '1', '1', '5 min'])
    expect(sessionFigures(null).map(f => f.value)).toEqual(['0', '0', '0', '—'])
    expect(
      weakestTheme({ weak: [{ key: 'pin', attempts: 5, successRate: 0.2 }] })
        ?.key
    ).toBe('pin')
    expect(weakestTheme({ weak: [] })).toBeNull()
    expect(weakestTheme(null)).toBeNull()
  })
})

describe('statistics store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.resetAllMocks()
    dashboardApi.training.mockResolvedValue({
      weeks: [],
      totals: {},
      sessions: {}
    })
    dashboardApi.themes.mockResolvedValue({
      attempts: 0,
      themes: [],
      strong: [],
      weak: []
    })
    dashboardApi.repertoire.mockResolvedValue({ repertoires: 0, fragile: [] })
  })

  it('loads the three blocks for the period and remembers it', async () => {
    const store = useDashboardStore()
    expect(store.period).toBe(30)
    await store.loadStats(90)
    expect(dashboardApi.training).toHaveBeenCalledWith(90)
    expect(dashboardApi.themes).toHaveBeenCalledWith(90)
    expect(dashboardApi.repertoire).toHaveBeenCalledWith(90)
    expect(store.training).not.toBeNull()
    expect(store.statsLoading).toBe(false)
    expect(localStorage.getItem('cm.stats.days')).toBe('90')

    setActivePinia(createPinia())
    expect(useDashboardStore().period).toBe(90)
  })

  it('lets one block fail alone', async () => {
    dashboardApi.themes.mockRejectedValue(new Error('down'))
    const store = useDashboardStore()
    await store.loadStats()
    expect(store.themes).toBeNull()
    expect(store.statsErrors).toEqual({
      themes: 'Impossible de charger ces données. Réessayez.'
    })
    expect(store.training).not.toBeNull()
    expect(store.health).not.toBeNull()
  })

  it('keeps the answers of the latest period only', async () => {
    /** @type {(value: any) => void} */
    let slow = () => {}
    dashboardApi.training.mockReturnValueOnce(
      new Promise(resolve => (slow = resolve))
    )
    const store = useDashboardStore()
    const first = store.loadStats(7)
    dashboardApi.training.mockResolvedValueOnce({
      weeks: ['latest'],
      totals: {},
      sessions: {}
    })
    await store.loadStats(365)
    slow({ weeks: ['stale'], totals: {}, sessions: {} })
    await first
    expect(store.period).toBe(365)
    expect(store.training?.weeks).toEqual(['latest'])
  })

  it('hides the home tip when its themes cannot be loaded', async () => {
    const store = useDashboardStore()
    dashboardApi.themes.mockResolvedValueOnce({
      weak: [{ key: 'pin', successRate: 0.2 }]
    })
    await store.loadTip()
    expect(dashboardApi.themes).toHaveBeenCalledWith(30)
    expect(store.tipThemes?.weak[0].key).toBe('pin')
    dashboardApi.themes.mockRejectedValueOnce(new Error('down'))
    await store.loadTip()
    expect(store.tipThemes).toBeNull()
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  puzzleApi: {
    start: vi.fn(),
    replay: vi.fn(),
    submit: vi.fn(),
    history: vi.fn(),
    themes: vi.fn(),
    rating: vi.fn(),
    importLichessRating: vi.fn()
  }
}))

const { puzzleApi } = await import('@/services/api')
const { usePuzzleStore } = await import('@/stores/puzzle')
const { useAuthStore } = await import('@/stores/auth')

const RATING = {
  rating: 1500,
  deviation: 350,
  provisional: true,
  ratedCount: 0,
  source: 'default',
  lichessImportAvailable: true
}
const ATTEMPT = {
  id: 'a1',
  status: 'pending',
  rated: true,
  puzzle: { id: 'K69di' }
}

describe('puzzle store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('starts the next puzzle with the current filters', async () => {
    puzzleApi.start.mockResolvedValue(ATTEMPT)
    const store = usePuzzleStore()
    store.setThemes(['fork', 'pin'])
    store.setDifficulty('harder')

    await store.next()

    expect(puzzleApi.start).toHaveBeenCalledWith({
      themes: ['fork', 'pin'],
      difficulty: 'harder'
    })
    expect(store.attempt).toEqual(ATTEMPT)
    expect(store.result).toBeNull()
  })

  it('submits the move log only, and refreshes the rating after a rated attempt', async () => {
    puzzleApi.start.mockResolvedValue(ATTEMPT)
    puzzleApi.submit.mockResolvedValue({
      ...ATTEMPT,
      status: 'solved',
      ratingAfter: 1612.4,
      ratingDelta: 112.4
    })
    puzzleApi.rating.mockResolvedValue({
      ...RATING,
      rating: 1612,
      ratedCount: 1
    })
    const store = usePuzzleStore()
    await store.next()

    const report = {
      moves: ['e1e7', 'e7f6'],
      hintLevel: 0,
      solutionShown: false
    }
    const answer = await store.submit(report)

    expect(puzzleApi.submit).toHaveBeenCalledWith('a1', report)
    expect(answer.status).toBe('solved')
    expect(store.result).toEqual(answer)
    expect(store.rating.rating).toBe(1612)
  })

  it('does not refresh the rating after an unrated replay', async () => {
    puzzleApi.replay.mockResolvedValue({ ...ATTEMPT, rated: false })
    puzzleApi.submit.mockResolvedValue({
      ...ATTEMPT,
      rated: false,
      status: 'solved',
      ratingAfter: null
    })
    const store = usePuzzleStore()
    await store.replay('K69di')

    await store.submit({ moves: [], hintLevel: 0, solutionShown: false })

    expect(puzzleApi.replay).toHaveBeenCalledWith('K69di')
    expect(puzzleApi.rating).not.toHaveBeenCalled()
  })

  it('refuses to submit without an attempt', async () => {
    await expect(
      usePuzzleStore().submit({ moves: [], hintLevel: 0, solutionShown: false })
    ).rejects.toThrow()
  })

  it('fetches the themes once and retries after a failure', async () => {
    const store = usePuzzleStore()
    puzzleApi.themes.mockRejectedValueOnce(new Error('offline'))
    await expect(store.fetchThemes()).rejects.toThrow()

    puzzleApi.themes.mockResolvedValue([{ key: 'fork', labelFr: 'Fourchette' }])
    await store.fetchThemes()
    await store.fetchThemes()

    expect(puzzleApi.themes).toHaveBeenCalledTimes(2)
    expect(store.themeLabel('fork')).toBe('Fourchette')
    expect(store.themeLabel('unknown')).toBe('unknown')
  })

  it('imports the Lichess rating', async () => {
    puzzleApi.importLichessRating.mockResolvedValue({
      ...RATING,
      rating: 2034,
      source: 'lichess',
      lichessImportAvailable: false
    })
    const store = usePuzzleStore()

    await store.importLichessRating()

    expect(store.rating.source).toBe('lichess')
  })

  it("forgets the user's rating and attempt when signed out", async () => {
    puzzleApi.start.mockResolvedValue(ATTEMPT)
    puzzleApi.rating.mockResolvedValue(RATING)
    const auth = useAuthStore()
    auth.accessToken = 'token'
    const store = usePuzzleStore()
    store.setThemes(['fork'])
    await store.fetchRating()
    await store.next()

    auth.accessToken = null
    await nextTick()

    expect(store.rating).toBeNull()
    expect(store.attempt).toBeNull()
    expect(store.filters.themes).toEqual([])
  })
})

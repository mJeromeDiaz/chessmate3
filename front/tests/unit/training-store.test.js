import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  trainingApi: {
    start: vi.fn(),
    current: vi.fn(),
    get: vi.fn(),
    next: vi.fn(),
    submit: vi.fn(),
    stop: vi.fn()
  }
}))

const { trainingApi } = await import('@/services/api')
const { useTrainingStore } = await import('@/stores/training')
const { useAuthStore } = await import('@/stores/auth')
const { runTrends, closeReasonText, subjectPath, backLabel } =
  await import('@/utils/training')

const conflict = () =>
  Object.assign(new Error('409'), { response: { status: 409 } })

describe('training store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('starts a run with a budget in seconds', async () => {
    trainingApi.start.mockResolvedValue({ id: 'r1', status: 'active' })
    const store = useTrainingStore()

    await store.start({ module: 'woodpecker', subjectId: 's1', minutes: 15 })

    expect(trainingApi.start).toHaveBeenCalledWith({
      module: 'woodpecker',
      subjectId: 's1',
      budgetSeconds: 900
    })
    expect(store.current.id).toBe('r1')
  })

  it('passes the module options (a repertoire test scope)', async () => {
    trainingApi.start.mockResolvedValue({ id: 'r2', status: 'active' })
    const store = useTrainingStore()
    const config = { repertoireIds: ['a'], unit: 'line' }

    await store.start({
      module: 'repertoire',
      subjectId: 'u1',
      minutes: 5,
      config
    })

    expect(trainingApi.start).toHaveBeenCalledWith({
      module: 'repertoire',
      subjectId: 'u1',
      budgetSeconds: 300,
      config
    })
  })

  it('on a conflict, finds the run already in progress', async () => {
    trainingApi.start.mockRejectedValue(conflict())
    trainingApi.current.mockResolvedValue({
      id: 'r0',
      status: 'active',
      subjectId: 's9'
    })
    const store = useTrainingStore()

    await expect(
      store.start({ module: 'woodpecker', subjectId: 's1', minutes: 5 })
    ).rejects.toThrow('409')

    expect(store.current.id).toBe('r0')
  })

  it('on a conflict without a run in progress, the subject is not playable', async () => {
    trainingApi.start.mockRejectedValue(conflict())
    trainingApi.current.mockResolvedValue(null)
    const store = useTrainingStore()

    await expect(
      store.start({ module: 'woodpecker', subjectId: 's1', minutes: 5 })
    ).rejects.toThrow()

    expect(store.current).toBeNull()
  })

  it('forgets the current run when it is stopped', async () => {
    trainingApi.current.mockResolvedValue({ id: 'r1', status: 'active' })
    trainingApi.stop.mockResolvedValue({ id: 'r1', status: 'closed' })
    const store = useTrainingStore()
    await store.fetchCurrent()

    await store.stop('r1')

    expect(store.current).toBeNull()
  })

  it('forgets the current run when signed out', async () => {
    trainingApi.current.mockResolvedValue({ id: 'r1', status: 'active' })
    const auth = useAuthStore()
    auth.accessToken = 'token'
    const store = useTrainingStore()
    await store.fetchCurrent()
    await nextTick()

    auth.accessToken = null
    await nextTick()

    expect(store.current).toBeNull()
  })
})

describe('training utils', () => {
  it('compares each run with the previous one, per minute', () => {
    const rows = runTrends([
      { id: 'c', summary: { itemsPerMinute: 4.5 } },
      { id: 'b', summary: { itemsPerMinute: 3.25 } },
      { id: 'a', summary: null }
    ])

    expect(rows.map(r => r.trend)).toEqual([1.25, null, null])
  })

  it('explains why a run closed and where to go back', () => {
    const run = {
      closeReason: 'subject_resting',
      summary: { context: { availableAt: '2026-09-29T22:00:00Z' } },
      subjectType: 'woodpecker_set',
      subjectId: 's1'
    }

    expect(closeReasonText(run)).toContain(
      'Repos : prochain cycle le 30/09/2026'
    )
    expect(closeReasonText({ closeReason: 'time_up' })).toBe('Temps écoulé.')
    expect(subjectPath(run)).toBe('/woodpecker/s1')
  })

  it('knows the puzzles and free study modules', () => {
    expect(
      closeReasonText({ closeReason: 'subject_unavailable', module: 'puzzles' })
    ).toContain('Plus aucun puzzle')
    expect(
      closeReasonText({
        closeReason: 'subject_unavailable',
        module: 'woodpecker'
      })
    ).toContain('Le set')
    expect(subjectPath({ subjectType: 'puzzle_player', subjectId: 'u' })).toBe(
      '/puzzle'
    )
    expect(subjectPath({ subjectType: 'free_owner', subjectId: 'u' })).toBe('/')
    expect(backLabel({ module: 'puzzles' })).toBe('Retour aux puzzles')
    expect(backLabel({ module: 'free' })).toBe('Retour à l’accueil')
  })
})

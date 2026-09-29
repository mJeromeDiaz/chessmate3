import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  woodpeckerApi: {
    sets: vi.fn(),
    set: vi.fn(),
    create: vi.fn(),
    act: vi.fn(),
    next: vi.fn(),
    submit: vi.fn(),
    stubborn: vi.fn()
  }
}))

const { woodpeckerApi } = await import('@/services/api')
const { useWoodpeckerStore, cycleRecap } = await import('@/stores/woodpecker')
const { useAuthStore } = await import('@/stores/auth')

const cycle = (number, run, status, extra = {}) => ({
  number,
  run,
  status,
  played: 0,
  ...extra
})
const set = (cycles, status = 'active') => ({
  id: 's1',
  status,
  cycles,
  current:
    cycles.find(c => c.status === 'active' || c.status === 'resting') ?? null
})

describe('woodpecker store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('plays the next puzzle and keeps the set in sync', async () => {
    const s = set([cycle(1, 1, 'active', { played: 3 })])
    woodpeckerApi.next.mockResolvedValue({
      id: 'a1',
      puzzle: { id: 'K69di' },
      set: s
    })
    const store = useWoodpeckerStore()

    await store.next('s1')

    expect(woodpeckerApi.next).toHaveBeenCalledWith('s1')
    expect(store.attempt.id).toBe('a1')
    expect(store.set).toEqual(s)
    expect(store.recap).toBeNull()
  })

  it('sends the move log only and detects the end of a cycle run', async () => {
    const before = set([
      cycle(1, 1, 'completed'),
      cycle(2, 1, 'active', { played: 4 })
    ])
    const after = set([
      cycle(1, 1, 'completed'),
      cycle(2, 1, 'completed'),
      cycle(3, 1, 'resting')
    ])
    woodpeckerApi.next.mockResolvedValue({ id: 'a1', puzzle: {}, set: before })
    woodpeckerApi.submit.mockResolvedValue({
      id: 'a1',
      status: 'solved',
      set: after
    })
    const store = useWoodpeckerStore()
    await store.next('s1')

    const report = { moves: ['e1e7'], hintLevel: 0, solutionShown: false }
    await store.submit(report)

    expect(woodpeckerApi.submit).toHaveBeenCalledWith('a1', report)
    expect(store.recap).toMatchObject({
      completed: { number: 2 },
      previous: { number: 1 },
      setCompleted: false
    })
  })

  it('finds no recap while the run goes on, and flags the end of the set', () => {
    const running = set([cycle(1, 1, 'active', { played: 2 })])
    expect(cycleRecap(running, cycle(1, 1, 'active'))).toBeNull()

    const done = set(
      [cycle(1, 1, 'completed'), cycle(2, 1, 'lost'), cycle(2, 2, 'completed')],
      'completed'
    )
    expect(cycleRecap(done, cycle(2, 2, 'active'))).toMatchObject({
      completed: { run: 2 },
      setCompleted: true
    })
  })

  it('refuses to submit without a puzzle', async () => {
    await expect(
      useWoodpeckerStore().submit({
        moves: [],
        hintLevel: 0,
        solutionShown: false
      })
    ).rejects.toThrow()
  })

  it('forgets everything when signed out', async () => {
    woodpeckerApi.sets.mockResolvedValue([set([])])
    const auth = useAuthStore()
    auth.accessToken = 'token'
    const store = useWoodpeckerStore()
    await store.fetchSets()

    auth.accessToken = null
    await nextTick()

    expect(store.sets).toEqual([])
    expect(store.set).toBeNull()
  })
})

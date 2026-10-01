import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'

vi.mock('@/services/api', () => ({
  repertoireApi: { explorer: vi.fn(), cloudEval: vi.fn() }
}))

const { repertoireApi } = await import('@/services/api')
const { useExplorer, useCloudEval, describeFailure, QUERY_DELAY_MS } =
  await import('@/composables/repertoire/useExplorer')

const A = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -'
const B = 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -'

/** Runs a composable inside an effect scope (disposed after each test). */
let scope
function mount(factory) {
  scope = effectScope()
  return scope.run(factory)
}

/** Lets the timers and the promises they start run. */
async function settle(ms = QUERY_DELAY_MS) {
  await vi.advanceTimersByTimeAsync(ms)
  await nextTick()
}

describe('useExplorer', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.resetAllMocks()
    localStorage.clear()
  })
  afterEach(() => {
    scope?.stop()
    vi.useRealTimers()
  })

  it('asks only for the position the user stops on, then serves it from its cache', async () => {
    repertoireApi.explorer.mockImplementation((source, fen) =>
      Promise.resolve({ fen, source, moves: [] })
    )
    const fen = ref(A)
    const explorer = mount(() => useExplorer(fen, ref(true)))

    expect(explorer.loading.value).toBe(true)
    await settle(100)
    fen.value = B
    await nextTick()
    await settle()
    expect(repertoireApi.explorer).toHaveBeenCalledTimes(1)
    expect(repertoireApi.explorer).toHaveBeenCalledWith(
      'masters',
      B,
      {},
      expect.any(AbortSignal)
    )
    expect(explorer.data.value.fen).toBe(B)
    expect(explorer.loading.value).toBe(false)

    fen.value = A
    await nextTick()
    await settle()
    fen.value = B
    await nextTick()
    expect(explorer.data.value.fen).toBe(B)
    expect(explorer.loading.value).toBe(false)
    expect(repertoireApi.explorer).toHaveBeenCalledTimes(2)
  })

  it('drops an answer that arrives for a position left meanwhile', async () => {
    let resolveFirst
    repertoireApi.explorer
      .mockImplementationOnce(
        () => new Promise(resolve => (resolveFirst = resolve))
      )
      .mockResolvedValueOnce({ fen: B, moves: [] })
    const fen = ref(A)
    const explorer = mount(() => useExplorer(fen, ref(true)))
    await settle()
    const firstSignal = repertoireApi.explorer.mock.calls[0][3]

    fen.value = B
    await nextTick()
    expect(firstSignal.aborted).toBe(true)
    resolveFirst({ fen: A, moves: [] })
    await settle()

    expect(explorer.data.value.fen).toBe(B)
  })

  it('asks nothing while hidden, and sends the Lichess filters sorted', async () => {
    repertoireApi.explorer.mockResolvedValue({ moves: [] })
    const enabled = ref(false)
    const explorer = mount(() => useExplorer(ref(A), enabled))
    await settle()
    expect(repertoireApi.explorer).not.toHaveBeenCalled()
    expect(explorer.loading.value).toBe(false)

    explorer.source.value = 'lichess'
    explorer.speeds.value = ['rapid', 'blitz']
    explorer.ratings.value = [2000, 1800]
    enabled.value = true
    await nextTick()
    await settle()

    expect(repertoireApi.explorer).toHaveBeenCalledWith(
      'lichess',
      A,
      { speeds: ['blitz', 'rapid'], ratings: [1800, 2000] },
      expect.any(AbortSignal)
    )
    expect(JSON.parse(localStorage.getItem('chessmate.explorer'))).toEqual({
      source: 'lichess',
      speeds: ['rapid', 'blitz'],
      ratings: [2000, 1800]
    })
  })

  it('reports a failure and asks again on retry', async () => {
    repertoireApi.cloudEval
      .mockRejectedValueOnce({
        response: {
          status: 503,
          headers: { 'x-lichess-unavailable': 'busy', 'retry-after': '5' }
        }
      })
      .mockResolvedValueOnce({ found: false, lines: [] })
    const cloud = mount(() => useCloudEval(ref(A), ref(true)))
    await settle()

    expect(cloud.failure.value).toMatchObject({ reason: 'busy', retryAfter: 5 })
    cloud.retry()
    await settle(0)
    expect(cloud.failure.value).toBeNull()
    expect(cloud.data.value).toEqual({ found: false, lines: [] })
    expect(repertoireApi.cloudEval).toHaveBeenLastCalledWith(
      A,
      3,
      expect.any(AbortSignal)
    )
  })
})

describe('describeFailure', () => {
  it('tells each reason apart', () => {
    const unavailable = reason => ({
      response: {
        status: 503,
        headers: reason ? { 'x-lichess-unavailable': reason } : {}
      }
    })

    expect(describeFailure(unavailable('no_token')).message).toContain(
      'liez le vôtre'
    )
    expect(describeFailure(unavailable('rate_limited')).reason).toBe(
      'rate_limited'
    )
    expect(describeFailure(unavailable(null)).reason).toBe('down')
    expect(describeFailure(unavailable('surprise')).reason).toBe('down')
    expect(
      describeFailure({ response: { status: 429, headers: {} } }).reason
    ).toBe('too_many')
    expect(
      describeFailure({ response: { status: 422, headers: {} } }).reason
    ).toBe('invalid')
    expect(describeFailure(new Error('Network Error')).reason).toBe('offline')
  })
})

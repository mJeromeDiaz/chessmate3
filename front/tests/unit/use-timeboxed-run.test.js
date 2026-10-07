import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useTimeboxedRun } from '@/composables/training/useTimeboxedRun'

const T0 = Date.parse('2026-09-28T10:00:00Z')
const iso = ms => new Date(ms).toISOString()

/** A run as the API returns it; `serverNow` in ms on the server's clock. */
function runJson({
  status = 'active',
  expiresAt,
  serverNow,
  summary = null,
  closeReason = null
}) {
  return {
    id: 'r1',
    module: 'woodpecker',
    subjectType: 'woodpecker_set',
    subjectId: 's1',
    status,
    closeReason,
    budgetSeconds: 120,
    startedAt: iso(T0),
    expiresAt: iso(expiresAt),
    closedAt: status === 'closed' ? iso(expiresAt) : null,
    serverNow: iso(serverNow),
    summary
  }
}
const item = id => ({
  id,
  type: 'woodpecker_puzzle',
  data: { puzzle: { id: `p-${id}` } }
})
const SUMMARY = {
  durationMs: 120_000,
  itemCount: 3,
  successCount: 2,
  failureCount: 1,
  successRate: 0.6667,
  itemsPerMinute: 1.5,
  metrics: {},
  context: {}
}
const conflict = () =>
  Object.assign(new Error('409'), { response: { status: 409 } })

/** A promise settled after `ms` of (fake) time. */
const later = (ms, value) =>
  new Promise(resolve => setTimeout(() => resolve(value), ms))

function mockApi() {
  return {
    start: vi.fn(),
    get: vi.fn(),
    next: vi.fn(),
    submit: vi.fn(),
    stop: vi.fn(),
    current: vi.fn()
  }
}

describe('useTimeboxedRun', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(T0)
  })
  afterEach(() => vi.useRealTimers())

  /** Starts a 120 s run; the server's clock is `skewMs` ahead of the client's. */
  async function started(api, skewMs = 0) {
    const expiresAt = T0 + skewMs + 120_000
    api.start.mockImplementation(async () =>
      runJson({ expiresAt, serverNow: Date.now() + skewMs })
    )
    api.next.mockImplementation(async () => ({
      run: runJson({ expiresAt, serverNow: Date.now() + skewMs }),
      item: item('a1')
    }))
    const runner = useTimeboxedRun({ api })
    await runner.start({
      module: 'woodpecker',
      subjectId: 's1',
      budgetSeconds: 120
    })
    return { runner, expiresAt }
  }

  it('counts down on the server clock, whatever the client clock says', async () => {
    const api = mockApi()
    // The client is 5 minutes late: its own clock would show 7 minutes left.
    const { runner } = await started(api, 300_000)

    expect(runner.offsetMs.value).toBe(300_000)
    expect(runner.remainingMs.value).toBe(120_000)
    expect(runner.phase.value).toBe('running')
    expect(runner.item.value.id).toBe('a1')

    await vi.advanceTimersByTimeAsync(30_000)
    expect(runner.remainingMs.value).toBe(90_000)
    runner.dispose()
  })

  it('keeps the offset measured on the fastest round trip', async () => {
    const api = mockApi()
    const expiresAt = T0 + 120_000
    // Slow answer (2 s): the server answered 1.5 s after the request, not at the midpoint.
    api.start.mockImplementation(() =>
      later(2000, runJson({ expiresAt, serverNow: T0 + 1500 }))
    )
    api.next.mockImplementation(async () => ({
      run: runJson({ expiresAt, serverNow: Date.now() }),
      item: item('a1')
    }))
    const runner = useTimeboxedRun({ api })
    const starting = runner.start({
      module: 'woodpecker',
      subjectId: 's1',
      budgetSeconds: 120
    })
    await vi.advanceTimersByTimeAsync(2000)
    await starting

    // The fast `next` answer replaced the 500 ms error of the slow one.
    expect(runner.offsetMs.value).toBe(0)

    api.next.mockImplementation(() =>
      later(3000, {
        run: runJson({ expiresAt, serverNow: Date.now() + 9000 }),
        item: item('a1')
      })
    )
    const pending = runner.next()
    await vi.advanceTimersByTimeAsync(3000)
    await pending
    expect(runner.offsetMs.value).toBe(0)
    runner.dispose()
  })

  it('at zero, lets the server close the run and shows the recap', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    api.next.mockResolvedValue({
      run: runJson({
        status: 'closed',
        closeReason: 'time_up',
        expiresAt,
        serverNow: expiresAt,
        summary: SUMMARY
      }),
      item: null
    })

    await vi.advanceTimersByTimeAsync(119_000)
    expect(runner.phase.value).toBe('running')
    await vi.advanceTimersByTimeAsync(1_250)

    expect(runner.phase.value).toBe('ended')
    expect(runner.item.value).toBeNull()
    expect(runner.summary.value).toEqual(SUMMARY)
    const calls = api.next.mock.calls.length
    await vi.advanceTimersByTimeAsync(10_000)
    expect(api.next.mock.calls.length).toBe(calls)
  })

  it('still sends a submission already on its way at zero, then closes', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    await vi.advanceTimersByTimeAsync(119_500)
    api.submit.mockImplementation(() =>
      later(1500, {
        run: runJson({
          status: 'closed',
          closeReason: 'time_up',
          expiresAt,
          serverNow: expiresAt + 1000,
          summary: SUMMARY
        }),
        result: { itemId: 'a1', success: true, data: {} }
      })
    )

    const submitting = runner.submit({
      moves: ['e2e4'],
      hintLevel: 0,
      solutionShown: false
    })
    await vi.advanceTimersByTimeAsync(1000)
    expect(runner.phase.value).toBe('timeUp')
    expect(api.next).toHaveBeenCalledTimes(1) // not asked while the submission travels
    await vi.advanceTimersByTimeAsync(500)
    await submitting

    expect(api.submit).toHaveBeenCalledWith('r1', {
      itemId: 'a1',
      moves: ['e2e4'],
      hintLevel: 0,
      solutionShown: false
    })
    expect(runner.result.value.success).toBe(true)
    expect([runner.played.value, runner.solved.value]).toEqual([1, 1])
    expect(runner.phase.value).toBe('ended')
  })

  it('shows the recap when the server refuses a late submission', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    api.submit.mockRejectedValue(conflict())
    api.get.mockResolvedValue(
      runJson({
        status: 'closed',
        closeReason: 'time_up',
        expiresAt,
        serverNow: expiresAt + 3000,
        summary: SUMMARY
      })
    )

    expect(
      await runner.submit({ moves: [], hintLevel: 0, solutionShown: false })
    ).toBeNull()

    expect(runner.phase.value).toBe('ended')
    expect(runner.played.value).toBe(0)
  })

  it('moves on when the item can no longer be submitted but the run goes on', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    api.submit.mockRejectedValue(conflict())
    api.get.mockResolvedValue(runJson({ expiresAt, serverNow: Date.now() }))
    api.next.mockResolvedValue({
      run: runJson({ expiresAt, serverNow: Date.now() }),
      item: item('a2')
    })

    await runner.submit({ moves: [], hintLevel: 0, solutionShown: false })

    expect(runner.phase.value).toBe('running')
    expect(runner.item.value.id).toBe('a2')
    runner.dispose()
  })

  it('keeps playing when the server still had a few ms, then closes', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    api.next
      // The server answers 200 ms before its expiry: the offset is corrected, time is left.
      .mockResolvedValueOnce({
        run: runJson({ expiresAt, serverNow: expiresAt - 200 }),
        item: item('a2')
      })
      .mockResolvedValue({
        run: runJson({
          status: 'closed',
          closeReason: 'time_up',
          expiresAt,
          serverNow: expiresAt + 800,
          summary: SUMMARY
        }),
        item: null
      })

    await vi.advanceTimersByTimeAsync(120_000)
    expect(runner.phase.value).toBe('running')
    expect(runner.item.value.id).toBe('a2')

    await vi.advanceTimersByTimeAsync(1_500)
    expect(runner.phase.value).toBe('ended')
  })

  it('resumes a run after a reload, with the item in progress', async () => {
    const api = mockApi()
    const expiresAt = T0 + 120_000
    api.get.mockResolvedValue(runJson({ expiresAt, serverNow: T0 }))
    api.next.mockResolvedValue({
      run: runJson({ expiresAt, serverNow: T0 }),
      item: item('a7')
    })
    const runner = useTimeboxedRun({ api })

    await runner.resume('r1')

    expect(api.get).toHaveBeenCalledWith('r1')
    expect(runner.item.value.id).toBe('a7')
    runner.dispose()
  })

  it('does not ask for an item when resuming a closed run', async () => {
    const api = mockApi()
    api.get.mockResolvedValue(
      runJson({
        status: 'closed',
        closeReason: 'stopped',
        expiresAt: T0 + 120_000,
        serverNow: T0 + 200_000,
        summary: SUMMARY
      })
    )
    const runner = useTimeboxedRun({ api })

    await runner.resume('r1')

    expect(runner.phase.value).toBe('ended')
    expect(api.next).not.toHaveBeenCalled()
  })

  it('stops the run on demand', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    api.stop.mockResolvedValue(
      runJson({
        status: 'closed',
        closeReason: 'stopped',
        expiresAt,
        serverNow: Date.now(),
        summary: SUMMARY
      })
    )

    await runner.stop()

    expect(api.stop).toHaveBeenCalledWith('r1')
    expect(runner.phase.value).toBe('ended')
    expect(runner.item.value).toBeNull()
  })

  it('lets a player send its last answers before the run closes', async () => {
    const api = mockApi()
    const { runner, expiresAt } = await started(api)
    const order = []
    const hook = vi.fn(async () => {
      await later(100)
      order.push('hook')
    })
    const unregister = runner.beforeClose(hook)
    api.next.mockImplementation(async () => {
      order.push('next')
      return {
        run: runJson({
          status: 'closed',
          closeReason: 'time_up',
          expiresAt,
          serverNow: expiresAt,
          summary: SUMMARY
        }),
        item: null
      }
    })

    await vi.advanceTimersByTimeAsync(121_000)

    expect(runner.phase.value).toBe('ended')
    expect(order).toEqual(['hook', 'next'])

    // Before "Terminer" too, and no more once unregistered.
    vi.setSystemTime(T0)
    const second = await started(api)
    api.stop.mockImplementation(async () => {
      order.push('stop')
      return runJson({
        status: 'closed',
        closeReason: 'stopped',
        expiresAt: second.expiresAt,
        serverNow: Date.now(),
        summary: SUMMARY
      })
    })
    const failing = vi.fn(async () => {
      order.push('failing')
      throw new Error('network')
    })
    second.runner.beforeClose(failing)
    await second.runner.stop()
    expect(order.slice(-2)).toEqual(['failing', 'stop'])
    expect(second.runner.phase.value).toBe('ended')

    unregister()
    expect(hook).toHaveBeenCalledTimes(1)
  })
})

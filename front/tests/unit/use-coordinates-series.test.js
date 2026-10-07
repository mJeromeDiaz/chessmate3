import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useCoordinatesSeries } from '@/composables/coordinates/useCoordinatesSeries'

const SQUARES = ['e4', 'd5', 'a1', 'h8', 'c3']

/** A runner whose submissions are recorded; `beforeClose` keeps the hook to call it. */
function mockRunner() {
  const runner = {
    submit: vi.fn(async () => ({ success: true })),
    /** @type {(() => Promise<void>)|null} */
    hook: null,
    beforeClose: vi.fn(hook => {
      runner.hook = hook
      return () => (runner.hook = null)
    })
  }
  return runner
}

describe('useCoordinatesSeries', () => {
  /** @type {import('vue').EffectScope} */
  let scope
  let clock = 0

  beforeEach(() => {
    vi.useFakeTimers()
    clock = 0
    scope = effectScope()
  })
  afterEach(() => {
    scope.stop()
    vi.useRealTimers()
  })

  /** @param {ReturnType<typeof mockRunner>} runner @param {object} [options] */
  function series(runner, options = {}) {
    const s = scope.run(() =>
      useCoordinatesSeries(runner, { now: () => clock, ...options })
    )
    s.load({ data: { squares: SQUARES, answered: 0, successCount: 0 } })
    return s
  }

  it('judges each click at once and moves on to the next square', () => {
    const s = series(mockRunner())
    expect(s.target.value).toBe('e4')

    clock = 1200
    expect(s.answer('e4')).toBe(true)
    clock = 2000
    expect(s.answer('d4')).toBe(false)

    expect(s.target.value).toBe('a1')
    expect([s.answered.value, s.correct.value, s.unsent.value]).toEqual([
      2, 1, 2
    ])
    expect(s.last.value).toEqual({
      target: 'd5',
      clicked: 'd4',
      correct: false
    })
  })

  it('sends the answers in batches, with the time spent on each square', async () => {
    const runner = mockRunner()
    const s = series(runner, { batchMs: 3000 })
    clock = 900
    s.answer('e4')
    clock = 2400
    s.answer('d5')
    expect(runner.submit).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(3000)

    expect(runner.submit).toHaveBeenCalledTimes(1)
    expect(runner.submit).toHaveBeenCalledWith({
      answers: [
        { index: 0, square: 'e4', ms: 900 },
        { index: 1, square: 'd5', ms: 1500 }
      ]
    })
    expect(s.unsent.value).toBe(0)
    await vi.advanceTimersByTimeAsync(3000)
    expect(runner.submit).toHaveBeenCalledTimes(1)
  })

  it('keeps a batch that failed and sends it again with the next answers', async () => {
    const runner = mockRunner()
    runner.submit.mockRejectedValueOnce(new Error('network'))
    const s = series(runner)
    s.answer('e4')

    await expect(s.flush()).rejects.toThrow('network')
    expect(s.unsent.value).toBe(1)
    s.answer('d5')
    await s.flush()

    expect(runner.submit).toHaveBeenLastCalledWith({
      answers: [
        { index: 0, square: 'e4', ms: 0 },
        { index: 1, square: 'd5', ms: 0 }
      ]
    })
    expect(s.unsent.value).toBe(0)
  })

  it('splits a long backlog and sends a full batch at once', async () => {
    const runner = mockRunner()
    const s = series(runner, { maxBatch: 2 })
    s.answer('e4')
    expect(runner.submit).not.toHaveBeenCalled()
    s.answer('d5')
    await vi.advanceTimersByTimeAsync(0)
    expect(runner.submit).toHaveBeenCalledTimes(1)

    s.answer('a1')
    s.answer('h8')
    s.answer('c3')
    await s.flush()
    expect(
      runner.submit.mock.calls.map(c => c[0].answers.map(a => a.index))
    ).toEqual([[0, 1], [2, 3], [4]])
  })

  it('sends what is left before the run closes, and nothing past the last square', async () => {
    const runner = mockRunner()
    const s = series(runner)
    for (const square of SQUARES) s.answer(square)
    expect(s.target.value).toBeNull()
    expect(s.answer('e4')).toBeNull()

    await runner.hook?.()
    expect(runner.submit).toHaveBeenCalledTimes(1)
    expect(runner.submit.mock.calls[0][0].answers).toHaveLength(5)
  })

  it('resumes after the answers the server already judged', () => {
    const s = scope.run(() => useCoordinatesSeries(mockRunner()))
    s.load({ data: { squares: SQUARES, answered: 3, successCount: 2 } })

    expect([s.target.value, s.answered.value, s.correct.value]).toEqual([
      'h8',
      3,
      2
    ])
  })

  it('stops sending and unregisters when its scope ends', async () => {
    const runner = mockRunner()
    const s = series(runner)
    s.answer('e4')
    scope.stop()

    await vi.advanceTimersByTimeAsync(10_000)
    expect(runner.submit).not.toHaveBeenCalled()
    expect(runner.hook).toBeNull()
  })
})

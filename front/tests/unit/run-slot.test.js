import { describe, expect, it, vi } from 'vitest'
import { closingOpenRun, isRunInProgress } from '@/utils/training'

/** @param {number} status @param {string} detail */
const refusal = (status, detail) =>
  Object.assign(new Error(detail), { response: { status, data: { detail } } })

describe('closingOpenRun', () => {
  it('stops the run left open, then starts once more', async () => {
    const start = vi
      .fn()
      .mockRejectedValueOnce(refusal(409, 'run_in_progress'))
      .mockResolvedValueOnce({ run: { id: 'new' } })
    const runs = {
      current: vi.fn().mockResolvedValue({ id: 'old' }),
      stop: vi.fn().mockResolvedValue({})
    }
    await expect(closingOpenRun(start, runs)).resolves.toEqual({
      run: { id: 'new' }
    })
    expect(runs.stop).toHaveBeenCalledWith('old')
    expect(start).toHaveBeenCalledTimes(2)
  })

  it('starts again when the open run closed meanwhile, or its stop failed', async () => {
    const start = vi
      .fn()
      .mockRejectedValueOnce(refusal(409, 'run_in_progress'))
      .mockResolvedValueOnce('started')
    const runs = { current: vi.fn().mockResolvedValue(null), stop: vi.fn() }
    await expect(closingOpenRun(start, runs)).resolves.toBe('started')
    expect(runs.stop).not.toHaveBeenCalled()

    const failing = {
      current: vi.fn().mockResolvedValue({ id: 'old' }),
      stop: vi.fn().mockRejectedValue(new Error('Network'))
    }
    const again = vi
      .fn()
      .mockRejectedValueOnce(refusal(409, 'run_in_progress'))
      .mockResolvedValueOnce('started')
    await expect(closingOpenRun(again, failing)).resolves.toBe('started')
  })

  it('lets any other refusal through, and tries once more only', async () => {
    const locked = refusal(409, 'step_locked')
    const runs = { current: vi.fn(), stop: vi.fn() }
    await expect(
      closingOpenRun(() => Promise.reject(locked), runs)
    ).rejects.toBe(locked)
    expect(runs.current).not.toHaveBeenCalled()

    const still = refusal(409, 'run_in_progress')
    const start = vi.fn().mockRejectedValue(still)
    await expect(
      closingOpenRun(start, {
        current: vi.fn().mockResolvedValue({ id: 'old' }),
        stop: vi.fn().mockResolvedValue({})
      })
    ).rejects.toBe(still)
    expect(start).toHaveBeenCalledTimes(2)
  })

  it('knows the refusal by its code', () => {
    expect(isRunInProgress(refusal(409, 'run_in_progress'))).toBe(true)
    expect(isRunInProgress(refusal(409, 'step_soon'))).toBe(false)
    expect(isRunInProgress(new Error('Network'))).toBe(false)
  })
})

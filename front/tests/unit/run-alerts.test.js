import { describe, expect, it, vi } from 'vitest'
import { computed, nextTick, ref } from 'vue'
import { WARNING_MS, useRunAlerts } from '@/composables/training/useRunAlerts'

/**
 * A runner stub: the run, its remaining time and phase as the page sees them.
 *
 * @param {Record<string, any>} run
 */
function fakeRunner(run) {
  const current = ref({ id: 'r1', status: 'active', closeReason: null, ...run })
  const remainingMs = ref(60_000)
  const ended = ref(false)
  const phase = computed(() =>
    ended.value ? 'ended' : remainingMs.value > 0 ? 'running' : 'timeUp'
  )
  return { run: current, remainingMs, phase, ended }
}

async function tick(runner, ms) {
  runner.remainingMs.value = ms
  await nextTick()
}

describe('run alerts', () => {
  it('sounds once a few seconds before the end of a session module', async () => {
    const play = vi.fn()
    const runner = fakeRunner({ module: 'puzzles', parentId: 's1' })
    useRunAlerts(/** @type {any} */ (runner), { play, show: vi.fn() })

    await tick(runner, WARNING_MS + 1000)
    expect(play).not.toHaveBeenCalled()
    await tick(runner, WARNING_MS - 250)
    await tick(runner, 1000)
    expect(play).toHaveBeenCalledTimes(1)
  })

  it('stays silent for a run outside a session', async () => {
    const play = vi.fn()
    const runner = fakeRunner({ module: 'woodpecker', parentId: null })
    useRunAlerts(/** @type {any} */ (runner), { play, show: vi.fn() })

    await tick(runner, 1000)
    await tick(runner, 0)
    expect(play).not.toHaveBeenCalled()
  })

  it('sounds and notifies when free study is over', async () => {
    const play = vi.fn()
    const show = vi.fn()
    const runner = fakeRunner({ module: 'free', parentId: null })
    useRunAlerts(/** @type {any} */ (runner), { play, show })

    await tick(runner, 30_000)
    await tick(runner, 0)
    runner.run.value = {
      ...runner.run.value,
      status: 'closed',
      closeReason: 'time_up'
    }
    runner.ended.value = true
    await nextTick()

    expect(play).toHaveBeenCalledTimes(1)
    expect(show).toHaveBeenCalledWith('Temps libre terminé', expect.any(String))
  })

  it('says nothing for a free run stopped early or reopened once over', async () => {
    const play = vi.fn()
    const stopped = fakeRunner({ module: 'free' })
    useRunAlerts(/** @type {any} */ (stopped), { play, show: vi.fn() })
    await tick(stopped, 30_000)
    stopped.run.value = {
      ...stopped.run.value,
      status: 'closed',
      closeReason: 'stopped'
    }
    stopped.ended.value = true
    await nextTick()

    const reopened = fakeRunner({
      module: 'free',
      status: 'closed',
      closeReason: 'time_up'
    })
    reopened.ended.value = true
    useRunAlerts(/** @type {any} */ (reopened), { play, show: vi.fn() })
    await nextTick()

    expect(play).not.toHaveBeenCalled()
  })
})

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
    useRunAlerts(/** @type {any} */ (runner), {
      play,
      show: vi.fn(),
      playEnd: vi.fn()
    })

    await tick(runner, WARNING_MS + 1000)
    expect(play).not.toHaveBeenCalled()
    await tick(runner, WARNING_MS - 250)
    await tick(runner, 1000)
    expect(play).toHaveBeenCalledTimes(1)
  })

  it('stays silent for a run outside a session', async () => {
    const play = vi.fn()
    const runner = fakeRunner({ module: 'woodpecker', parentId: null })
    useRunAlerts(/** @type {any} */ (runner), {
      play,
      show: vi.fn(),
      playEnd: vi.fn()
    })

    await tick(runner, 1000)
    await tick(runner, 0)
    expect(play).not.toHaveBeenCalled()
  })

  it('sounds and notifies when free study is over', async () => {
    const play = vi.fn()
    const show = vi.fn()
    const runner = fakeRunner({ module: 'free', parentId: null })
    useRunAlerts(/** @type {any} */ (runner), { play, show, playEnd: vi.fn() })

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
    useRunAlerts(/** @type {any} */ (stopped), {
      play,
      show: vi.fn(),
      playEnd: vi.fn()
    })
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
    useRunAlerts(/** @type {any} */ (reopened), {
      play,
      show: vi.fn(),
      playEnd: vi.fn()
    })
    await nextTick()

    expect(play).not.toHaveBeenCalled()
  })

  /**
   * Closes a run seen running, with its recap.
   *
   * @param {ReturnType<typeof fakeRunner>} runner
   * @param {{itemCount: number, successCount: number}} summary
   */
  async function close(runner, summary) {
    await tick(runner, 30_000)
    runner.run.value = {
      ...runner.run.value,
      status: 'closed',
      closeReason: 'stopped',
      summary
    }
    runner.ended.value = true
    await nextTick()
  }

  it('sounds the outcome of a module, once', async () => {
    const playEnd = vi.fn()
    const passed = fakeRunner({ module: 'puzzles', parentId: null })
    useRunAlerts(/** @type {any} */ (passed), {
      play: vi.fn(),
      show: vi.fn(),
      playEnd
    })
    await close(passed, { itemCount: 5, successCount: 4 })
    passed.ended.value = false
    await nextTick()
    passed.ended.value = true
    await nextTick()

    const failed = fakeRunner({
      id: 'r2',
      module: 'woodpecker',
      parentId: null
    })
    useRunAlerts(/** @type {any} */ (failed), {
      play: vi.fn(),
      show: vi.fn(),
      playEnd
    })
    await close(failed, { itemCount: 5, successCount: 3 })

    expect(playEnd.mock.calls).toEqual([['moduleDone'], ['moduleFailed']])
  })

  it('says nothing for a run reopened once over', async () => {
    const playEnd = vi.fn()
    const reopened = fakeRunner({
      module: 'puzzles',
      status: 'closed',
      summary: { itemCount: 5, successCount: 5 }
    })
    reopened.ended.value = true
    useRunAlerts(/** @type {any} */ (reopened), {
      play: vi.fn(),
      show: vi.fn(),
      playEnd
    })
    await nextTick()

    expect(playEnd).not.toHaveBeenCalled()
  })

  it('waits for the session of a session step: the last module sounds the session', async () => {
    const playEnd = vi.fn()
    const session = ref(/** @type {any} */ (null))
    const runner = fakeRunner({ module: 'puzzles', parentId: 's1' })
    useRunAlerts(/** @type {any} */ (runner), {
      play: vi.fn(),
      show: vi.fn(),
      playEnd,
      session
    })
    await close(runner, { itemCount: 5, successCount: 1 })
    expect(playEnd).not.toHaveBeenCalled()

    session.value = { id: 's1', status: 'completed' }
    await nextTick()
    session.value = { id: 's1', status: 'completed' }
    await nextTick()

    expect(playEnd.mock.calls).toEqual([['sessionDone']])
  })

  it('sounds the module of a session that goes on', async () => {
    const playEnd = vi.fn()
    const session = ref(/** @type {any} */ (null))
    const runner = fakeRunner({ module: 'repertoire', parentId: 's1' })
    useRunAlerts(/** @type {any} */ (runner), {
      play: vi.fn(),
      show: vi.fn(),
      playEnd,
      session
    })
    await close(runner, { itemCount: 4, successCount: 1 })
    session.value = { id: 's1', status: 'active' }
    await nextTick()

    expect(playEnd.mock.calls).toEqual([['moduleFailed']])
  })
})

import { watch } from 'vue'
import { notify, playAlert } from '@/utils/alerts'
import { moduleEndSound, playOutcomeSound } from '@/utils/sounds'

/** How long before the end of a session module the alert sounds. */
export const WARNING_MS = 5_000

/**
 * Sound and notification around the end of a timed run: a session module sounds a few seconds
 * before its end; free study sounds and notifies when its time is up. Once closed, every run
 * sounds its outcome (module done or failed, see `moduleEndSound`), or the session's when it was
 * the session's last module (known once the page has reloaded the session, given as `session`).
 * Each sound at most once per run, and only for a run seen running on this page (reopening a
 * finished run says nothing).
 *
 * @param {ReturnType<typeof import('./useTimeboxedRun').useTimeboxedRun>} runner
 * @param {object} [options]
 * @param {typeof playAlert} [options.play]
 * @param {typeof notify} [options.show]
 * @param {typeof playOutcomeSound} [options.playEnd]
 * @param {import('vue').Ref<{id: string, status: string}|null>} [options.session] the session
 *   of a run that is a session step, reloaded by the page once the run is over
 */
export function useRunAlerts(runner, options = {}) {
  const {
    play = playAlert,
    show = notify,
    playEnd = playOutcomeSound,
    session
  } = options
  /** Runs seen running here. @type {Set<string>} */
  const seenRunning = new Set()
  /** @type {Set<string>} */
  const warned = new Set()
  /** @type {Set<string>} */
  const ended = new Set()
  /** Runs whose outcome has sounded. @type {Set<string>} */
  const closed = new Set()
  /** A closed session step waiting for its session. @type {import('./useTimeboxedRun').TrainingRun|null} */
  let awaitingSession = null

  watch(
    () => runner.remainingMs.value,
    remaining => {
      const run = runner.run.value
      if (!run || run.status !== 'active') return
      if (runner.phase.value === 'running') seenRunning.add(run.id)
      if (!run.parentId || warned.has(run.id)) return
      if (remaining > 0 && remaining <= WARNING_MS) {
        warned.add(run.id)
        play()
      }
    }
  )

  watch(
    () => runner.phase.value,
    phase => {
      const run = runner.run.value
      if (!run || run.module !== 'free') return
      if (!seenRunning.has(run.id) || ended.has(run.id)) return
      const timeUp =
        phase === 'timeUp' ||
        (phase === 'ended' && run.closeReason === 'time_up')
      if (!timeUp) return
      ended.add(run.id)
      play()
      show('Temps libre terminé', 'Ton module libre est fini.')
    }
  )

  watch(
    () => runner.phase.value,
    phase => {
      const run = runner.run.value
      if (phase !== 'ended' || !run) return
      if (!seenRunning.has(run.id) || closed.has(run.id)) return
      closed.add(run.id)
      if (run.parentId && session) {
        awaitingSession = run
        soundSession()
        return
      }
      playEnd(moduleEndSound(run))
    }
  )

  if (session) watch(session, soundSession)

  function soundSession() {
    const run = awaitingSession
    const current = session?.value
    if (!run || !current || current.id !== run.parentId) return
    awaitingSession = null
    playEnd(
      current.status === 'completed' ? 'sessionDone' : moduleEndSound(run)
    )
  }
}

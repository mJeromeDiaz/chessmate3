import { watch } from 'vue'
import { notify, playAlert } from '@/utils/alerts'

/** How long before the end of a session module the alert sounds. */
export const WARNING_MS = 5_000

/**
 * Sound and notification around the end of a timed run: a session module sounds a few seconds
 * before its end; free study sounds and notifies when its time is up. Each alert at most once per
 * run, and only for a run seen running on this page (reopening a finished run says nothing).
 *
 * @param {ReturnType<typeof import('./useTimeboxedRun').useTimeboxedRun>} runner
 * @param {object} [options]
 * @param {typeof playAlert} [options.play]
 * @param {typeof notify} [options.show]
 */
export function useRunAlerts(runner, options = {}) {
  const { play = playAlert, show = notify } = options
  /** Runs seen running here. @type {Set<string>} */
  const seenRunning = new Set()
  /** @type {Set<string>} */
  const warned = new Set()
  /** @type {Set<string>} */
  const ended = new Set()

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
}

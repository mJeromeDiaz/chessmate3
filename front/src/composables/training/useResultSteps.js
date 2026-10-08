import { onBeforeUnmount, ref } from 'vue'
import { prefersReducedMotion } from '@/utils/runResult'

/**
 * The steps of an end-of-run screen's animation (designs "Résultat de leçon", "Corriger ses
 * erreurs"): `step` goes from 0 to the number of steps at the given times, and from `countStep`
 * `k` goes from 0 to 1 in `countMs` (the counter). With reduced motion, the last step at once.
 *
 * @param {number[]} stepsMs when steps 1, 2… begin
 * @param {number} countStep
 * @param {number} countMs
 */
export function useResultSteps(stepsMs, countStep, countMs) {
  const step = ref(0)
  const k = ref(0)
  /** Bumped at each replay: keys the parts that restart their own animation. */
  const run = ref(0)
  /** @type {ReturnType<typeof setTimeout>[]} */
  let timers = []
  let frame = 0

  function stop() {
    timers.forEach(clearTimeout)
    timers = []
    cancelAnimationFrame(frame)
  }

  function count() {
    const t0 = performance.now()
    const tick = (/** @type {number} */ now) => {
      k.value = Math.min(1, (now - t0) / countMs)
      if (k.value < 1) frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
  }

  function play() {
    stop()
    run.value++
    if (prefersReducedMotion()) {
      step.value = stepsMs.length
      k.value = 1
      return
    }
    step.value = 0
    k.value = 0
    timers = stepsMs.map((ms, i) =>
      setTimeout(() => {
        step.value = i + 1
        if (i + 1 === countStep) count()
      }, ms)
    )
  }

  onBeforeUnmount(stop)

  return { step, k, run, play, stop }
}

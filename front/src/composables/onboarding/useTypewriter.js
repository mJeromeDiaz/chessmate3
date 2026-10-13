import { onBeforeUnmount, ref, watch } from 'vue'
import { prefersReducedMotion } from '@/utils/runResult'

/** Milliseconds per letter. */
const LETTER_MS = 18

/**
 * A line typed letter by letter, started again each time it changes (design "Visite guidée"). With
 * reduced motion, the whole line at once.
 *
 * @param {import('vue').Ref<string>|(() => string)} source
 */
export function useTypewriter(source) {
  const shown = ref('')
  const typing = ref(false)
  /** @type {ReturnType<typeof setInterval>|undefined} */
  let timer

  function stop() {
    clearInterval(timer)
    typing.value = false
  }

  watch(
    source,
    text => {
      stop()
      if (prefersReducedMotion() || !text) {
        shown.value = text ?? ''
        return
      }
      let n = 0
      shown.value = ''
      typing.value = true
      timer = setInterval(() => {
        n += 1
        shown.value = text.slice(0, n)
        if (n >= text.length) stop()
      }, LETTER_MS)
    },
    { immediate: true }
  )

  onBeforeUnmount(stop)

  return { shown, typing }
}

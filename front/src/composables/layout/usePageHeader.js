import { onBeforeUnmount, watchEffect } from 'vue'
import { useLayoutStore } from '@/stores/layout'

/**
 * Gives the header a title or a subtitle known only once the page's data is loaded; a missing
 * text (null, still loading) falls back to the route's `meta.header` key. Cleared when the page
 * leaves.
 *
 * @param {() => {title?: string|null, subtitle?: string|null}} header texts, already translated
 */
export function usePageHeader(header) {
  const layout = useLayoutStore()
  watchEffect(() => layout.set(header()))
  onBeforeUnmount(() => layout.clear())
}

import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * The header's title and subtitle set by the page itself, when they come from data (a repertoire's
 * name, a capacity's): they win over the route's `meta.header` keys. Written by `usePageHeader`.
 */
export const useLayoutStore = defineStore('layout', () => {
  /** @type {import('vue').Ref<string|null>} */
  const title = ref(null)
  /** @type {import('vue').Ref<string|null>} */
  const subtitle = ref(null)

  /**
   * @param {{title?: string|null, subtitle?: string|null}} header texts, already translated
   */
  function set(header) {
    title.value = header.title ?? null
    subtitle.value = header.subtitle ?? null
  }

  function clear() {
    set({})
  }

  return { title, subtitle, set, clear }
})

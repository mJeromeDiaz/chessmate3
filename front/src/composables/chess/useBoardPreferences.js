import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { boardTheme } from '@/utils/chess/boardThemes'

/**
 * The signed-in user's board preferences (profile): square colours and move sounds. Visitors get
 * the default colours and no sound.
 *
 * @returns {{theme: import('vue').ComputedRef<{value: string, label: string, light: string, dark: string}>, soundOn: import('vue').ComputedRef<boolean>}}
 */
export function useBoardPreferences() {
  const auth = useAuthStore()

  return {
    theme: computed(() => boardTheme(auth.profile?.boardTheme)),
    soundOn: computed(() => auth.profile?.moveSound === true)
  }
}

import { t } from '@/i18n'

/**
 * The theme choices as shown in the header menu and on the profile page.
 *
 * @type {{value: import('@/stores/theme').Theme, label: string, icon: string}[]}
 */
export const THEME_OPTIONS = [
  {
    value: 'auto',
    get label() {
      return t('layout.theme.auto')
    },
    icon: 'brightness_auto'
  },
  {
    value: 'light',
    get label() {
      return t('layout.theme.light')
    },
    icon: 'light_mode'
  },
  {
    value: 'dark',
    get label() {
      return t('layout.theme.dark')
    },
    icon: 'dark_mode'
  }
]

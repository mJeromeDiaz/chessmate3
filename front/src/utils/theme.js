/**
 * The theme choices as shown in the header menu and on the profile page.
 *
 * @type {{value: import('@/stores/theme').Theme, label: string, icon: string}[]}
 */
export const THEME_OPTIONS = [
  { value: 'auto', label: 'Automatique', icon: 'brightness_auto' },
  { value: 'light', label: 'Clair', icon: 'light_mode' },
  { value: 'dark', label: 'Sombre', icon: 'dark_mode' }
]

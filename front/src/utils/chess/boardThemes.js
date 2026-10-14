import { t } from '@/i18n'

/**
 * @param {string} value
 * @param {string} light
 * @param {string} dark
 */
function theme(value, light, dark) {
  return {
    value,
    get label() {
      return t(`profile.preferences.boards.${value}`)
    },
    light,
    dark
  }
}

/**
 * The chessboard colours offered on the profile (design "Profil"). Keys are the API's values
 * (`boardTheme`); `light` and `dark` are the square colours.
 *
 * @type {Array<{value: string, label: string, light: string, dark: string}>}
 */
export const BOARD_THEMES = [
  theme('wood', '#F0D9B5', '#B58863'),
  theme('glass', '#E4EEF8', '#9DB8D6'),
  theme('pastel', '#FFE6F1', '#C9B8FF'),
  theme('tournament', '#EEEED2', '#769656'),
  theme('slate', '#D7D4E2', '#5E5878')
]

export const DEFAULT_BOARD_THEME = 'wood'

/**
 * The colours of a board theme (the default one for an unknown value).
 *
 * @param {string|null|undefined} value
 * @returns {{value: string, label: string, light: string, dark: string}}
 */
export function boardTheme(value) {
  return (
    BOARD_THEMES.find(t => t.value === value) ??
    BOARD_THEMES.find(t => t.value === DEFAULT_BOARD_THEME)
  )
}

/**
 * The 4×4 preview of a theme on the profile: alternating squares, light first.
 *
 * @param {{light: string, dark: string}} theme
 * @returns {string[]} 16 colours, row by row
 */
export function previewSquares(theme) {
  return Array.from({ length: 16 }, (_, i) =>
    ((i >> 2) + i) % 2 ? theme.dark : theme.light
  )
}

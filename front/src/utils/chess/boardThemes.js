/**
 * The chessboard colours offered on the profile (design "Profil"). Keys are the API's values
 * (`boardTheme`); `light` and `dark` are the square colours.
 *
 * @type {Array<{value: string, label: string, light: string, dark: string}>}
 */
export const BOARD_THEMES = [
  { value: 'wood', label: 'Bois', light: '#F0D9B5', dark: '#B58863' },
  { value: 'glass', label: 'Verre', light: '#E4EEF8', dark: '#9DB8D6' },
  { value: 'pastel', label: 'Pastel', light: '#FFE6F1', dark: '#C9B8FF' },
  { value: 'tournament', label: 'Tournoi', light: '#EEEED2', dark: '#769656' },
  { value: 'slate', label: 'Ardoise', light: '#D7D4E2', dark: '#5E5878' }
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

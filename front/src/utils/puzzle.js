import { currentLocale, intlLocale, t } from '@/i18n'

/**
 * @typedef {object} ThemeGroup
 * @property {string} category
 * @property {string} label
 * @property {import('@/stores/puzzle').Theme[]} themes
 */

/**
 * A theme's text in the current language: the API sends both (`labelFr`, `labelEn`…), French
 * when a language has none (docs/I18N.md, § 5).
 *
 * @param {import('@/stores/puzzle').Theme} theme
 * @param {'label'|'description'|'categoryLabel'} field
 * @returns {string}
 */
export function themeText(theme, field) {
  const en = currentLocale() === 'en' ? theme[`${field}En`] : null
  return en || theme[`${field}Fr`]
}

/**
 * Themes grouped by category, in the API's order (themes page, puzzle settings).
 *
 * @param {import('@/stores/puzzle').Theme[]} themes
 * @returns {ThemeGroup[]}
 */
export function groupThemes(themes) {
  /** @type {Map<string, ThemeGroup>} */
  const byCategory = new Map()
  for (const theme of themes) {
    if (!byCategory.has(theme.category)) {
      byCategory.set(theme.category, {
        category: theme.category,
        label: themeText(theme, 'categoryLabel'),
        themes: []
      })
    }
    byCategory.get(theme.category)?.themes.push(theme)
  }
  return [...byCategory.values()]
}

/**
 * A theme's puzzle count, short: "12 k".
 *
 * @param {number} count
 */
export function formatCount(count) {
  return new Intl.NumberFormat(intlLocale(), { notation: 'compact' }).format(
    count
  )
}

/** The relative difficulty of the next puzzles. */
export const DIFFICULTIES = ['easier', 'normal', 'harder'].map(value => ({
  value,
  get label() {
    return t(`puzzle.difficulties.${value}`)
  }
}))

/** Why the Lichess rating import was refused (read when shown). */
export const LICHESS_IMPORT_ERRORS = {
  get 409() {
    return t('puzzle.lichessImport.409')
  },
  get 422() {
    return t('puzzle.lichessImport.422')
  }
}

/**
 * Filters other than the defaults (a dot on the settings icon).
 *
 * @param {{themes: string[], difficulty: string}} filters
 */
export function filtersActive(filters) {
  return filters.themes.length > 0 || filters.difficulty !== 'normal'
}

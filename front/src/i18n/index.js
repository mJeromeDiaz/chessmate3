import { createI18n } from 'vue-i18n'

/**
 * The SPA's languages (docs/I18N.md): French is the source of every text, English the default
 * for a browser that is not French. Messages live in one JSON file per domain and language
 * (`fr/woodpecker.json` → keys `woodpecker.*`); a key missing in English shows the French text.
 *
 * @typedef {'fr'|'en'} Locale
 */

/** @type {Locale[]} */
export const LOCALES = ['fr', 'en']

/** @type {Locale} */
export const DEFAULT_LOCALE = 'en'

/** The source language, shown when a translation is missing. */
export const SOURCE_LOCALE = 'fr'

/** Names in their own language, for the selectors. */
export const LOCALE_NAMES = { fr: 'Français', en: 'English' }

/** BCP 47 tags for dates and numbers (`Intl`): day before month, 24-hour clock in both. */
const INTL_TAGS = { fr: 'fr-FR', en: 'en-GB' }

/**
 * One language's messages, each file under its name.
 *
 * @param {Record<string, Record<string, unknown>>} files `import.meta.glob` of `./<locale>/*.json`
 */
function bundle(files) {
  return Object.fromEntries(
    Object.entries(files).map(([path, messages]) => [
      path.replace(/^.*\/([^/]+)\.json$/, '$1'),
      messages
    ])
  )
}

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: DEFAULT_LOCALE,
  fallbackLocale: SOURCE_LOCALE,
  // A key missing in English falls back to French on purpose: no console noise for it.
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    fr: bundle(
      import.meta.glob('./fr/*.json', { eager: true, import: 'default' })
    ),
    en: bundle(
      import.meta.glob('./en/*.json', { eager: true, import: 'default' })
    )
  }
})

/**
 * Translates outside components (stores, utils), in the current language.
 *
 * @type {typeof i18n.global.t}
 */
export const t = (...args) => i18n.global.t(...args)

/** @returns {Locale} */
export function currentLocale() {
  return /** @type {Locale} */ (i18n.global.locale.value)
}

/** The `Intl` tag of the current language ("fr-FR", "en-GB"). */
export function intlLocale() {
  return INTL_TAGS[currentLocale()] ?? INTL_TAGS[DEFAULT_LOCALE]
}

/**
 * @param {unknown} value
 * @returns {value is Locale}
 */
export function isLocale(value) {
  return LOCALES.includes(/** @type {Locale} */ (value))
}

/**
 * The language of a first visit: the first of the browser's languages that the SPA speaks,
 * English otherwise.
 *
 * @param {readonly string[]} [languages] the browser's, in order of preference
 * @returns {Locale}
 */
export function browserLocale(
  languages = globalThis.navigator?.languages ?? []
) {
  for (const language of languages) {
    const base = language.toLowerCase().split('-')[0]
    if (isLocale(base)) return base
  }
  return DEFAULT_LOCALE
}

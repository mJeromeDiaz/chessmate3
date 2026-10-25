import { t } from '@/i18n'

/**
 * @typedef {object} NavLink
 * @property {string} id stable name, for the test ids (`nav-free-<id>`)
 * @property {string} to the route
 * @property {Record<string, string>} [query] sent along (a dialog opened on arrival)
 * @property {string} label in the current language (docs/I18N.md)
 * @property {string} icon
 * @property {string[]} [also] other route prefixes that belong to this page
 */

/**
 * @param {string} id
 * @param {string} to
 * @param {string} key message key of the label
 * @param {string} icon
 * @param {Partial<NavLink>} [extra]
 * @returns {NavLink}
 */
function link(id, to, key, icon, extra = {}) {
  return {
    id,
    to,
    icon,
    ...extra,
    get label() {
      return t(key)
    }
  }
}

/** The dashboard, first entry of the menu. */
export const DASHBOARD_LINK = link(
  'dashboard',
  '/',
  'layout.menu.dashboard',
  'space_dashboard',
  { also: ['/stats'] }
)

/** The modules played on their own, in the menu's "Entraînement libre" panel. */
export const FREE_TRAINING_LINKS = [
  link('puzzle', '/puzzle', 'layout.nav.puzzles', 'extension'),
  link('rush', '/puzzle', 'layout.nav.rush', 'bolt', { query: { rush: '1' } }),
  link('streak', '/streak', 'layout.nav.streak', 'local_fire_department'),
  link('woodpecker', '/woodpecker', 'layout.nav.woodpecker', 'repeat'),
  link('repertoire', '/repertoire', 'layout.nav.repertoires', 'menu_book'),
  link('coordinates', '/coordinates', 'layout.nav.coordinates', 'grid_on'),
  link('notation', '/notation', 'layout.nav.notation', 'edit_note'),
  link('blindfold', '/blindfold', 'layout.nav.blindfold', 'visibility_off'),
  link('recall', '/reconstitution', 'layout.nav.recall', 'psychology'),
  link('evaluation', '/evaluation', 'layout.nav.evaluation', 'balance'),
  link('endgames', '/finales', 'layout.nav.endgames', 'flag'),
  link('challenges', '/defis', 'layout.nav.challenges', 'route')
]

/**
 * Whether a path is on a link's page: the page itself or one of its sub-pages ("/puzzle/themes"
 * is Puzzles), never a mere common prefix ("/puzzles" is not "/puzzle"). Home matches only itself.
 * A link opening a dialog (a query) is never the current page.
 *
 * @param {NavLink} link
 * @param {string} path the current route path
 * @returns {boolean}
 */
export function isNavActive(link, path) {
  if (link.query) return false
  return [link.to, ...(link.also ?? [])].some(prefix =>
    prefix === '/'
      ? path === '/'
      : path === prefix || path.startsWith(`${prefix}/`)
  )
}

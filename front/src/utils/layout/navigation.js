import { t } from '@/i18n'

/**
 * @typedef {object} NavLink
 * @property {string} id stable name, for the test ids (`nav-free-<id>`)
 * @property {string} to the route
 * @property {Record<string, string>} [query] sent along (a dialog opened on arrival)
 * @property {string} label in the current language (docs/I18N.md)
 * @property {string} icon
 * @property {string[]} [also] other route prefixes that belong to this page
 * @property {boolean} [exact] the page itself only, not its sub-pages ("/finales", not "/finales/libre")
 */

/**
 * @typedef {object} NavSection
 * @property {string} id stable name, for the test ids (`nav-free-section-<id>`)
 * @property {string} label in the current language
 * @property {NavLink[]} links
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

/**
 * @param {string} id
 * @param {string} key message key of the title
 * @param {NavLink[]} links
 * @returns {NavSection}
 */
function section(id, key, links) {
  return {
    id,
    links,
    get label() {
      return t(key)
    }
  }
}

/**
 * The modules played on their own, in the menu's "Entraînement libre" panel, by phase of the game:
 * the openings, the middlegame, the endgame, then vision and judgement.
 */
export const FREE_TRAINING_SECTIONS = [
  section('openings', 'layout.menu.free.sections.openings', [
    link('repertoire', '/repertoire', 'layout.nav.repertoires', 'menu_book')
  ]),
  section('middlegame', 'layout.menu.free.sections.middlegame', [
    link('woodpecker', '/woodpecker', 'layout.nav.woodpecker', 'repeat'),
    link('puzzle', '/puzzle', 'layout.nav.puzzles', 'extension'),
    link('rush', '/puzzle', 'layout.nav.rush', 'bolt', {
      query: { rush: '1' }
    }),
    link('streak', '/streak', 'layout.nav.streak', 'local_fire_department')
  ]),
  section('endgame', 'layout.menu.free.sections.endgame', [
    link('endgames', '/finales/libre', 'layout.nav.endgames', 'flag')
  ]),
  section('vision', 'layout.menu.free.sections.vision', [
    link('coordinates', '/coordinates', 'layout.nav.coordinates', 'grid_on'),
    link('notation', '/notation', 'layout.nav.notation', 'edit_note'),
    link('blindfold', '/blindfold', 'layout.nav.blindfold', 'visibility_off'),
    link('recall', '/reconstitution', 'layout.nav.recall', 'psychology'),
    link('evaluation', '/evaluation', 'layout.nav.evaluation', 'balance')
  ])
]

/** Every free training module, in the panel's order. */
export const FREE_TRAINING_LINKS = FREE_TRAINING_SECTIONS.flatMap(
  ({ links }) => links
)

/**
 * The mind map's short paths, in the menu's "Les défis" card: the endgames' path (its free mode
 * is a free training module) and Lizy's défis.
 */
export const CHALLENGE_LINKS = [
  link('endgames', '/finales', 'layout.nav.endgames', 'flag', { exact: true }),
  link('defis', '/defis', 'layout.nav.challenges', 'route')
]

/**
 * Whether a path is on a link's page: the page itself or one of its sub-pages ("/puzzle/themes"
 * is Puzzles), never a mere common prefix ("/puzzles" is not "/puzzle"). Home and an `exact` link
 * match only themselves.
 * A link opening a dialog (a query) is never the current page.
 *
 * @param {NavLink} link
 * @param {string} path the current route path
 * @returns {boolean}
 */
export function isNavActive(link, path) {
  if (link.query) return false
  return [link.to, ...(link.also ?? [])].some(prefix =>
    prefix === '/' || link.exact
      ? path === prefix
      : path === prefix || path.startsWith(`${prefix}/`)
  )
}

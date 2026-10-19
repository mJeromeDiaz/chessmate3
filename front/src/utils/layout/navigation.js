import { t } from '@/i18n'

/**
 * @typedef {object} NavLink
 * @property {string} to the route
 * @property {string} label in the current language (docs/I18N.md)
 * @property {string} icon
 * @property {boolean} header shown in the wide header (home is the logo there)
 * @property {string[]} [also] other route prefixes that belong to this page
 */

/**
 * The app's pages, shared by the wide header and the drawer (one list to keep up to date).
 *
 * @type {NavLink[]}
 */
export const NAV_LINKS = [
  {
    to: '/',
    get label() {
      return t('layout.nav.home')
    },
    icon: 'home',
    header: false,
    also: ['/stats']
  },
  {
    to: '/session',
    get label() {
      return t('layout.nav.sessions')
    },
    icon: 'playlist_play',
    header: true
  },
  {
    to: '/cerveau',
    get label() {
      return t('layout.nav.mindMap')
    },
    icon: 'lightbulb',
    header: true
  },
  {
    to: '/parcours',
    get label() {
      return t('layout.nav.parcours')
    },
    icon: 'route',
    header: true
  },
  {
    to: '/puzzle',
    get label() {
      return t('layout.nav.puzzles')
    },
    icon: 'extension',
    header: true
  },
  {
    to: '/woodpecker',
    get label() {
      return t('layout.nav.woodpecker')
    },
    icon: 'repeat',
    header: true
  },
  {
    to: '/streak',
    get label() {
      return t('layout.nav.streak')
    },
    icon: 'bolt',
    header: true
  },
  {
    to: '/repertoire',
    get label() {
      return t('layout.nav.repertoires')
    },
    icon: 'menu_book',
    header: true
  },
  {
    to: '/coordinates',
    get label() {
      return t('layout.nav.coordinates')
    },
    icon: 'grid_on',
    header: true
  },
  {
    to: '/blindfold',
    get label() {
      return t('layout.nav.blindfold')
    },
    icon: 'visibility_off',
    header: true
  },
  {
    to: '/reconstitution',
    get label() {
      return t('layout.nav.recall')
    },
    icon: 'psychology',
    header: true
  },
  {
    to: '/evaluation',
    get label() {
      return t('layout.nav.evaluation')
    },
    icon: 'balance',
    header: true
  }
]

/**
 * Whether a path is on a link's page: the page itself or one of its sub-pages ("/puzzle/themes"
 * is Puzzles), never a mere common prefix ("/puzzles" is not "/puzzle"). Home matches only itself.
 *
 * @param {NavLink} link
 * @param {string} path the current route path
 * @returns {boolean}
 */
export function isNavActive(link, path) {
  return [link.to, ...(link.also ?? [])].some(prefix =>
    prefix === '/'
      ? path === '/'
      : path === prefix || path.startsWith(`${prefix}/`)
  )
}

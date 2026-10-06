/**
 * @typedef {object} NavLink
 * @property {string} to the route
 * @property {string} label
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
    label: 'Accueil',
    icon: 'home',
    header: false,
    also: ['/stats']
  },
  { to: '/session', label: 'Sessions', icon: 'playlist_play', header: true },
  { to: '/puzzle', label: 'Puzzles', icon: 'extension', header: true },
  { to: '/woodpecker', label: 'Woodpecker', icon: 'repeat', header: true },
  { to: '/repertoire', label: 'Répertoires', icon: 'menu_book', header: true }
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

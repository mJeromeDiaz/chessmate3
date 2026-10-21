/**
 * @typedef {object} PageHeaderMeta What a page declares in `definePage({ meta: { header } })`
 * @property {string} [title] message key of the title (none: the brand is shown instead)
 * @property {string} [subtitle] message key of the line above the title
 * @property {string|false} [parent] the back arrow's target, forced; false: no arrow
 */

/**
 * The back arrow's target: the closest existing page above this one ("/repertoire/12/stats" →
 * "/repertoire/12", "/session/plans/3" → "/session" as "/session/plans" is no page). None on the
 * dashboard and on top-level pages, whose parent would be the dashboard, unless the page names
 * its parent itself.
 *
 * @param {string} path the current route path
 * @param {(path: string) => boolean} exists whether a path is a page (not the 404)
 * @param {string|false} [forced] the page's own `header.parent`
 * @returns {string|null}
 */
export function parentPath(path, exists, forced) {
  if (forced === false) return null
  if (typeof forced === 'string') return forced
  const segments = path.split('/').filter(Boolean)
  while (segments.length > 1) {
    segments.pop()
    const candidate = `/${segments.join('/')}`
    if (exists(candidate)) return candidate
  }
  return null
}

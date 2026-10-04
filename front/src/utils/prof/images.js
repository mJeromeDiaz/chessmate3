/**
 * The professors' illustrations (src/assets/profs), by file name without extension. Globbed rather
 * than imported one by one: a missing picture yields '' and the avatar falls back to its glyph.
 * Character sheets (`*-sheet.png`, design references) are left out of the bundle.
 */
const URLS = import.meta.glob(['@/assets/profs/*.png', '!**/*-sheet.png'], {
  eager: true,
  import: 'default',
  query: '?url'
})

/**
 * @param {string} name e.g. "lizy-bust"
 * @returns {string} the bundled URL, or '' when the file is missing
 */
export function profImage(name) {
  const key = Object.keys(URLS).find(path => path.endsWith(`/${name}.png`))
  return key ? /** @type {string} */ (URLS[key]) : ''
}

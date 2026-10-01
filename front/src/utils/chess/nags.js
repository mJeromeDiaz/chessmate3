/**
 * PGN Numeric Annotation Glyphs the editor offers (the API accepts 1 to 255, at most one move
 * assessment from 1 to 6).
 */

/** Move assessments: one per move. */
export const MOVE_NAGS = [
  { nag: 1, symbol: '!', label: 'Bon coup' },
  { nag: 2, symbol: '?', label: 'Erreur' },
  { nag: 3, symbol: '!!', label: 'Excellent coup' },
  { nag: 4, symbol: '??', label: 'Gaffe' },
  { nag: 5, symbol: '!?', label: 'Coup intéressant' },
  { nag: 6, symbol: '?!', label: 'Coup douteux' }
]

/** Position assessments. */
export const POSITION_NAGS = [
  { nag: 10, symbol: '=', label: 'Égalité' },
  { nag: 13, symbol: '∞', label: 'Position incertaine' },
  { nag: 14, symbol: '⩲', label: 'Léger avantage blanc' },
  { nag: 15, symbol: '⩱', label: 'Léger avantage noir' },
  { nag: 16, symbol: '±', label: 'Avantage blanc' },
  { nag: 17, symbol: '∓', label: 'Avantage noir' },
  { nag: 18, symbol: '+−', label: 'Avantage décisif blanc' },
  { nag: 19, symbol: '−+', label: 'Avantage décisif noir' }
]

const SYMBOLS = new Map(
  [...MOVE_NAGS, ...POSITION_NAGS].map(n => [n.nag, n.symbol])
)

/**
 * @param {number} nag
 * @returns {boolean}
 */
export const isMoveNag = nag => nag >= 1 && nag <= 6

/**
 * The symbol of a NAG ("$n" for one the editor does not name).
 *
 * @param {number} nag
 * @returns {string}
 */
export const nagSymbol = nag => SYMBOLS.get(nag) ?? `$${nag}`

/**
 * The move assessment symbol of a move, if any ("!", "?!"...).
 *
 * @param {number[]} nags
 * @returns {string|null}
 */
export function moveGlyph(nags) {
  const nag = nags.find(isMoveNag)
  return nag ? nagSymbol(nag) : null
}

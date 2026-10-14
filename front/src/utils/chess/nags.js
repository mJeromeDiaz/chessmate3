import { t } from '@/i18n'

/**
 * A NAG with its label, read when shown (repertoire.nags.<nag>).
 *
 * @param {number} nag
 * @param {string} symbol
 * @returns {{nag: number, symbol: string, label: string}}
 */
function glyph(nag, symbol) {
  return {
    nag,
    symbol,
    get label() {
      return t(`repertoire.nags.${nag}`)
    }
  }
}

/**
 * PGN Numeric Annotation Glyphs the editor offers (the API accepts 1 to 255, at most one move
 * assessment from 1 to 6).
 */

/** Move assessments: one per move. */
export const MOVE_NAGS = [
  glyph(1, '!'),
  glyph(2, '?'),
  glyph(3, '!!'),
  glyph(4, '??'),
  glyph(5, '!?'),
  glyph(6, '?!')
]

/** Position assessments. */
export const POSITION_NAGS = [
  glyph(10, '='),
  glyph(13, '∞'),
  glyph(14, '⩲'),
  glyph(15, '⩱'),
  glyph(16, '±'),
  glyph(17, '∓'),
  glyph(18, '+−'),
  glyph(19, '−+')
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

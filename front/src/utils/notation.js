import { currentLocale, t } from '@/i18n'

/**
 * The notation module (docs/NOTATION.md), what the player reads and types. The API speaks SAN with
 * English piece letters only; the player reads and types those of the interface's language (in
 * French R D T F C: Roi, Dame, Tour, Fou, Cavalier). Both sets cannot be accepted at once: R is
 * the king in French, the rook in English. Pure, so that it can be tested alone.
 *
 * @typedef {'play'|'sequence'|'write'|'line'} NotationKind
 * @typedef {{uci: string, san: string}} SanMove
 */

/** The exercises, in the page's order. */
export const KINDS = ['play', 'sequence', 'write', 'line']

/** Exercises answered in writing, not on the board. */
export const WRITTEN = ['write', 'line']

/** English piece letters → each language's (king, queen, rook, bishop, knight). */
const LETTERS = {
  fr: { K: 'R', Q: 'D', R: 'T', B: 'F', N: 'C' },
  en: { K: 'K', Q: 'Q', R: 'R', B: 'B', N: 'N' }
}

/**
 * The piece letters of a language, king first.
 *
 * @param {string} [locale]
 * @returns {string[]}
 */
export function pieceLetters(locale = currentLocale()) {
  return Object.values(LETTERS[locale === 'fr' ? 'fr' : 'en'])
}

/**
 * A SAN move (English letters) in the player's language: « Nf3 » → « Cf3 », « e8=Q+ » → « e8=D+ ».
 *
 * @param {string} san
 * @param {string} [locale]
 */
export function localSan(san, locale = currentLocale()) {
  const map = LETTERS[locale === 'fr' ? 'fr' : 'en']
  return san.replace(/[KQRBN]/g, letter => map[letter] ?? letter)
}

/**
 * What the player typed, in English letters for the API: « Cf3 Tf1 » → « Nf3 Rf1 ». Only capital
 * letters are pieces (a lowercase « c » is the c-file); « 0-0 » becomes « O-O ».
 *
 * @param {string} text
 * @param {string} [locale]
 */
export function englishSan(text, locale = currentLocale()) {
  const map = LETTERS[locale === 'fr' ? 'fr' : 'en']
  const reverse = Object.fromEntries(
    Object.entries(map).map(([english, local]) => [local, english])
  )
  return text
    .replace(/0-0-0/g, 'O-O-O')
    .replace(/0-0/g, 'O-O')
    .replace(/[A-Z]/g, letter =>
      letter === 'O' ? letter : (reverse[letter] ?? letter)
    )
}

/**
 * Moves as a numbered line, in the player's language: « 1. e4 e5 2. Cf3 », « 12… Fxe4 13. Dd1 ».
 *
 * @param {string[]} sans SAN, English letters
 * @param {string} fen where the line starts (its side to move and move number)
 * @param {string} [locale]
 */
export function numberedLine(sans, fen, locale = currentLocale()) {
  const [, turn = 'w', , , , fullmove = '1'] = fen.split(' ')
  let number = Number(fullmove) || 1
  let white = turn === 'w'
  const parts = []
  sans.forEach((san, i) => {
    if (white) parts.push(`${number}.`)
    else if (i === 0) parts.push(`${number}…`)
    parts.push(localSan(san, locale))
    if (!white) number++
    white = !white
  })
  return parts.join(' ')
}

/**
 * Why a written line is wrong, for the player.
 *
 * @param {string|null} reason illegal, wrong, short, long
 * @param {number|null} mistakeAt 0-based
 */
export function writtenMistake(reason, mistakeAt) {
  if (!reason) return ''
  return t(`notation.reasons.${reason}`, { n: (mistakeAt ?? 0) + 1 })
}

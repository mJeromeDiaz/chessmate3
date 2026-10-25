import { Chess } from 'chess.js'
import { currentLocale, t } from '@/i18n'

/**
 * The notation module (docs/NOTATION.md), what the player reads and types. The API speaks SAN with
 * English piece letters only; the player reads and types those of the interface's language (in
 * French R D T F C: Roi, Dame, Tour, Fou, Cavalier). Both sets cannot be accepted at once: R is
 * the king in French, the rook in English. Pure, so that it can be tested alone.
 *
 * @typedef {'play'|'sequence'|'write'|'line'|'game_play'|'game_write'} NotationKind
 * @typedef {{uci: string, san: string}} SanMove
 */

/** The exercises, in the page's order. */
export const KINDS = [
  'play',
  'sequence',
  'write',
  'line',
  'game_play',
  'game_write'
]

/** Exercises answered in writing, not on the board. */
export const WRITTEN = ['write', 'line', 'game_write']

/** Exercises played as a sequence of moves read, for both sides. */
export const SEQUENCES = ['sequence', 'game_play']

/** Exercises drawn from the legendary games. */
export const GAMES = ['game_play', 'game_write']

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

/**
 * The half-moves of a written line, as the server reads them (`WrittenLine::tokens`): move numbers
 * (« 1. », « 2... ») and empty pieces left out.
 *
 * @param {string[]} written
 * @returns {string[]}
 */
export function writtenTokens(written) {
  return written.flatMap(part =>
    part
      .trim()
      .split(/\s+/)
      .map(token => token.replace(/^\d+\.+/, ''))
      .filter(Boolean)
  )
}

/**
 * @typedef {'ok'|'wrong'|'missing'|'extra'|'after'} CorrectionState
 * @typedef {{number: string, yours: string|null, right: string|null, state: CorrectionState}} CorrectionRow
 * @typedef {object} Correction
 * @property {CorrectionRow[]} rows a half-move each: what was written beside the solution
 * @property {string[]} fens the solution's positions, from its start (index i: after i half-moves)
 * @property {string|null} wrongUci the player's wrong move (UCI) when it was legal there
 * @property {number|null} mistakeAt the first half-move wrong, 0-based
 */

/**
 * A wrong written line beside the right one (docs/NOTATION.md, § 3 bis): each half-move written,
 * the solution's, and how it reads (right, the first wrong one, missing, one too many, written
 * after the mistake); the solution's positions to step through, and the wrong move on the board
 * when it was a legal one.
 *
 * @param {string} fen where the line starts
 * @param {SanMove[]} expected the solution
 * @param {string[]} answer what was written (English letters)
 * @param {number|null} mistakeAt
 * @param {string|null} reason illegal, wrong, short, long
 * @param {string} [locale]
 * @returns {Correction}
 */
export function correctionRows(
  fen,
  expected,
  answer,
  mistakeAt,
  reason,
  locale = currentLocale()
) {
  const tokens = writtenTokens(answer)
  const [, turn = 'w', , , , fullmove = '1'] = fen.split(' ')
  let number = Number(fullmove) || 1
  let white = turn === 'w'
  const rows = []
  for (let i = 0; i < Math.max(expected.length, tokens.length); i++) {
    const label = white ? `${number}.` : `${number}…`
    if (!white) number++
    white = !white
    /** @type {CorrectionState} */
    let state = 'after'
    if (i >= expected.length) state = 'extra'
    else if (mistakeAt === null || i < mistakeAt) state = 'ok'
    else if (i === mistakeAt) state = i >= tokens.length ? 'missing' : 'wrong'
    rows.push({
      number: label,
      yours: i < tokens.length ? localSan(tokens[i], locale) : null,
      right: i < expected.length ? localSan(expected[i].san, locale) : null,
      state
    })
  }

  const chess = new Chess(fen)
  const fens = [chess.fen()]
  let wrongUci = null
  expected.forEach((move, i) => {
    if (i === mistakeAt && reason === 'wrong' && tokens[i]) {
      try {
        const wrong = new Chess(chess.fen()).move(tokens[i])
        wrongUci = wrong.from + wrong.to + (wrong.promotion ?? '')
      } catch {
        wrongUci = null
      }
    }
    chess.move({
      from: move.uci.slice(0, 2),
      to: move.uci.slice(2, 4),
      promotion: move.uci[4]
    })
    fens.push(chess.fen())
  })

  return { rows, fens, wrongUci, mistakeAt }
}

/**
 * The board at a step of a correction: the solution's position after `frame` half-moves, its last
 * move marked; at the mistake, the player's move in red (when legal) and the right one as an arrow.
 *
 * @param {Correction} correction
 * @param {SanMove[]} expected
 * @param {number} frame 0 to the solution's length
 * @returns {{fen: string, highlights: {square: string, type: 'lastMove'|'error'}[], arrows: {from: string, to: string, type: 'solution'}[]}}
 */
export function correctionBoard(correction, expected, frame) {
  const at = Math.max(0, Math.min(frame, correction.fens.length - 1))
  /** @type {{square: string, type: 'lastMove'|'error'}[]} */
  const highlights = []
  /** @type {{from: string, to: string, type: 'solution'}[]} */
  const arrows = []
  const last = at > 0 ? expected[at - 1]?.uci : null
  if (last)
    highlights.push(
      { square: last.slice(0, 2), type: 'lastMove' },
      { square: last.slice(2, 4), type: 'lastMove' }
    )
  const right = expected[at]?.uci
  if (at === correction.mistakeAt && right) {
    if (correction.wrongUci)
      highlights.push(
        { square: correction.wrongUci.slice(0, 2), type: 'error' },
        { square: correction.wrongUci.slice(2, 4), type: 'error' }
      )
    arrows.push({
      from: right.slice(0, 2),
      to: right.slice(2, 4),
      type: 'solution'
    })
  }
  return { fen: correction.fens[at], highlights, arrows }
}

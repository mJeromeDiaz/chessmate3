/**
 * The vision module (docs/VISION.md): what its questions say, what their verdict says, how the
 * correction colours the position reached.
 */
import { localSan } from '@/utils/notation'
import { t } from '@/i18n'

/** The questions answered by one click on the empty board. */
export const ONE_SQUARE = ['where', 'en_prise', 'arrival']
/** The questions answered by clicking several squares, then « Valider ». */
export const MANY_SQUARES = ['attackers', 'captures', 'stops']
/** The questions answered with a piece of the palette (« Case vide » for « what »). */
export const PIECE_ANSWER = ['what', 'captured', 'missing']
/** The questions whose square is lit while answering (it is no answer). */
export const LIT_TARGET = ['what', 'attackers', 'captured', 'reach']

/**
 * The moves announced, as the player reads them: a game's numbered line, or a piece's path
 * (« Td1, Td5, Txd7 »), in the UI's piece letters.
 *
 * @param {{announced?: {san: string}[], announcedStyle?: string}} data the item's
 * @param {(sans: string[]) => string} numbered a game's line, numbered from its position
 * @param {number} [upTo] the moves announced so far
 * @returns {string}
 */
export function movesText(data, numbered, upTo) {
  const sans = (data.announced ?? []).map(m => m.san).slice(0, upTo)
  if (!sans.length) return ''
  return data.announcedStyle === 'path'
    ? sans.map(san => localSan(san)).join(', ')
    : numbered(sans)
}

/**
 * The label of a choice: a number of jumps, « Oui », « Non ».
 *
 * @param {string} choice
 * @returns {string}
 */
export function choiceText(choice) {
  return ['yes', 'no'].includes(choice) ? t(`vision.choices.${choice}`) : choice
}

/**
 * The question, as the player reads it: « Où est le roi noir ? », « Qu'y a-t-il en e5 ? »…
 *
 * @param {{ask: string, target: string|null, side: string|null, piece?: string|null, to?: string|null}} data the item's
 * @returns {string}
 */
export function questionText(data) {
  switch (data.ask) {
    case 'where':
      return t('vision.ask.where', { piece: t(`vision.pieces.${data.target}`) })
    case 'what':
      return t('vision.ask.what', { square: data.target ?? '' })
    case 'attackers':
      return t('vision.ask.attackers', {
        side: t(`vision.sides.${data.side === 'black' ? 'black' : 'white'}`),
        square: data.target ?? ''
      })
    case 'captures':
      // A position updated by moves: no tracked piece.
      if (!data.piece) return t('vision.ask.capturesAll')
      return t('vision.ask.captures', { piece: tracked(data.piece) })
    case 'missing':
      return t('vision.ask.missing')
    case 'reach':
      return t(`vision.ask.reach.${data.piece}`, { square: data.target ?? '' })
    case 'arrival':
    case 'captured':
      return t(`vision.ask.${data.ask}`, {
        piece: tracked(data.piece),
        square: data.target ?? ''
      })
    case 'passed':
    case 'stops':
      return t(`vision.ask.${data.ask}.${trackedKey(data.piece)}`, {
        square: data.target ?? ''
      })
    case 'knight_jumps':
      return t('vision.ask.knight_jumps', {
        from: data.target ?? '',
        to: data.to ?? ''
      })
    default:
      return t('vision.ask.en_prise')
  }
}

/** @param {string|null|undefined} piece a white piece's letter */
function trackedKey(piece) {
  return ['R', 'B', 'Q', 'N'].includes(piece ?? '')
    ? /** @type {string} */ (piece)
    : 'R'
}

/** « la tour », « le fou »… @param {string|null|undefined} piece */
function tracked(piece) {
  return t(`vision.tracked.${trackedKey(piece)}`)
}

/**
 * What a wrong answer's verdict says: where the right answer was.
 *
 * @param {{ask: string, target: string|null, piece?: string|null}} data the item's
 * @param {{expected: string[]}} result
 * @returns {string}
 */
export function verdictText(data, result) {
  const squares = result.expected.length
    ? result.expected.join(', ')
    : t('vision.player.verdicts.none')
  switch (data.ask) {
    case 'where':
      return t('vision.player.verdicts.where', {
        piece: capitalize(t(`vision.pieces.${data.target}`)),
        squares
      })
    case 'what':
      return result.expected[0]
        ? t('vision.player.verdicts.what', {
            square: data.target ?? '',
            piece: t(`vision.pieces.${result.expected[0]}`)
          })
        : t('vision.player.verdicts.whatEmpty', { square: data.target ?? '' })
    case 'arrival':
      return t('vision.player.verdicts.arrival', {
        piece: capitalize(tracked(data.piece)),
        squares
      })
    case 'captured':
      return t('vision.player.verdicts.captured', {
        square: data.target ?? '',
        piece: tracked(data.piece),
        taken: t(`vision.pieces.${result.expected[0]}`)
      })
    case 'knight_jumps':
      return t(
        'vision.player.verdicts.knight_jumps',
        { n: result.expected[0] ?? '' },
        Number(result.expected[0] ?? 0)
      )
    case 'missing':
      return t('vision.player.verdicts.missing', {
        piece: t(`vision.pieces.${result.expected[0]}`)
      })
    case 'passed':
    case 'reach':
      return t('vision.player.verdicts.passed', {
        answer: choiceText(result.expected[0] ?? '')
      })
    default:
      return t(`vision.player.verdicts.${data.ask}`, { squares })
  }
}

/**
 * The correction on the position reached: the right squares in green, the wrong clicks in red; for
 * « what », the square asked, green or red.
 *
 * @param {{ask: string, target: string|null}} data the item's
 * @param {{status: string, expected: string[], answer: string[]}} result
 * @returns {{square: string, type: string}[]}
 */
export function correctionHighlights(data, result) {
  if (['knight_jumps', 'passed', 'reach', 'missing'].includes(data.ask))
    return []
  if (PIECE_ANSWER.includes(data.ask))
    return data.target
      ? [
          {
            square: data.target,
            type: result.status === 'right' ? 'success' : 'error'
          }
        ]
      : []
  const right = new Set(result.expected)
  return [
    ...result.expected.map(square => ({ square, type: 'success' })),
    ...result.answer
      .filter(square => !right.has(square))
      .map(square => ({ square, type: 'error' }))
  ]
}

/**
 * A square clicked among the attackers: chosen, or chosen no more.
 *
 * @param {string[]} squares
 * @param {string} square
 * @returns {string[]}
 */
export function toggleSquare(squares, square) {
  return squares.includes(square)
    ? squares.filter(s => s !== square)
    : [...squares, square]
}

/** @param {string} text */
function capitalize(text) {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

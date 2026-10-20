/**
 * The vision module (docs/VISION.md): what its questions say, what their verdict says, how the
 * correction colours the position reached.
 */
import { t } from '@/i18n'

/** The questions answered by one click on the empty board. */
export const ONE_SQUARE = ['where', 'en_prise']

/**
 * The question, as the player reads it: « Où est le roi noir ? », « Qu'y a-t-il en e5 ? »…
 *
 * @param {{ask: string, target: string|null, side: string|null}} data the item's
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
    default:
      return t('vision.ask.en_prise')
  }
}

/**
 * What a wrong answer's verdict says: where the right answer was.
 *
 * @param {{ask: string, target: string|null}} data the item's
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
  if (data.ask === 'what')
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

/**
 * « Brillant ou Blunder » (docs/BRILLIANCY.md): a move that gives material, brilliant (the
 * puzzle's winning sacrifice) or a blunder (the opponent's move the puzzle punishes). The answers,
 * the badges, the line replayed in the correction and what the sheet and Albert say. Pure, so that
 * it can be tested alone.
 *
 * @typedef {'brilliant'|'blunder'} BrilliancyKind
 *
 * @typedef {object} BrilliancyPosition an item's `position`
 * @property {string} fen before the move shown
 * @property {'white'|'black'} color the side that plays it
 * @property {{uci: string, san: string}} move
 *
 * @typedef {object} BrilliancyResult the submission's `result.data`
 * @property {'right'|'wrong'} status
 * @property {BrilliancyKind} kind what the move is
 * @property {BrilliancyKind|null} answer what the player said
 * @property {{uci: string, san: string}[]} line the puzzle's line from the move shown
 * @property {{id: string, rating: number, gameUrl: string|null}|null} puzzle
 * @property {number|null} durationMs
 *
 * @typedef {object} LineStep a position of the line replayed
 * @property {string} fen after the move
 * @property {string} san
 * @property {string} label the move with its number: "12.Bxh7+", "12…Kxh7"
 * @property {string} from
 * @property {string} to
 */
import { Chess } from 'chess.js'
import { formatRate } from '@/utils/format'
import { t } from '@/i18n'

/** The two answers, in the buttons' order, and their badge on the board. */
export const BRILLIANCY_CHOICES = /** @type {const} */ ([
  { value: 'brilliant', glyph: '!!', key: 'b' },
  { value: 'blunder', glyph: '??', key: 'n' }
])

/** Keys that answer at once, besides each choice's letter: 1 brilliant, 2 blunder. */
const NUMBER_KEYS = { 1: 'brilliant', 2: 'blunder' }

/**
 * The answer a key gives, null for any other key.
 *
 * @param {string} key a KeyboardEvent's `key`
 * @returns {BrilliancyKind|null}
 */
export function choiceForKey(key) {
  const lower = key.toLowerCase()
  const choice = BRILLIANCY_CHOICES.find(c => c.key === lower)
  if (choice) return choice.value
  return (
    /** @type {Record<string, BrilliancyKind>} */ (NUMBER_KEYS)[key] ?? null
  )
}

/**
 * The badge of a kind on the move's arrival square.
 *
 * @param {BrilliancyKind} kind
 * @param {string} square
 * @returns {import('@/components/chess/ChessBoard.vue').Glyph}
 */
export function verdictGlyph(kind, square) {
  return {
    square,
    text: kind === 'brilliant' ? '!!' : '??',
    tone: kind
  }
}

/**
 * The squares of a UCI move: "e2e4" → from e2 to e4.
 *
 * @param {string} uci
 */
export function uciSquares(uci) {
  return { from: uci.slice(0, 2), to: uci.slice(2, 4) }
}

/**
 * The positions of a line played from `fen`, each with its move and number; stops at the first
 * move that does not play.
 *
 * @param {string} fen
 * @param {{uci: string, san: string}[]} line
 * @returns {LineStep[]}
 */
export function lineSteps(fen, line) {
  /** @type {Chess} */
  let chess
  try {
    chess = new Chess(fen)
  } catch {
    return []
  }
  /** @type {LineStep[]} */
  const steps = []
  for (const [i, { uci }] of line.entries()) {
    const white = chess.turn() === 'w'
    const number = chess.moveNumber()
    let move
    try {
      move = chess.move({
        from: uci.slice(0, 2),
        to: uci.slice(2, 4),
        promotion: uci.length > 4 ? uci[4] : undefined
      })
    } catch {
      break
    }
    steps.push({
      fen: chess.fen(),
      san: move.san,
      label: white
        ? `${number}.${move.san}`
        : i === 0
          ? `${number}…${move.san}`
          : move.san,
      from: move.from,
      to: move.to
    })
  }
  return steps
}

/** Titles of a right answer, in turn (brilliancy.feedback.winTitles). */
const WIN_TITLES = 3

/**
 * The sheet's look and words, and Albert's, for a verdict.
 *
 * @param {BrilliancyResult} result
 * @param {number} index the position's number in the run (1 first)
 */
export function brilliancyFeedback(result, index) {
  const f = 'brilliancy.feedback.'
  const right = result.status === 'right'
  return {
    kind: /** @type {'win'|'miss'} */ (right ? 'win' : 'miss'),
    badge: result.kind === 'brilliant' ? '!!' : '??',
    title: right
      ? t(`${f}winTitles.${(index - 1) % WIN_TITLES}`)
      : t(`${f}missTitle`),
    sub: t(`${f}${result.kind}`),
    kicker: t(`${f}kicker`),
    bubble: right ? t(`${f}${result.kind}Right`) : t(`${f}${result.kind}Wrong`)
  }
}

/**
 * "42 positions · 33 justes (79 %) · brillants 80 % · blunders 75 %", what GET /brilliancy's
 * `results` sum up.
 *
 * @param {{played: number, right: number, brilliant: {played: number, right: number}, blunder: {played: number, right: number}}} results
 */
export function resultsText(results) {
  const r = 'brilliancy.results.'
  if (!results.played) return t(`${r}none`)
  const parts = [
    t(`${r}played`, { n: results.played }, results.played),
    t(
      `${r}right`,
      { n: results.right, rate: formatRate(results.right / results.played) },
      results.right
    )
  ]
  for (const kind of /** @type {const} */ (['brilliant', 'blunder'])) {
    const k = results[kind]
    if (k.played)
      parts.push(t(`${r}${kind}`, { rate: formatRate(k.right / k.played) }))
  }
  return parts.join(' · ')
}

/**
 * The recap's lines of a run: right and wrong, then each kind's right answers out of those met.
 *
 * @param {Record<string, any>} metrics the run's summary metrics
 * @returns {string[]}
 */
export function recapLines(metrics) {
  /** @param {any} k @returns {{played: number, right: number}} */
  const kind = k => ({ played: k?.played ?? 0, right: k?.right ?? 0 })
  const brilliant = kind(metrics.brilliant)
  const blunder = kind(metrics.blunder)
  return [
    t('brilliancy.recap.results', {
      right: metrics.right ?? 0,
      wrong: metrics.wrong ?? 0
    }),
    t('brilliancy.recap.kinds', {
      brilliantRight: brilliant.right,
      brilliantPlayed: brilliant.played,
      blunderRight: blunder.right,
      blunderPlayed: blunder.played
    })
  ]
}

/**
 * A reviewed position's title: the move and what it was, "Fxh7+ · Brillant".
 *
 * @param {{position?: BrilliancyPosition, kind?: BrilliancyKind}} data a review item's data
 */
export function reviewTitle(data) {
  return t('brilliancy.review.title', {
    move: data.position?.move?.san ?? '',
    kind: data.kind ? t(`brilliancy.kinds.${data.kind}`) : ''
  })
}

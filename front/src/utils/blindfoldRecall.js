import { numberedLine } from '@/utils/chess/lichess'
import { t } from '@/i18n'

/**
 * Position recall (docs/BLINDFOLD_RECALL.md): a position shown, hidden, then rebuilt piece by
 * piece and corrected square by square by the server. Pure helpers, testable alone.
 *
 * @typedef {Record<string, string>} Squares square ("e4") => FEN piece letter ("P", "k"...)
 *
 * @typedef {object} RecallLevelRules a level, as GET /blindfold/recall gives it
 * @property {number} level 1 to 20
 * @property {number} stage 1 to 5
 * @property {number} visibleSeconds
 * @property {string[]} pieces a generated level's pieces besides the kings ("?P": either side)
 * @property {[number, number]|null} puzzlePieces a puzzle level's number of pieces
 * @property {number} announced moves announced once the position is hidden
 *
 * @typedef {object} RecallRules
 * @property {RecallLevelRules[]} levels
 * @property {number} hiddenSeconds
 * @property {number} unlockExact exact rebuilds at a level that open the next one
 * @property {number} closeAccuracy
 * @property {number} freeBudgetSeconds
 * @property {number} maxLevel
 *
 * @typedef {object} LevelProgress
 * @property {number} level
 * @property {number} stage
 * @property {number} played
 * @property {number} exact
 * @property {number} close
 * @property {number} missed
 * @property {boolean} unlocked
 *
 * @typedef {object} RecallResult a submission's `data`
 * @property {'exact'|'close'|'missed'} status
 * @property {number} expected
 * @property {number} correct
 * @property {number} wrong
 * @property {number} extra
 * @property {number} missing
 * @property {number} accuracy 0 to 1
 * @property {string} expectedPlacement
 * @property {number|null} unlockedLevel the level this rebuild opened
 * @property {number|null} durationMs
 *
 * @typedef {{square: string, type: 'lastMove'|'hint'|'error'|'success'}} Highlight
 */

/** The palette: White's pieces, then Black's, as FEN letters. */
export const PALETTE = [
  'K',
  'Q',
  'R',
  'B',
  'N',
  'P',
  'k',
  'q',
  'r',
  'b',
  'n',
  'p'
]

/** What a placement needs to be a full FEN the board accepts (nobody to move matters). */
const FEN_TAIL = ' w - - 0 1'

/** The five stages, by number. */
export const STAGES = [1, 2, 3, 4, 5]

/**
 * The squares of a FEN (or of its placement alone).
 *
 * @param {string} fen
 * @returns {Squares}
 */
export function squaresOf(fen) {
  /** @type {Squares} */
  const squares = {}
  const ranks = fen.trim().split(' ')[0].split('/')
  ranks.forEach((rank, index) => {
    let file = 0
    for (const char of rank) {
      if (/\d/.test(char)) {
        file += Number(char)
        continue
      }
      if (file < 8) squares[String.fromCharCode(97 + file) + (8 - index)] = char
      file++
    }
  })
  return squares
}

/**
 * The placement of squares, as in a FEN.
 *
 * @param {Squares} squares
 * @returns {string}
 */
export function placementOf(squares) {
  const ranks = []
  for (let rank = 8; rank >= 1; rank--) {
    let row = ''
    let empty = 0
    for (let file = 0; file < 8; file++) {
      const piece = squares[String.fromCharCode(97 + file) + rank]
      if (!piece) {
        empty++
        continue
      }
      row += (empty ? String(empty) : '') + piece
      empty = 0
    }
    ranks.push(row + (empty ? String(empty) : ''))
  }
  return ranks.join('/')
}

/**
 * A full FEN of squares, for the board.
 *
 * @param {Squares} squares
 */
export function boardFen(squares) {
  return placementOf(squares) + FEN_TAIL
}

/**
 * A click on a square while rebuilding: the piece chosen in the palette goes there (a click on the
 * same piece takes it back); without a piece chosen (the eraser), the square is emptied.
 *
 * @param {Squares} squares
 * @param {string} square
 * @param {string|null} piece the palette's choice, null for the eraser
 * @returns {Squares} a new map
 */
export function placeOn(squares, square, piece) {
  const next = { ...squares }
  if (!piece || next[square] === piece) delete next[square]
  else next[square] = piece
  return next
}

/**
 * The submission of a rebuild: the pieces placed.
 *
 * @param {Squares} squares
 * @returns {{square: string, piece: string}[]}
 */
export function toPlacement(squares) {
  return Object.entries(squares).map(([square, piece]) => ({ square, piece }))
}

/**
 * The correction drawn on the board: on the player's rebuild, the pieces on their square (green)
 * and the others (red); on the position, the pieces found (green) and those missed (circled).
 *
 * @param {Squares} placed
 * @param {Squares} expected
 * @returns {{mine: Highlight[], answer: Highlight[]}}
 */
export function correctionMarks(placed, expected) {
  return {
    mine: Object.entries(placed).map(([square, piece]) => ({
      square,
      type: expected[square] === piece ? 'success' : 'error'
    })),
    answer: Object.entries(expected).map(([square, piece]) => ({
      square,
      type: placed[square] === piece ? 'success' : 'hint'
    }))
  }
}

/**
 * The feedback of a verdict: exact wins, close is "with help", missed is a miss.
 *
 * @param {RecallResult['status']} status
 * @returns {'win'|'help'|'miss'}
 */
export function recallKind(status) {
  if (status === 'exact') return 'win'
  return status === 'close' ? 'help' : 'miss'
}

/**
 * The moves announced, numbered from the position shown: "24.Nd5 Ne2+ 25.Kh1".
 *
 * @param {string} fen the position shown
 * @param {{uci: string, san: string}[]} announced
 */
export function announcedLine(fen, announced) {
  if (!announced?.length) return ''
  const [, turn, , , , number] = fen.split(' ')
  const depth = (Number(number || 1) - 1) * 2 + (turn === 'b' ? 1 : 0)
  return numberedLine(
    announced.map(m => m.san),
    { turn: turn === 'b' ? 'b' : 'w', depth }
  )
}

/**
 * A share as a whole percent: "88 %".
 *
 * @param {number|null|undefined} accuracy 0 to 1
 */
export function percentText(accuracy) {
  if (accuracy === null || accuracy === undefined) return '—'
  return t('blindfoldRecall.percent', { n: Math.round(accuracy * 100) })
}

/** @param {number} level */
export function levelTitle(level) {
  return t(`blindfoldRecall.levels.${level}.title`)
}

/** @param {number} level */
export function levelPieces(level) {
  return t(`blindfoldRecall.levels.${level}.pieces`)
}

/** @param {number} stage */
export function stageTitle(stage) {
  return t(`blindfoldRecall.stages.${stage}.title`)
}

/** @param {number} stage */
export function stageGoal(stage) {
  return t(`blindfoldRecall.stages.${stage}.goal`)
}

/**
 * Where a level stands: locked, "2 / 3" exact rebuilds towards the next one, or passed.
 *
 * @param {LevelProgress} progress
 * @param {number} unlockExact
 * @param {number} maxLevel
 * @returns {'locked'|'open'|'passed'}
 */
export function levelState(progress, unlockExact, maxLevel) {
  if (!progress.unlocked) return 'locked'
  return progress.exact >= unlockExact && progress.level < maxLevel
    ? 'passed'
    : 'open'
}

/**
 * The run's recap lines (docs/TRAINING.md): the level, then the verdicts and the accuracy.
 *
 * @param {Record<string, any>} metrics the summary's
 * @returns {string[]}
 */
export function recallLines(metrics) {
  const lines = []
  if (metrics.level)
    lines.push(
      t('blindfoldRecall.recap.level', {
        level: metrics.level,
        title: levelTitle(metrics.level)
      })
    )
  lines.push(
    t('blindfoldRecall.recap.results', {
      exact: metrics.exact ?? 0,
      close: metrics.close ?? 0,
      missed: metrics.missed ?? 0,
      accuracy: percentText(metrics.accuracy)
    })
  )
  if (metrics.unlockedLevel)
    lines.push(
      t('blindfoldRecall.recap.unlocked', { level: metrics.unlockedLevel })
    )
  return lines
}

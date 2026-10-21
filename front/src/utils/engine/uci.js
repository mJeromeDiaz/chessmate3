/**
 * The UCI protocol spoken by a chess engine in a Web Worker (docs/ENGINE.md): the commands sent,
 * the `info` and `bestmove` lines read back, and the scores they carry. Pure, no worker here.
 *
 * A score is always seen from the side to move in the position analysed, as UCI gives it.
 *
 * @typedef {{cp: number}|{mate: number}} Score `mate: 3` mates in 3 moves, `mate: -2` is mated in 2
 * @typedef {{multipv: number, depth: number, score: Score, pv: string[]}} InfoLine
 * @typedef {{move: string, score: Score, pv: string[], depth: number}} EngineLine
 * @typedef {{depth?: number, movetime?: number, nodes?: number, searchMoves?: string[]}} GoOptions
 */

/** What a mate is worth in centipawns, before the moves it takes are taken off. */
export const MATE_CP = 100_000

/**
 * The `info` line of a search, null for any other line, for a bound (`lowerbound`, `upperbound`:
 * a score not settled yet) and for an info without a score or a line of moves.
 *
 * @param {string} line
 * @returns {InfoLine|null}
 */
export function parseInfo(line) {
  if (!line.startsWith('info ')) return null
  const words = line.trim().split(/\s+/)
  if (words.includes('lowerbound') || words.includes('upperbound')) return null
  let depth = null
  let multipv = 1
  /** @type {Score|null} */
  let score = null
  /** @type {string[]} */
  let pv = []
  for (let i = 1; i < words.length; i++) {
    const word = words[i]
    if (word === 'depth') depth = Number(words[++i])
    else if (word === 'multipv') multipv = Number(words[++i])
    else if (word === 'score') {
      const kind = words[++i]
      const value = Number(words[++i])
      if (kind === 'cp') score = { cp: value }
      else if (kind === 'mate') score = { mate: value }
    } else if (word === 'pv') {
      pv = words.slice(i + 1)
      break
    }
  }
  if (depth === null || score === null || pv.length === 0) return null
  return { multipv, depth, score, pv }
}

/**
 * The move a search ends on: `bestmove e2e4 ponder e7e5`; `move` is null when the side to move has
 * none (`bestmove (none)`: mate or stalemate). Null for any other line.
 *
 * @param {string} line
 * @returns {{move: string|null}|null}
 */
export function parseBestMove(line) {
  const match = /^bestmove\s+(\S+)/.exec(line.trim())
  if (!match) return null
  return { move: match[1] === '(none)' ? null : match[1] }
}

/**
 * `position fen … [moves …]`.
 *
 * @param {string} fen
 * @param {string[]} [moves] UCI moves played from it
 */
export function positionCommand(fen, moves = []) {
  return moves.length > 0
    ? `position fen ${fen} moves ${moves.join(' ')}`
    : `position fen ${fen}`
}

/**
 * `go …`: by depth, by time or by nodes (several end the search at the first reached), on some
 * moves only when `searchMoves` names them.
 *
 * @param {GoOptions} options
 */
export function goCommand({ depth, movetime, nodes, searchMoves } = {}) {
  const parts = ['go']
  if (depth) parts.push(`depth ${depth}`)
  if (movetime) parts.push(`movetime ${movetime}`)
  if (nodes) parts.push(`nodes ${nodes}`)
  if (!depth && !movetime && !nodes) parts.push('depth 12')
  if (searchMoves?.length) parts.push(`searchmoves ${searchMoves.join(' ')}`)
  return parts.join(' ')
}

/**
 * `setoption name … value …`.
 *
 * @param {string} name
 * @param {string|number|boolean} value
 */
export function optionCommand(name, value) {
  return `setoption name ${name} value ${value}`
}

/**
 * A score as one number to compare, from the same side: a mate comes before any material, a
 * shorter mate before a longer one, being mated sooner after later.
 *
 * @param {Score} score
 * @returns {number}
 */
export function scoreValue(score) {
  if ('mate' in score) {
    if (score.mate > 0) return MATE_CP - score.mate
    // `mate 0`: the side to move is mated already.
    return -MATE_CP - score.mate
  }
  return score.cp
}

/**
 * The score from the other side: what a move is worth for the side that played it is the opposite
 * of the score of the position it reaches (the opponent to move).
 *
 * @param {Score} score
 * @returns {Score}
 */
export function flipScore(score) {
  return 'mate' in score ? { mate: -score.mate } : { cp: -score.cp }
}

/**
 * The lines of a search, the last one seen for each `multipv` at its deepest, best first.
 *
 * @param {Map<number, InfoLine>} infos
 * @returns {EngineLine[]}
 */
export function linesOf(infos) {
  return [...infos.values()]
    .sort((a, b) => a.multipv - b.multipv)
    .map(info => ({
      move: info.pv[0],
      score: info.score,
      pv: info.pv,
      depth: info.depth
    }))
}

/**
 * Whether `move` is among the best moves of an analysis: its line scores no more than
 * `toleranceCp` below the first, or mates as fast. A move the analysis did not look at (MultiPV
 * too small) is not.
 *
 * @param {EngineLine[]} lines an analysis, best first
 * @param {string} move UCI
 * @param {number} toleranceCp
 */
export function isNearBest(lines, move, toleranceCp) {
  const best = lines[0]
  const line = lines.find(l => l.move === move)
  if (!best || !line) return false
  if ('mate' in best.score && best.score.mate > 0) {
    return 'mate' in line.score && line.score.mate === best.score.mate
  }
  return scoreValue(best.score) - scoreValue(line.score) <= toleranceCp
}

import { children } from '@/components/chess/moveTree'

/**
 * The line being drafted in the repertoire editor (docs/REPERTOIRE.md, "Brouillon"): moves played
 * from a position of the repertoire, not saved until the user saves the line.
 *
 * @typedef {{uci: string, san: string, fen: string}} DraftMove fen: the full FEN after the move
 * @typedef {object} Draft
 * @property {string|null} from the repertoire position the line starts from
 * @property {DraftMove[]} moves
 * @property {number} cursor how many of the moves the board shows (0: the start position)
 *
 * @typedef {{prepared: import('@/stores/repertoire').Move, uci: string, san: string, positionId: string}} DraftConflict
 */

const STORAGE_PREFIX = 'dontstayrooky.repertoire.draft.'

/** @returns {Draft} */
export function emptyDraft() {
  return { from: null, moves: [], cursor: 0 }
}

/**
 * The draft kept in this browser for a repertoire, if any.
 *
 * @param {string} repertoireId
 * @returns {Draft|null}
 */
export function loadDraft(repertoireId) {
  try {
    const raw = localStorage.getItem(STORAGE_PREFIX + repertoireId)
    if (!raw) return null
    const draft = JSON.parse(raw)
    const valid =
      typeof draft?.from === 'string' &&
      Array.isArray(draft.moves) &&
      draft.moves.length > 0 &&
      draft.moves.every(
        m =>
          typeof m?.uci === 'string' &&
          typeof m.san === 'string' &&
          typeof m.fen === 'string'
      )
    if (!valid) return null
    const cursor = Number.isInteger(draft.cursor) ? draft.cursor : 0
    return {
      from: draft.from,
      moves: draft.moves.map(({ uci, san, fen }) => ({ uci, san, fen })),
      cursor: Math.min(Math.max(cursor, 0), draft.moves.length)
    }
  } catch {
    return null
  }
}

/**
 * Keeps the draft in this browser (an empty one is forgotten).
 *
 * @param {string} repertoireId
 * @param {Draft} draft
 */
export function storeDraft(repertoireId, draft) {
  try {
    if (draft.moves.length && draft.from) {
      localStorage.setItem(STORAGE_PREFIX + repertoireId, JSON.stringify(draft))
    } else {
      localStorage.removeItem(STORAGE_PREFIX + repertoireId)
    }
  } catch {
    // Storage unavailable (private window, blocked): the draft lives in this page only.
  }
}

/**
 * The draft's moves, numbered from the ply of its start: "5.Nf3 Nc6", "5…Nc6 6.d4".
 *
 * @param {number} startDepth ply of the start position (0: the initial position)
 * @param {{san: string}[]} moves
 * @returns {string[]} one label per move
 */
export function draftLabels(startDepth, moves) {
  return moves.map((m, i) => {
    const ply = startDepth + i
    const number = Math.floor(ply / 2) + 1
    if (ply % 2 === 0) return `${number}.${m.san}`
    return i === 0 ? `${number}…${m.san}` : m.san
  })
}

/**
 * The repertoire's moves along a line, from a position, as far as the repertoire has them.
 *
 * @param {import('@/components/chess/moveTree').TreeIndex} index
 * @param {string} fromId
 * @param {string[]} ucis
 * @returns {string[]} move ids
 */
export function walkLine(index, fromId, ucis) {
  const ids = []
  let current = fromId
  for (const uci of ucis) {
    const next = children(index, current).find(m => m.uci === uci)
    if (!next) break
    ids.push(next.id)
    current = next.to
  }
  return ids
}

/**
 * The first place where the line plays another move than the one prepared in the repertoire
 * (saving it replaces that move), null when there is none.
 *
 * @param {import('@/stores/repertoire').EditorGraph} graph
 * @param {import('@/components/chess/moveTree').TreeIndex} index
 * @param {string} fromId
 * @param {DraftMove[]} moves
 * @returns {DraftConflict|null}
 */
export function draftConflict(graph, index, fromId, moves) {
  const userTurn = graph.color === 'black' ? 'b' : 'w'
  let current = fromId
  for (const move of moves) {
    const position = graph.positions[current]
    if (!position) return null
    const out = children(index, current)
    const same = out.find(m => m.uci === move.uci)
    if (same) {
      current = same.to
      continue
    }
    const prepared =
      position.turn === userTurn
        ? (out.find(m => m.role === 'reference') ?? null)
        : null
    return prepared
      ? { prepared, uci: move.uci, san: move.san, positionId: current }
      : null
  }
  return null
}

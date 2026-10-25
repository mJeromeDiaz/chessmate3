import { children } from '@/components/chess/moveTree'
import { draftLabels } from '@/utils/repertoireDraft'

/**
 * @typedef {object} RepertoireLine a line of the repertoire, from the initial position to a line
 *   end (docs/REPERTOIRE.md, "Lignes")
 * @property {string} key the line's last move
 * @property {string[]} path move ids from the root (the editor's path to the line end)
 * @property {string} text numbered SAN: "1.e4 e5 2.Nf3"
 * @property {{eco: string, name: string}|null} opening the last named position on the way
 * @property {number} userMoves moves of the user (prepared moves)
 * @property {boolean} unanswered ends on an opponent's move the user has no answer to
 * @property {boolean} transposition ends by joining another line
 * @property {string} cutMoveId the first move only this line has (after its last branching):
 *   deleting it removes the line
 * @property {number} cutIndex position of that move in the path
 */

/**
 * Every line of the repertoire: the canonical moves from the initial position to a position
 * without continuation, or to a transposition (included, it ends the line). In the tree's order:
 * main line first.
 *
 * @param {import('@/components/chess/moveTree').TreeIndex} index
 * @param {import('@/stores/repertoire').EditorGraph} graph
 * @returns {RepertoireLine[]}
 */
export function repertoireLines(index, graph) {
  /** @type {RepertoireLine[]} */
  const lines = []
  /** @type {{positionId: string, path: string[]}[]} */
  const stack = [{ positionId: graph.rootId, path: [] }]
  while (stack.length) {
    const { positionId, path } = stack.pop()
    const out = children(index, positionId)
    if (!out.length) {
      if (path.length) lines.push(describe(index, graph, path))
      continue
    }
    // Reversed onto the stack: the main line comes out first.
    for (let i = out.length - 1; i >= 0; i--) {
      const move = out[i]
      if (move.canonical) {
        stack.push({ positionId: move.to, path: [...path, move.id] })
      } else {
        lines.push(describe(index, graph, [...path, move.id]))
      }
    }
  }
  return lines
}

/**
 * The lines whose moves or opening name contain the text (case and accents ignored).
 *
 * @param {RepertoireLine[]} lines
 * @param {string} query
 * @returns {RepertoireLine[]}
 */
export function filterLines(lines, query) {
  const words = fold(query).split(/\s+/).filter(Boolean)
  if (!words.length) return lines
  return lines.filter(line => {
    const haystack = fold(
      `${line.text} ${line.opening?.eco ?? ''} ${line.opening?.name ?? ''}`
    )
    return words.every(w => haystack.includes(w))
  })
}

/** @param {string} text */
function fold(text) {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
}

/**
 * @param {import('@/components/chess/moveTree').TreeIndex} index
 * @param {import('@/stores/repertoire').EditorGraph} graph
 * @param {string[]} path
 * @returns {RepertoireLine}
 */
function describe(index, graph, path) {
  const moves = path.map(id => graph.moves[id])
  const last = moves.at(-1)
  let opening = null
  let cutIndex = 0
  moves.forEach((move, i) => {
    opening = graph.positions[move.to]?.opening ?? opening
    if (children(index, move.from).length > 1) cutIndex = i
  })
  return {
    key: last.id,
    path,
    text: draftLabels(0, moves).join(' '),
    opening,
    userMoves: moves.filter(m => m.role === 'reference').length,
    unanswered: last.canonical && last.role === 'reply',
    transposition: !last.canonical,
    cutMoveId: path[cutIndex],
    cutIndex
  }
}

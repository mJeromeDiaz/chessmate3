/**
 * Pure helpers of the move tree (MoveTree.vue and every page that navigates a graph of positions).
 *
 * The model is a graph, not a tree: positions (nodes) and moves (edges). Each position but the
 * root has exactly one canonical incoming move; the other moves reaching it are transpositions.
 * Following canonical moves only gives the tree that is displayed; a transposition is shown as a
 * leaf pointing to the position it joins. See docs/REPERTOIRE.md, "Chemin canonique".
 *
 * Navigation works on a path: the list of move ids played from the root, the last one being the
 * current move (an empty path is the root position).
 */

/**
 * @typedef {object} TreePosition
 * @property {string} id
 * @property {string} fen normalized FEN (4 fields)
 * @property {'w'|'b'} turn
 * @property {number} depth half-moves from the root along the canonical path
 * @property {{eco: string, name: string}|null} [opening]
 *
 * @typedef {object} TreeMove
 * @property {string} id
 * @property {string} from position id
 * @property {string} to position id
 * @property {string} uci
 * @property {string} san
 * @property {number} sortOrder the smallest first: the main line
 * @property {boolean} canonical
 * @property {string|null} [comment]
 * @property {number[]} [nags]
 * @property {string} [role]
 *
 * @typedef {object} TreeGraph
 * @property {string} rootId
 * @property {Record<string, TreePosition>} positions
 * @property {Record<string, TreeMove>} moves
 *
 * @typedef {object} TreeIndex
 * @property {Map<string, TreeMove[]>} out moves leaving each position, main line first
 * @property {Map<string, TreeMove[]>} in moves reaching each position
 * @property {Map<string, TreeMove>} canonicalIn the canonical move reaching each position
 *
 * @typedef {{kind: 'move', move: TreeMove, number: string|null}} MoveItem
 * @typedef {{kind: 'variations', depth: number, lines: DisplayLine[]}} VariationsItem
 * @typedef {Array<MoveItem|VariationsItem>} DisplayLine
 */

/**
 * Main line first, then creation order (UUID v7 ids sort by time).
 *
 * @param {TreeMove} a
 * @param {TreeMove} b
 */
const bySortOrder = (a, b) =>
  a.sortOrder - b.sortOrder || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0)

/**
 * @param {TreeGraph} graph
 * @returns {TreeIndex}
 */
export function indexGraph(graph) {
  /** @type {Map<string, TreeMove[]>} */
  const out = new Map()
  /** @type {Map<string, TreeMove[]>} */
  const incoming = new Map()
  /** @type {Map<string, TreeMove>} */
  const canonicalIn = new Map()
  for (const move of Object.values(graph.moves)) {
    if (!out.has(move.from)) out.set(move.from, [])
    out.get(move.from).push(move)
    if (!incoming.has(move.to)) incoming.set(move.to, [])
    incoming.get(move.to).push(move)
    if (move.canonical) canonicalIn.set(move.to, move)
  }
  for (const moves of out.values()) moves.sort(bySortOrder)
  return { out, in: incoming, canonicalIn }
}

/**
 * @param {TreeIndex} index
 * @param {string} positionId
 * @returns {TreeMove[]}
 */
export function children(index, positionId) {
  return index.out.get(positionId) ?? []
}

/**
 * Position at the end of a path.
 *
 * @param {TreeGraph} graph
 * @param {string[]} path
 * @returns {string}
 */
export function positionAt(graph, path) {
  return path.length ? graph.moves[path.at(-1)].to : graph.rootId
}

/**
 * The canonical moves from the root to a position.
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string} positionId
 * @returns {string[]}
 */
export function canonicalPath(index, graph, positionId) {
  const path = []
  let current = positionId
  const seen = new Set()
  while (current !== graph.rootId) {
    const move = index.canonicalIn.get(current)
    // Unreachable position (being deleted) or a broken index: stop rather than loop.
    if (!move || seen.has(move.id)) return []
    seen.add(move.id)
    path.push(move.id)
    current = move.from
  }
  return path.reverse()
}

/**
 * The path that selects a move: the canonical path to its start, then the move itself.
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string} moveId
 * @returns {string[]}
 */
export function pathToMove(index, graph, moveId) {
  const move = graph.moves[moveId]
  if (!move) return []
  return [...canonicalPath(index, graph, move.from), moveId]
}

/**
 * Keeps the longest prefix of a path whose moves still exist and chain up (after a deletion or an
 * undo).
 *
 * @param {TreeGraph} graph
 * @param {string[]} path
 * @returns {string[]}
 */
export function validPrefix(graph, path) {
  const kept = []
  let position = graph.rootId
  for (const id of path) {
    const move = graph.moves[id]
    if (!move || move.from !== position) break
    kept.push(id)
    position = move.to
  }
  return kept
}

/**
 * One move forward along the main line (or the path unchanged at the end of the line).
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string[]} path
 * @returns {string[]}
 */
export function forward(index, graph, path) {
  const next = children(index, positionAt(graph, path))[0]
  return next ? [...path, next.id] : path
}

/**
 * @param {string[]} path
 * @returns {string[]}
 */
export function back(path) {
  return path.slice(0, -1)
}

/**
 * The current move replaced by its previous (delta -1) or next (delta 1) sibling: another move
 * from the same position.
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string[]} path
 * @param {-1|1} delta
 * @returns {string[]}
 */
export function sibling(index, graph, path, delta) {
  if (!path.length) return path
  const current = graph.moves[path.at(-1)]
  const siblings = children(index, current.from)
  const target = siblings[siblings.findIndex(m => m.id === current.id) + delta]
  return target ? [...path.slice(0, -1), target.id] : path
}

/**
 * The path followed along the main line to the end of the line.
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string[]} path
 * @returns {string[]}
 */
export function lineEnd(index, graph, path) {
  const result = [...path]
  const seen = new Set(result)
  for (;;) {
    const next = children(index, positionAt(graph, result))[0]
    if (!next || seen.has(next.id)) return result
    seen.add(next.id)
    result.push(next.id)
  }
}

/**
 * Whether a position can be reached from another one (a move from `to` back to `from` would
 * close a cycle).
 *
 * @param {TreeIndex} index
 * @param {string} from
 * @param {string} to
 * @returns {boolean}
 */
export function reaches(index, from, to) {
  const stack = [from]
  const seen = new Set()
  while (stack.length) {
    const current = stack.pop()
    if (current === to) return true
    if (seen.has(current)) continue
    seen.add(current)
    for (const move of children(index, current)) stack.push(move.to)
  }
  return false
}

/**
 * What deleting a move removes: the move, and every position (with its moves) no longer
 * reachable from the root without it.
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string} moveId
 * @returns {{moveIds: string[], positionIds: string[]}}
 */
export function removal(index, graph, moveId) {
  const reachable = new Set([graph.rootId])
  const stack = [graph.rootId]
  while (stack.length) {
    for (const move of children(index, stack.pop())) {
      if (move.id === moveId || reachable.has(move.to)) continue
      reachable.add(move.to)
      stack.push(move.to)
    }
  }
  const positionIds = Object.keys(graph.positions).filter(
    id => !reachable.has(id)
  )
  const gone = new Set(positionIds)
  const moveIds = Object.values(graph.moves)
    .filter(m => m.id === moveId || gone.has(m.from) || gone.has(m.to))
    .map(m => m.id)
  return { moveIds, positionIds }
}

/**
 * Move number as written before a move: "3." for White, "3…" for Black.
 *
 * @param {TreePosition} position the position the move is played from
 * @returns {string}
 */
export function moveNumber(position) {
  const number = Math.floor(position.depth / 2) + 1
  return position.turn === 'w' ? `${number}.` : `${number}…`
}

/**
 * The tree as it is written (like Lichess or a PGN): the main line, each group of variations right
 * after the move they replace, move numbers where they are needed. Transpositions end their line.
 *
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @returns {DisplayLine}
 */
export function displayLines(index, graph) {
  /** @type {DisplayLine} */
  const root = []
  continueLine(index, graph, graph.rootId, 0, root, true)
  return root
}

/**
 * @param {TreeIndex} index
 * @param {TreeGraph} graph
 * @param {string} positionId
 * @param {number} depth nesting level of the line (0: main line)
 * @param {DisplayLine} items appended to
 * @param {boolean} forceNumber
 */
function continueLine(index, graph, positionId, depth, items, forceNumber) {
  let position = positionId
  let force = forceNumber
  const seen = new Set()
  for (;;) {
    const [main, ...others] = children(index, position)
    if (!main || seen.has(main.id)) return
    seen.add(main.id)
    items.push(moveItem(graph, main, force))
    force = false
    if (others.length) {
      items.push({
        kind: 'variations',
        depth: depth + 1,
        lines: others.map(other => {
          /** @type {DisplayLine} */
          const line = [moveItem(graph, other, true)]
          if (other.canonical)
            continueLine(index, graph, other.to, depth + 1, line, false)
          return line
        })
      })
      force = true
    }
    if (!main.canonical) return
    position = main.to
  }
}

/**
 * @param {TreeGraph} graph
 * @param {TreeMove} move
 * @param {boolean} force
 * @returns {MoveItem}
 */
function moveItem(graph, move, force) {
  const from = graph.positions[move.from]
  return {
    kind: 'move',
    move,
    number: from && (force || from.turn === 'w') ? moveNumber(from) : null
  }
}

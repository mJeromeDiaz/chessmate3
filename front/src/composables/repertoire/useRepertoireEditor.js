import { computed, ref, watch } from 'vue'
import { Chess } from 'chess.js'
import { children, positionAt, validPrefix } from '@/components/chess/moveTree'
import { moveGlyph } from '@/utils/chess/nags'
import { normalizeFen } from '@/utils/chess/normalizeFen'

/**
 * @typedef {import('@/stores/repertoire').Move} Move
 * @typedef {import('@/stores/repertoire').Position} Position
 * @typedef {{uci: string, san: string, fen: string}} ExploredMove a move played while exploring,
 *   not saved (fen: the full FEN after it)
 * @typedef {import('@/stores/repertoire').AddResult | {explored: true}} PlayResult
 */

/**
 * State of the repertoire editor around the store's graph: where the user is (the path), what
 * the board shows (position, last move, known continuations, annotation), the opening name, and
 * playing a move on the board.
 *
 * The path may hold temporary ids of moves not saved yet: it is read through the store's
 * mapping, cut where a move no longer exists (deletion, undo, reload).
 *
 * Exploration mode: moves are played without being saved. A known move follows the repertoire;
 * any other one leaves it ("off book": {@link offBook}, after the path's position) until the user
 * goes back, picks a move in the tree, or leaves the mode.
 *
 * @param {ReturnType<typeof import('@/stores/repertoire').useRepertoireStore>} store
 */
export function useRepertoireEditor(store) {
  /** @type {import('vue').Ref<string[]>} */
  const rawPath = ref([])
  /** @type {import('vue').Ref<'white'|'black'>} */
  const orientation = ref('white')
  const exploring = ref(false)
  /** @type {import('vue').Ref<ExploredMove[]>} */
  const offBook = ref([])

  const path = computed({
    get: () =>
      store.graph
        ? validPrefix(store.graph, rawPath.value.map(store.resolve))
        : [],
    set: value => {
      rawPath.value = value
    }
  })

  /** @type {import('vue').ComputedRef<Position|null>} */
  const position = computed(() =>
    store.graph
      ? store.graph.positions[positionAt(store.graph, path.value)]
      : null
  )
  /** @type {import('vue').ComputedRef<Move|null>} */
  const currentMove = computed(() =>
    path.value.length ? store.graph.moves[path.value.at(-1)] : null
  )
  /** FEN for the board, with move counters. */
  const fen = computed(() => {
    if (offBook.value.length) return offBook.value.at(-1).fen
    return position.value
      ? `${position.value.fen} 0 ${Math.floor(position.value.depth / 2) + 1}`
      : ''
  })
  /**
   * The position on the board for the Lichess panels: normalized FEN, side to move and ply (off
   * book included).
   *
   * @type {import('vue').ComputedRef<{fen: string, turn: 'w'|'b', depth: number}|null>}
   */
  const boardPosition = computed(() => {
    const p = position.value
    if (!p) return null
    if (!offBook.value.length)
      return { fen: p.fen, turn: p.turn, depth: p.depth }
    const depth = p.depth + offBook.value.length
    return {
      fen: normalizeFen(offBook.value.at(-1).fen),
      turn: depth % 2 === 0 ? 'w' : 'b',
      depth
    }
  })
  const userTurn = computed(() => (store.graph?.color === 'black' ? 'b' : 'w'))
  /** @type {import('vue').ComputedRef<Move[]>} */
  const continuations = computed(() =>
    position.value && store.index && !offBook.value.length
      ? children(store.index, position.value.id)
      : []
  )

  /** Name of the last named position of the path (the opening being played). */
  const opening = computed(() => {
    const g = store.graph
    if (!g) return null
    const ids = [g.rootId, ...path.value.map(id => g.moves[id].to)]
    for (let i = ids.length - 1; i >= 0; i--) {
      const named = g.positions[ids[i]]?.opening
      if (named) return named
    }
    return null
  })

  const highlights = computed(() => {
    const move = offBook.value.at(-1) ?? currentMove.value
    if (!move) return []
    return [
      { square: move.uci.slice(0, 2), type: 'lastMove' },
      { square: move.uci.slice(2, 4), type: 'lastMove' }
    ]
  })

  /** Known continuations: the prepared move in green, replies in blue. */
  const arrows = computed(() =>
    continuations.value.map(move => ({
      from: move.uci.slice(0, 2),
      to: move.uci.slice(2, 4),
      type: move.role === 'reference' ? 'solution' : 'hint'
    }))
  )

  const glyphs = computed(() => {
    if (offBook.value.length) return []
    const move = currentMove.value
    const glyph = move ? moveGlyph(move.nags ?? []) : null
    return glyph ? [{ square: move.uci.slice(2, 4), text: glyph }] : []
  })

  /**
   * An opponent's move (reply) whose position has no answer from the user yet.
   *
   * @param {Move} move
   */
  function isUnanswered(move) {
    if (move.role !== 'reply' || !move.canonical || !store.index) return false
    return !children(store.index, move.to).some(m => m.role === 'reference')
  }

  /**
   * Plays a move from the current position and goes to it: saved, or explored in exploration
   * mode.
   *
   * @param {string} uci
   * @returns {PlayResult}
   * @throws {Error} illegal move, a move back to a position the line comes from, or a second
   *   prepared move in a position (PositionOccupiedError: the page offers to replace it)
   */
  function play(uci) {
    if (exploring.value) return explore(uci)
    const result = store.addMove(position.value.id, uci)
    rawPath.value = [...path.value, result.moveId]
    return result
  }

  /**
   * Plays a move without saving it: a known move follows the repertoire, any other one goes off
   * book.
   *
   * @param {string} uci
   * @returns {PlayResult}
   * @throws {Error} illegal move
   */
  function explore(uci) {
    exploring.value = true
    const known = continuations.value.find(m => m.uci === uci)
    if (known) {
      rawPath.value = [...path.value, known.id]
      return { moveId: known.id, created: false, transposition: false }
    }
    const chess = new Chess(fen.value)
    const played = chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
    offBook.value = [
      ...offBook.value,
      { uci, san: played.san, fen: chess.fen() }
    ]
    return { explored: true }
  }

  /** One move back: off book first, then along the path. */
  function back() {
    if (offBook.value.length) {
      offBook.value = offBook.value.slice(0, -1)
      return
    }
    rawPath.value = path.value.slice(0, -1)
  }

  /** @param {boolean} on */
  function setExploring(on) {
    exploring.value = on
    if (!on) offBook.value = []
  }

  function flip() {
    orientation.value = orientation.value === 'white' ? 'black' : 'white'
  }

  // Another place in the tree: the moves explored from the former one no longer apply.
  watch(
    () => path.value.join(),
    () => (offBook.value = [])
  )

  // A newly opened repertoire starts at the initial position, from its side.
  watch(
    () => store.graph?.id,
    () => {
      rawPath.value = []
      setExploring(false)
      orientation.value = store.graph?.color === 'black' ? 'black' : 'white'
    },
    { immediate: true }
  )

  return {
    path,
    position,
    currentMove,
    fen,
    userTurn,
    continuations,
    opening,
    highlights,
    arrows,
    glyphs,
    orientation,
    exploring,
    offBook,
    boardPosition,
    isUnanswered,
    play,
    explore,
    back,
    setExploring,
    flip
  }
}

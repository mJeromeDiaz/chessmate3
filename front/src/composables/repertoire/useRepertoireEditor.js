import { computed, ref, watch } from 'vue'
import { Chess } from 'chess.js'
import {
  canonicalPath,
  children,
  positionAt,
  validPrefix
} from '@/components/chess/moveTree'
import { moveGlyph } from '@/utils/chess/nags'
import { normalizeFen } from '@/utils/chess/normalizeFen'
import { RepeatedPositionError } from '@/stores/repertoire'
import { emptyDraft, loadDraft, storeDraft } from '@/utils/repertoireDraft'

/**
 * @typedef {import('@/stores/repertoire').Move} Move
 * @typedef {import('@/stores/repertoire').Position} Position
 * @typedef {import('@/utils/repertoireDraft').DraftMove} DraftMove
 * @typedef {import('@/utils/repertoireDraft').Draft} Draft
 * @typedef {{moveId: string, drafted: false} | {drafted: true}} PlayResult
 */

/**
 * State of the repertoire editor around the store's graph: where the user is (the path), what
 * the board shows (position, last move, known continuations, annotation), the opening name, and
 * playing a move on the board.
 *
 * The path is cut where a move no longer exists (deletion, undo, reload).
 *
 * The draft (docs/REPERTOIRE.md, "Brouillon"): a move of the repertoire follows it; any other one
 * starts a line drafted from the path's position, never sent until the page saves it. The board
 * steps through the draft with a cursor; playing another move than the next one replaces the rest.
 * The draft is kept in this browser per repertoire, and dropped when its start position leaves
 * the repertoire (undo, reload) or the path moves elsewhere ({@link draftLost} tells the page).
 *
 * @param {ReturnType<typeof import('@/stores/repertoire').useRepertoireStore>} store
 */
export function useRepertoireEditor(store) {
  /** @type {import('vue').Ref<string[]>} */
  const rawPath = ref([])
  /** @type {import('vue').Ref<'white'|'black'>} */
  const orientation = ref('white')
  /** @type {import('vue').Ref<Draft>} */
  const draft = ref(emptyDraft())
  /** Set when a draft was dropped because its start position left the repertoire. */
  const draftLost = ref(0)
  /** @type {import('vue').ComputedRef<DraftMove[]>} the draft's moves up to the cursor */
  const shown = computed(() => draft.value.moves.slice(0, draft.value.cursor))
  const drafting = computed(() => draft.value.moves.length > 0)

  const path = computed({
    get: () =>
      store.graph
        ? validPrefix(store.graph, rawPath.value)
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
    if (shown.value.length) return shown.value.at(-1).fen
    return position.value
      ? `${position.value.fen} 0 ${Math.floor(position.value.depth / 2) + 1}`
      : ''
  })
  /**
   * The position on the board for the Lichess panels: normalized FEN, side to move and ply (draft
   * included).
   *
   * @type {import('vue').ComputedRef<{fen: string, turn: 'w'|'b', depth: number}|null>}
   */
  const boardPosition = computed(() => {
    const p = position.value
    if (!p) return null
    if (!shown.value.length) return { fen: p.fen, turn: p.turn, depth: p.depth }
    const depth = p.depth + shown.value.length
    return {
      fen: normalizeFen(shown.value.at(-1).fen),
      turn: depth % 2 === 0 ? 'w' : 'b',
      depth
    }
  })
  const userTurn = computed(() => (store.graph?.color === 'black' ? 'b' : 'w'))
  /** @type {import('vue').ComputedRef<Move[]>} */
  const continuations = computed(() =>
    position.value && store.index && !shown.value.length
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
    const move = shown.value.at(-1) ?? currentMove.value
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
    if (shown.value.length) return []
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
   * Plays a move from the position on the board: a move of the repertoire follows it, any other
   * one goes into the draft (nothing is saved).
   *
   * @param {string} uci
   * @returns {PlayResult}
   * @throws {Error} illegal move, or a move back to a position the line comes from
   *   (RepeatedPositionError)
   */
  function play(uci) {
    const d = draft.value
    if (d.cursor === 0) {
      const known = continuations.value.find(m => m.uci === uci)
      if (known) {
        if (d.moves[0]?.uci === uci) {
          d.cursor = 1
          persist()
          return { drafted: true }
        }
        rawPath.value = [...path.value, known.id]
        return { moveId: known.id, drafted: false }
      }
    } else if (d.moves[d.cursor]?.uci === uci) {
      d.cursor++
      persist()
      return { drafted: true }
    }
    const chess = new Chess(fen.value)
    const played = chess.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
    const reached = normalizeFen(chess.fen())
    if (lineFens().has(reached)) throw new RepeatedPositionError()
    draft.value = {
      from: position.value.id,
      moves: [
        ...d.moves.slice(0, d.cursor),
        { uci, san: played.san, fen: chess.fen() }
      ],
      cursor: d.cursor + 1
    }
    persist()
    return { drafted: true }
  }

  /** Normalized FENs of the line on the board: the path's positions, then the draft's. */
  function lineFens() {
    const g = store.graph
    const fens = new Set([g.positions[g.rootId]?.fen])
    for (const id of path.value) fens.add(g.positions[g.moves[id]?.to]?.fen)
    for (const m of shown.value) fens.add(normalizeFen(m.fen))
    return fens
  }

  /**
   * Steps the draft's cursor (in a draft) or the path.
   *
   * @param {'start'|'back'|'forward'|'end'} action
   * @returns {boolean} whether the draft handled it (the tree handles the path otherwise)
   */
  function stepDraft(action) {
    const d = draft.value
    if (!d.moves.length) return false
    d.cursor = {
      start: 0,
      back: Math.max(d.cursor - 1, 0),
      forward: Math.min(d.cursor + 1, d.moves.length),
      end: d.moves.length
    }[action]
    persist()
    return true
  }

  /** One move back: in the draft first, then along the path. */
  function back() {
    if (!stepDraft('back')) rawPath.value = path.value.slice(0, -1)
  }

  /** Forgets the draft. */
  function discardDraft() {
    draft.value = emptyDraft()
    persist()
  }

  function persist() {
    if (store.graph) storeDraft(store.graph.id, draft.value)
  }

  function flip() {
    orientation.value = orientation.value === 'white' ? 'black' : 'white'
  }

  // Another place in the tree, or the draft's start gone (undo, reload): the draft no longer
  // applies.
  watch(
    [
      () => position.value?.id,
      () => store.graph?.positions[draft.value.from ?? '']
    ],
    ([id, start]) => {
      if (!store.graph || !draft.value.moves.length) return
      if (!start) draftLost.value++
      if (!start || id !== draft.value.from) discardDraft()
    }
  )

  // A newly opened repertoire starts at the initial position, from its side, or where its draft
  // kept in this browser starts.
  watch(
    () => store.graph?.id,
    id => {
      rawPath.value = []
      draft.value = emptyDraft()
      orientation.value = store.graph?.color === 'black' ? 'black' : 'white'
      const saved = id ? loadDraft(id) : null
      if (!saved) return
      if (!store.graph.positions[saved.from] || !store.index) {
        storeDraft(id, emptyDraft())
        draftLost.value++
        return
      }
      rawPath.value = canonicalPath(store.index, store.graph, saved.from)
      draft.value = saved
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
    draft,
    drafting,
    draftLost,
    shown,
    boardPosition,
    isUnanswered,
    play,
    stepDraft,
    back,
    discardDraft,
    flip
  }
}

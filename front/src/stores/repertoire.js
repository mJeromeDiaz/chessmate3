import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { Chess } from 'chess.js'
import { repertoireApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { normalizeAfter } from '@/utils/chess/normalizeFen'
import { indexGraph, reaches, removal } from '@/components/chess/moveTree'

/**
 * @typedef {object} RepertoireSummary
 * @property {string} id
 * @property {string} name
 * @property {'white'|'black'} color
 * @property {number} positionCount
 * @property {number} segmentCount segments with a user's move: the units a test presents
 * @property {number} version
 * @property {string} createdAt
 * @property {string} updatedAt
 *
 * @typedef {import('@/components/chess/moveTree').TreePosition} Position
 * @typedef {import('@/components/chess/moveTree').TreeMove & {role: 'reference'|'reply', comment: string|null, nags: number[], segmentId: string|null}} Move
 * @typedef {{id: string, startMoveId: string|null, moveCount: number, userMoveCount: number}} Segment
 *
 * @typedef {object} EditorGraph
 * @property {string} id repertoire id
 * @property {string} name
 * @property {'white'|'black'} color
 * @property {number} version last version confirmed by the server
 * @property {string} rootId
 * @property {Record<string, Position>} positions
 * @property {Record<string, Move>} moves
 * @property {Record<string, Segment>} segments
 *
 * @typedef {object} Change the server's answer to a change (RepertoireChange)
 * @property {number} version
 * @property {string} operation
 * @property {string|null} moveId
 * @property {boolean} transposition
 * @property {string|null} trashId the trash entry a replacement or a deletion created
 * @property {Position[]} positions
 * @property {Move[]} moves
 * @property {Segment[]} segments
 * @property {string[]} deletedPositionIds
 * @property {string[]} deletedMoveIds
 *
 * @typedef {object} AddResult what the editor can tell at once, before the server answers
 * @property {string} moveId the new move (temporary id until saved) or the existing one
 * @property {boolean} created
 * @property {boolean} transposition the move reaches a position already in the repertoire
 *
 * @typedef {{status: number|null, operation: string}} Failure
 *
 * @typedef {object} TrashedSuite a suite of the trash: a move and what only it reached
 * @property {string} id
 * @property {'replaced'|'deleted'|'imported'|'migrated'} reason
 * @property {string} fromFen normalized FEN of the position it starts from
 * @property {string} uci
 * @property {string} san
 * @property {string[]} path SAN moves from the initial position to fromFen
 * @property {number} positionCount
 * @property {number} moveCount
 * @property {string} createdAt
 *
 * @typedef {{fen: string, path: string[], restored: {uci: string, san: string}, current: {uci: string, san: string}, choice: 'restored'|'current'}} RestoreConflict
 * @typedef {{conflicts: RestoreConflict[], positions: number, moves: number, joined: number, leftOut: number, replaced: number}} RestorePreview
 */

const TEMP_PREFIX = 'tmp-'

/**
 * Opening repertoires: the list, and the graph being edited.
 *
 * Every edit is applied locally at once (optimistic), then sent to the server one at a time, in
 * order, each with the version it is based on. Moves and positions created locally carry a
 * temporary id until the server answers with the real one ({@link resolve} follows the mapping).
 * If a change is refused, the changes queued after it are dropped and the graph is reloaded from
 * the server: the local copy never drifts from what is saved. `failure` tells the page what
 * happened.
 */
export const useRepertoireStore = defineStore('repertoire', () => {
  /** @type {import('vue').Ref<RepertoireSummary[]>} */
  const repertoires = ref([])
  /** @type {import('vue').Ref<EditorGraph|null>} */
  const graph = ref(null)
  /** Changes sent or waiting to be sent. */
  const pending = ref(0)
  /** @type {import('vue').Ref<Failure|null>} the last refused change (the graph was reloaded) */
  const failure = ref(null)
  /** @type {import('vue').Ref<Record<string, string>>} temporary id => server id */
  const aliases = ref({})

  let queue = Promise.resolve()
  /** Incremented to drop the queued changes (after a refusal, or on another graph). */
  let generation = 0
  let tempCounter = 0
  /** Incremented when another graph is loaded: the old changes no longer count as pending. */
  let session = 0

  const index = computed(() => (graph.value ? indexGraph(graph.value) : null))
  const saving = computed(() => pending.value > 0)

  // Nothing of a user may remain for the next one in this tab.
  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) reset()
    }
  )

  function reset() {
    generation++
    session++
    repertoires.value = []
    graph.value = null
    pending.value = 0
    failure.value = null
    aliases.value = {}
  }

  async function fetchList() {
    repertoires.value = await repertoireApi.list()
    return repertoires.value
  }

  /** @param {{name: string, color: 'white'|'black'}} payload */
  async function create(payload) {
    const created = await repertoireApi.create(payload)
    repertoires.value = [created, ...repertoires.value]
    return created
  }

  /**
   * @param {string} id
   * @param {string} name
   */
  async function rename(id, name) {
    const renamed = await repertoireApi.rename(id, name)
    repertoires.value = repertoires.value.map(r => (r.id === id ? renamed : r))
    if (graph.value?.id === id) graph.value.name = renamed.name
    return renamed
  }

  /** @param {string} id */
  async function remove(id) {
    await repertoireApi.remove(id)
    repertoires.value = repertoires.value.filter(r => r.id !== id)
    if (graph.value?.id === id) {
      generation++
      graph.value = null
    }
  }

  /**
   * Loads a repertoire's graph for the editor (drops what was queued for another one).
   *
   * @param {string} id
   */
  async function load(id) {
    generation++
    session++
    pending.value = 0
    failure.value = null
    aliases.value = {}
    queue = Promise.resolve()
    graph.value = toEditorGraph(await repertoireApi.graph(id))
    return graph.value
  }

  /**
   * The server id of a move or position created locally (the id itself otherwise).
   *
   * @param {string} id
   * @returns {string}
   */
  function resolve(id) {
    let current = id
    while (aliases.value[current]) current = aliases.value[current]
    return current
  }

  /**
   * Plays a move from a position of the repertoire.
   *
   * @param {string} fromPositionId
   * @param {string} uci
   * @returns {AddResult}
   * @throws {Error} illegal move, or a move back to a position the line comes from
   */
  function addMove(fromPositionId, uci) {
    const g = requireGraph()
    const fromId = resolve(fromPositionId)
    const from = g.positions[fromId]
    if (!from) throw new Error('Unknown position')
    const existing = (index.value.out.get(fromId) ?? []).find(
      m => m.uci === uci
    )
    if (existing) {
      return { moveId: existing.id, created: false, transposition: false }
    }

    const probe = new Chess(`${from.fen} 0 1`)
    const played = probe.move({
      from: uci.slice(0, 2),
      to: uci.slice(2, 4),
      promotion: uci[4]
    })
    const fen = normalizeAfter(from.fen, [uci])
    const target = Object.values(g.positions).find(p => p.fen === fen)
    if (target && reaches(index.value, target.id, fromId)) {
      throw new RepeatedPositionError()
    }

    const siblings = index.value.out.get(fromId) ?? []
    const userTurn = g.color === 'white' ? 'w' : 'b'
    const prepared =
      from.turn === userTurn
        ? (siblings.find(m => m.role === 'reference') ?? null)
        : null
    if (prepared) throw new PositionOccupiedError(prepared)
    let toId = target?.id
    if (!toId) {
      toId = tempId()
      g.positions[toId] = {
        id: toId,
        fen,
        turn: from.turn === 'w' ? 'b' : 'w',
        depth: from.depth + 1,
        opening: null
      }
    }
    const moveId = tempId()
    g.moves[moveId] = {
      id: moveId,
      from: fromId,
      to: toId,
      uci,
      san: played.san,
      role: from.turn === userTurn ? 'reference' : 'reply',
      sortOrder: siblings.length
        ? Math.max(...siblings.map(m => m.sortOrder)) + 1
        : 0,
      comment: null,
      nags: [],
      canonical: !target,
      segmentId: null
    }

    enqueue(
      'add',
      v =>
        repertoireApi.addMove(g.id, {
          fromPositionId: resolve(fromId),
          uci,
          baseVersion: v
        }),
      change => {
        const real = change.moveId
        const realTo = real ? graph.value.moves[real]?.to : null
        if (!real || !realTo) return
        delete graph.value.moves[moveId]
        alias(moveId, real)
        if (!target) {
          delete graph.value.positions[toId]
          alias(toId, realTo)
        }
      }
    )

    return { moveId, created: true, transposition: !!target }
  }

  /**
   * Another prepared move in place of the user's move: the former one, with what only it
   * reached, goes to the trash. Not optimistic (what stays depends on the whole graph): resolves
   * with the new move's id once saved, null when refused.
   *
   * @param {string} moveId the prepared move replaced
   * @param {string} uci
   * @returns {Promise<string|null>}
   */
  async function replaceMove(moveId, uci) {
    const g = requireGraph()
    const id = resolve(moveId)
    if (!g.moves[id]) return null
    const change = await enqueue('replace', v =>
      repertoireApi.replaceMove(g.id, resolve(id), { uci, baseVersion: v })
    )
    return change?.moveId ?? null
  }

  /**
   * Makes a move the main line of its position.
   *
   * @param {string} moveId
   */
  function promote(moveId) {
    const g = requireGraph()
    const move = g.moves[resolve(moveId)]
    if (!move) return
    moveFirst(move)
    queueAction(move.id, 'promote')
  }

  /**
   * Replaces a move's comment (plain text) and NAGs.
   *
   * @param {string} moveId
   * @param {{comment: string|null, nags: number[]}} annotation
   */
  function annotate(moveId, { comment, nags }) {
    const g = requireGraph()
    const move = g.moves[resolve(moveId)]
    if (!move) return
    const text = comment?.trim() ? comment : null
    move.comment = text
    move.nags = [...nags]
    const id = move.id
    enqueue('annotate', v =>
      repertoireApi.annotate(g.id, resolve(id), {
        comment: text,
        nags: [...nags],
        baseVersion: v
      })
    )
  }

  /**
   * What deleting a move would remove (for the confirmation).
   *
   * @param {string} moveId
   * @returns {{moveIds: string[], positionIds: string[]}}
   */
  function removalOf(moveId) {
    requireGraph()
    return removal(index.value, graph.value, resolve(moveId))
  }

  /**
   * Deletes a move and everything only reachable through it.
   *
   * @param {string} moveId
   */
  function deleteMove(moveId) {
    const g = requireGraph()
    const id = resolve(moveId)
    if (!g.moves[id]) return
    const { moveIds, positionIds } = removal(index.value, g, id)
    for (const mid of moveIds) delete g.moves[mid]
    for (const pid of positionIds) delete g.positions[pid]
    // A position still reached by a transposition gets a canonical move again (the server
    // confirms which one).
    const reached = new Set()
    for (const m of Object.values(g.moves)) if (m.canonical) reached.add(m.to)
    for (const m of Object.values(g.moves).sort((a, b) =>
      a.id < b.id ? -1 : 1
    )) {
      if (!reached.has(m.to)) {
        m.canonical = true
        reached.add(m.to)
      }
    }
    queueAction(id, 'delete')
  }

  /**
   * Resolves once every change queued so far has been sent (saved or refused).
   *
   * @returns {Promise<void>}
   */
  function idle() {
    return queue
  }

  /** Undoes the last saved change (the server keeps the history). */
  function undo() {
    const g = requireGraph()
    return enqueue('undo', v => repertoireApi.undo(g.id, { baseVersion: v }))
  }

  /**
   * The suites of the trash, newest first.
   *
   * @returns {Promise<TrashedSuite[]>}
   */
  function fetchTrash() {
    return repertoireApi.trash(requireGraph().id)
  }

  /**
   * What restoring a suite would do with these choices.
   *
   * @param {string} trashId
   * @param {Record<string, 'restored'|'current'>} [choices] normalized FEN => choice
   * @returns {Promise<TrashedSuite & {restorable: boolean, preview: RestorePreview|null}>}
   */
  function previewRestore(trashId, choices = {}) {
    return repertoireApi.trashPreview(requireGraph().id, trashId, choices)
  }

  /**
   * Brings a suite of the trash back (a change of the graph, after the queued ones).
   *
   * @param {string} trashId
   * @param {Record<string, 'restored'|'current'>} [choices]
   * @returns {Promise<Change|null>} null when refused (the graph was reloaded)
   */
  function restore(trashId, choices = {}) {
    const g = requireGraph()
    return enqueue('restore', v =>
      repertoireApi.restore(g.id, trashId, { choices, baseVersion: v })
    )
  }

  /**
   * Removes a suite from the trash for good (not a change of the graph).
   *
   * @param {string} trashId
   */
  function discard(trashId) {
    return repertoireApi.discard(requireGraph().id, trashId)
  }

  /**
   * @param {string} moveId
   * @param {'promote'|'delete'} action
   */
  function queueAction(moveId, action) {
    const g = requireGraph()
    enqueue(action, v =>
      repertoireApi.moveAction(g.id, resolve(moveId), action, {
        baseVersion: v
      })
    )
  }

  /**
   * Sends a change after the ones already queued, with the version it is based on, and merges the
   * server's answer. Resolves with the change, or null when it was dropped or refused.
   *
   * @param {string} operation
   * @param {(baseVersion: number) => Promise<Change>} send
   * @param {(change: Change) => void} [saved] run right after the merge, before the next change
   * @returns {Promise<Change|null>}
   */
  function enqueue(operation, send, saved) {
    const mine = generation
    const mySession = session
    const repertoireId = graph.value?.id
    pending.value++
    const run = queue.then(async () => {
      if (mine !== generation || graph.value?.id !== repertoireId) return null
      try {
        const change = await send(graph.value.version)
        if (mine !== generation) return null
        merge(change)
        saved?.(change)
        return change
      } catch (error) {
        if (mine !== generation) return null
        generation++
        failure.value = {
          status: /** @type {any} */ (error)?.response?.status ?? null,
          operation
        }
        await reload(repertoireId)
        return null
      }
    })
    queue = run.then(() => {
      if (mySession === session) pending.value--
    })
    return run
  }

  /** @param {string} id */
  async function reload(id) {
    aliases.value = {}
    try {
      const fresh = await repertoireApi.graph(id)
      if (graph.value?.id === id) graph.value = toEditorGraph(fresh)
    } catch {
      // Unreachable server: the page shows the failure; the next load starts over.
    }
  }

  /**
   * Applies the server's answer: current state of what it touched, deletions, new version.
   *
   * @param {Change} change
   */
  function merge(change) {
    const g = graph.value
    if (!g) return
    for (const id of change.deletedMoveIds ?? []) delete g.moves[id]
    for (const id of change.deletedPositionIds ?? []) delete g.positions[id]
    for (const p of change.positions ?? []) g.positions[p.id] = p
    for (const m of change.moves ?? []) g.moves[m.id] = m
    for (const s of change.segments ?? []) g.segments[s.id] = s
    g.version = change.version
  }

  /**
   * @param {string} temp
   * @param {string} real
   */
  function alias(temp, real) {
    aliases.value[temp] = real
    const g = graph.value
    if (!g) return
    // Moves created locally after this one may start from (or reach) the renamed position.
    for (const m of Object.values(g.moves)) {
      if (m.from === temp) m.from = real
      if (m.to === temp) m.to = real
    }
  }

  /** @param {Move} move */
  function moveFirst(move) {
    const siblings = index.value.out.get(move.from) ?? []
    move.sortOrder = Math.min(...siblings.map(m => m.sortOrder)) - 1
  }

  function requireGraph() {
    if (!graph.value) throw new Error('No repertoire loaded')
    return graph.value
  }

  function tempId() {
    tempCounter++
    return `${TEMP_PREFIX}${tempCounter}`
  }

  return {
    repertoires,
    graph,
    index,
    pending,
    saving,
    failure,
    reset,
    fetchList,
    create,
    rename,
    remove,
    load,
    resolve,
    addMove,
    replaceMove,
    promote,
    annotate,
    removalOf,
    deleteMove,
    fetchTrash,
    previewRestore,
    restore,
    discard,
    undo,
    idle
  }
})

/** A move back to a position the line comes from (the repertoire is acyclic). */
export class RepeatedPositionError extends Error {
  constructor() {
    super('This move goes back to a position the line comes from')
    this.name = 'RepeatedPositionError'
  }
}

/** The user already has a prepared move in this position: it can only be replaced. */
export class PositionOccupiedError extends Error {
  /** @param {Move} prepared the move prepared in this position */
  constructor(prepared) {
    super('A move is already prepared in this position')
    this.name = 'PositionOccupiedError'
    this.prepared = prepared
  }
}

/**
 * @param {string} id
 * @returns {boolean}
 */
export function isTemporaryId(id) {
  return id.startsWith(TEMP_PREFIX)
}

/**
 * @param {any} data the API graph (RepertoireGraph)
 * @returns {EditorGraph}
 */
function toEditorGraph(data) {
  return {
    id: data.id,
    name: data.name,
    color: data.color,
    version: data.version,
    rootId: data.rootPositionId,
    positions: Object.fromEntries(data.positions.map(p => [p.id, p])),
    moves: Object.fromEntries(data.moves.map(m => [m.id, m])),
    segments: Object.fromEntries(data.segments.map(s => [s.id, s]))
  }
}

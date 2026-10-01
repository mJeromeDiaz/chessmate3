import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

/** Lets every settled promise run its callbacks. */
const flushPromises = () => new Promise(resolve => setTimeout(resolve, 0))

vi.mock('@/services/api', () => ({
  repertoireApi: {
    list: vi.fn(),
    create: vi.fn(),
    rename: vi.fn(),
    remove: vi.fn(),
    graph: vi.fn(),
    addMove: vi.fn(),
    moveAction: vi.fn(),
    replaceMove: vi.fn(),
    annotate: vi.fn(),
    undo: vi.fn(),
    trash: vi.fn(),
    trashPreview: vi.fn(),
    restore: vi.fn(),
    discard: vi.fn()
  }
}))

const { repertoireApi } = await import('@/services/api')
const {
  useRepertoireStore,
  RepeatedPositionError,
  PositionOccupiedError,
  isTemporaryId
} = await import('@/stores/repertoire')

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -'
const AFTER_E4 = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -'
const AFTER_E4_E5 = 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -'

const position = (id, fen, depth, opening = null) => ({
  id,
  fen,
  turn: fen.split(' ')[1],
  depth,
  opening
})
const move = (id, from, to, uci, san, role, extra = {}) => ({
  id,
  from,
  to,
  uci,
  san,
  role,
  sortOrder: 0,
  comment: null,
  nags: [],
  canonical: true,
  segmentId: null,
  ...extra
})
const change = (version, extra = {}) => ({
  version,
  operation: 'add',
  moveId: null,
  transposition: false,
  trashId: null,
  positions: [],
  moves: [],
  segments: [],
  deletedPositionIds: [],
  deletedMoveIds: [],
  ...extra
})

/** A promise the test settles by hand (a request in flight). */
function deferred() {
  let resolve
  let reject
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

async function loaded(moves = [], positions = [], color = 'white') {
  repertoireApi.graph.mockResolvedValue({
    id: 'r1',
    name: 'Blancs',
    color,
    version: 3,
    rootPositionId: 'root',
    positions: [position('root', START, 0), ...positions],
    moves,
    segments: []
  })
  const store = useRepertoireStore()
  await store.load('r1')
  return store
}

describe('repertoire store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('loads a graph keyed by id', async () => {
    const store = await loaded(
      [move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference')],
      [position('p1', AFTER_E4, 1)]
    )

    expect(store.graph.rootId).toBe('root')
    expect(store.graph.moves.m1.san).toBe('e4')
    expect(store.index.out.get('root').map(m => m.id)).toEqual(['m1'])
    expect(store.saving).toBe(false)
  })

  it('shows a new move at once and swaps in the server ids when saved', async () => {
    const store = await loaded()
    const first = deferred()
    repertoireApi.addMove.mockReturnValueOnce(first.promise)

    const added = store.addMove('root', 'e2e4')

    expect(added.created).toBe(true)
    expect(isTemporaryId(added.moveId)).toBe(true)
    const temp = store.graph.moves[added.moveId]
    expect(temp).toMatchObject({
      san: 'e4',
      role: 'reference',
      canonical: true
    })
    expect(store.graph.positions[temp.to].fen).toBe(AFTER_E4)
    expect(store.saving).toBe(true)

    // Played on before the server answers: sent from the real position once it is known.
    const second = deferred()
    repertoireApi.addMove.mockReturnValueOnce(second.promise)
    const reply = store.addMove(temp.to, 'e7e5')
    expect(store.graph.moves[reply.moveId].role).toBe('reply')
    await flushPromises()
    expect(repertoireApi.addMove).toHaveBeenCalledTimes(1)

    first.resolve(
      change(4, {
        moveId: 'm1',
        positions: [position('p1', AFTER_E4, 1)],
        moves: [move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference')]
      })
    )
    await flushPromises()

    expect(store.resolve(added.moveId)).toBe('m1')
    expect(store.graph.moves[added.moveId]).toBeUndefined()
    expect(Object.keys(store.graph.positions).sort()).toEqual([
      'p1',
      'root',
      expect.stringMatching(/^tmp-/)
    ])
    expect(repertoireApi.addMove).toHaveBeenLastCalledWith('r1', {
      fromPositionId: 'p1',
      uci: 'e7e5',
      baseVersion: 4
    })

    second.resolve(
      change(5, {
        moveId: 'm2',
        positions: [position('p2', AFTER_E4_E5, 2)],
        moves: [move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply')]
      })
    )
    await flushPromises()

    expect(store.resolve(reply.moveId)).toBe('m2')
    expect(Object.keys(store.graph.moves).sort()).toEqual(['m1', 'm2'])
    expect(Object.keys(store.graph.positions).sort()).toEqual([
      'p1',
      'p2',
      'root'
    ])
    expect(store.graph.version).toBe(5)
    expect(store.saving).toBe(false)
  })

  it('replays an existing move without asking the server', async () => {
    const store = await loaded(
      [move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference')],
      [position('p1', AFTER_E4, 1)]
    )

    expect(store.addMove('root', 'e2e4')).toEqual({
      moveId: 'm1',
      created: false,
      transposition: false
    })
    expect(repertoireApi.addMove).not.toHaveBeenCalled()
  })

  it('refuses a second prepared move and tells at once about a transposition', async () => {
    const store = await loaded(
      [
        move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
        move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply')
      ],
      [position('p1', AFTER_E4, 1), position('p2', AFTER_E4_E5, 2)]
    )
    repertoireApi.addMove.mockReturnValue(new Promise(() => {}))

    let refused = null
    try {
      store.addMove('root', 'd2d4')
    } catch (e) {
      refused = e
    }
    expect(refused).toBeInstanceOf(PositionOccupiedError)
    expect(refused.prepared.id).toBe('m1')
    expect(Object.keys(store.graph.moves)).toEqual(['m1', 'm2'])
    expect(repertoireApi.addMove).not.toHaveBeenCalled()
    // An opponent's move is a reply: any number.
    expect(store.addMove('p1', 'c7c5').created).toBe(true)

    // 1…e5 answered again after 1.e4 from a line that reached it otherwise: here, a second
    // reply leading to an existing position.
    const other = await loaded(
      [
        move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
        move('m3', 'p1', 'p3', 'g8f6', 'Nf6', 'reply', { sortOrder: 1 }),
        move('m4', 'p3', 'p4', 'b1c3', 'Nc3', 'reference'),
        move('m5', 'p4', 'p5', 'e7e5', 'e5', 'reply'),
        move('m6', 'p1', 'p6', 'e7e5', 'e5', 'reply'),
        move('m7', 'p6', 'p7', 'b1c3', 'Nc3', 'reference', { canonical: true })
      ],
      [
        position('p1', AFTER_E4, 1),
        position(
          'p3',
          'rnbqkb1r/pppppppp/5n2/8/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -',
          2
        ),
        position(
          'p4',
          'rnbqkb1r/pppppppp/5n2/8/4P3/2N5/PPPP1PPP/R1BQKBNR b KQkq -',
          3
        ),
        position(
          'p5',
          'rnbqkb1r/pppp1ppp/5n2/4p3/4P3/2N5/PPPP1PPP/R1BQKBNR w KQkq -',
          4
        ),
        position('p6', AFTER_E4_E5, 2),
        position(
          'p7',
          'rnbqkbnr/pppp1ppp/8/4p3/4P3/2N5/PPPP1PPP/R1BQKBNR b KQkq -',
          3
        )
      ]
    )
    const joined = other.addMove('p7', 'g8f6')
    expect(joined.transposition).toBe(true)
    expect(other.graph.moves[joined.moveId]).toMatchObject({
      to: 'p5',
      canonical: false
    })
  })

  it('refuses a move back to a position the line comes from', async () => {
    const store = await loaded(
      [
        move('m1', 'root', 'p1', 'g1f3', 'Nf3', 'reference'),
        move('m2', 'p1', 'p2', 'g8f6', 'Nf6', 'reply'),
        move('m3', 'p2', 'p3', 'f3g1', 'Ng1', 'reference')
      ],
      [
        position(
          'p1',
          'rnbqkbnr/pppppppp/8/8/8/5N2/PPPPPPPP/RNBQKB1R b KQkq -',
          1
        ),
        position(
          'p2',
          'rnbqkb1r/pppppppp/5n2/8/8/5N2/PPPPPPPP/RNBQKB1R w KQkq -',
          2
        ),
        position(
          'p3',
          'rnbqkb1r/pppppppp/5n2/8/8/8/PPPPPPPP/RNBQKBNR b KQkq -',
          3
        )
      ]
    )

    expect(() => store.addMove('p3', 'f6g8')).toThrow(RepeatedPositionError)
    expect(repertoireApi.addMove).not.toHaveBeenCalled()
    expect(() => store.addMove('root', 'e2e5')).toThrow()
  })

  it('drops the queued changes and reloads the graph when one is refused', async () => {
    const store = await loaded()
    repertoireApi.addMove.mockRejectedValueOnce({ response: { status: 409 } })

    const added = store.addMove('root', 'e2e4')
    store.annotate(added.moveId, { comment: 'Best by test', nags: [1] })
    repertoireApi.graph.mockResolvedValue({
      id: 'r1',
      name: 'Blancs',
      color: 'white',
      version: 9,
      rootPositionId: 'root',
      positions: [position('root', START, 0)],
      moves: [],
      segments: []
    })
    await flushPromises()

    expect(store.failure).toEqual({ status: 409, operation: 'add' })
    expect(repertoireApi.annotate).not.toHaveBeenCalled()
    expect(store.graph.version).toBe(9)
    expect(store.graph.moves).toEqual({})
    expect(store.saving).toBe(false)
  })

  it('deletes a line locally, then on the server', async () => {
    const store = await loaded(
      [
        move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
        move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply')
      ],
      [position('p1', AFTER_E4, 1), position('p2', AFTER_E4_E5, 2)]
    )
    repertoireApi.moveAction.mockResolvedValue(
      change(4, {
        operation: 'delete',
        deletedMoveIds: ['m1', 'm2'],
        deletedPositionIds: ['p1', 'p2']
      })
    )

    expect(store.removalOf('m1').moveIds.sort()).toEqual(['m1', 'm2'])
    store.deleteMove('m1')
    expect(store.graph.moves).toEqual({})
    expect(Object.keys(store.graph.positions)).toEqual(['root'])
    await flushPromises()

    expect(repertoireApi.moveAction).toHaveBeenCalledWith(
      'r1',
      'm1',
      'delete',
      {
        baseVersion: 3
      }
    )
    expect(store.graph.version).toBe(4)
  })

  it('promotes and annotates optimistically', async () => {
    const store = await loaded(
      [
        move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
        move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply'),
        move('m3', 'p1', 'p3', 'c7c5', 'c5', 'reply', { sortOrder: 1 })
      ],
      [
        position('p1', AFTER_E4, 1),
        position('p2', AFTER_E4_E5, 2),
        position(
          'p3',
          'rnbqkbnr/pp1ppppp/8/2p5/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -',
          2
        )
      ]
    )
    repertoireApi.moveAction.mockResolvedValue(change(4))
    repertoireApi.annotate.mockResolvedValue(change(5))

    store.promote('m3')
    expect(store.index.out.get('p1').map(m => m.id)).toEqual(['m3', 'm2'])

    store.annotate('m3', { comment: '   ', nags: [3] })
    expect(store.graph.moves.m3.comment).toBeNull()
    await flushPromises()

    expect(repertoireApi.moveAction.mock.calls.map(c => c[2])).toEqual([
      'promote'
    ])
    expect(repertoireApi.annotate).toHaveBeenCalledWith('r1', 'm3', {
      comment: null,
      nags: [3],
      baseVersion: 4
    })
  })

  it('replaces a prepared move once the server answers', async () => {
    const afterD4 = 'rnbqkbnr/pppppppp/8/8/3P4/8/PPP1PPPP/RNBQKBNR b KQkq -'
    const store = await loaded(
      [
        move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
        move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply')
      ],
      [position('p1', AFTER_E4, 1), position('p2', AFTER_E4_E5, 2)]
    )
    repertoireApi.replaceMove.mockResolvedValue(
      change(4, {
        operation: 'replace',
        moveId: 'm9',
        trashId: 't1',
        positions: [position('p9', afterD4, 1)],
        moves: [move('m9', 'root', 'p9', 'd2d4', 'd4', 'reference')],
        deletedMoveIds: ['m1', 'm2'],
        deletedPositionIds: ['p1', 'p2']
      })
    )

    const replaced = store.replaceMove('m1', 'd2d4')
    expect(store.graph.moves.m1).toBeDefined()
    expect(await replaced).toBe('m9')

    expect(repertoireApi.replaceMove).toHaveBeenCalledWith('r1', 'm1', {
      uci: 'd2d4',
      baseVersion: 3
    })
    expect(Object.keys(store.graph.moves)).toEqual(['m9'])
    expect(store.graph.version).toBe(4)
  })

  it('restores from the trash as a change, and discards for good', async () => {
    const store = await loaded(
      [move('m9', 'root', 'p9', 'd2d4', 'd4', 'reference')],
      [
        position(
          'p9',
          'rnbqkbnr/pppppppp/8/8/3P4/8/PPP1PPPP/RNBQKBNR b KQkq -',
          1
        )
      ]
    )
    repertoireApi.trash.mockResolvedValue([{ id: 't1', san: 'e4' }])
    repertoireApi.restore.mockResolvedValue(
      change(4, {
        operation: 'restore',
        positions: [position('p1', AFTER_E4, 1)],
        moves: [move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference')],
        deletedMoveIds: ['m9'],
        deletedPositionIds: ['p9']
      })
    )
    repertoireApi.discard.mockResolvedValue(undefined)

    expect(await store.fetchTrash()).toEqual([{ id: 't1', san: 'e4' }])
    const restored = await store.restore('t1', { [START]: 'restored' })

    expect(restored.operation).toBe('restore')
    expect(repertoireApi.restore).toHaveBeenCalledWith('r1', 't1', {
      choices: { [START]: 'restored' },
      baseVersion: 3
    })
    expect(Object.keys(store.graph.moves)).toEqual(['m1'])

    await store.discard('t2')
    expect(repertoireApi.discard).toHaveBeenCalledWith('r1', 't2')
  })

  it('undoes through the server and merges the answer', async () => {
    const store = await loaded(
      [move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference')],
      [position('p1', AFTER_E4, 1)]
    )
    repertoireApi.undo.mockResolvedValue(
      change(4, {
        operation: 'undo',
        deletedMoveIds: ['m1'],
        deletedPositionIds: ['p1']
      })
    )

    await store.undo()

    expect(repertoireApi.undo).toHaveBeenCalledWith('r1', { baseVersion: 3 })
    expect(store.graph.moves).toEqual({})
  })
})

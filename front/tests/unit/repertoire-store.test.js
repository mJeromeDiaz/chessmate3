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
    saveLine: vi.fn(),
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
const { useRepertoireStore } = await import('@/stores/repertoire')

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

  it('drops the queued changes and reloads the graph when one is refused', async () => {
    const store = await loaded(
      [move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference')],
      [position('p1', AFTER_E4, 1)]
    )
    repertoireApi.saveLine.mockRejectedValueOnce({ response: { status: 409 } })

    store.saveLine('p1', ['e7e5'], false)
    store.annotate('m1', { comment: 'Best by test', nags: [1] })
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

    expect(store.failure).toEqual({ status: 409, operation: 'line' })
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

  it('saves a drafted line in one change once the server answers', async () => {
    const store = await loaded()
    repertoireApi.saveLine.mockResolvedValue(
      change(4, {
        operation: 'import',
        positions: [
          position('p1', AFTER_E4, 1),
          position('p2', AFTER_E4_E5, 2)
        ],
        moves: [
          move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
          move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply')
        ]
      })
    )

    const saved = store.saveLine('root', ['e2e4', 'e7e5'], false)
    expect(store.graph.moves).toEqual({})
    expect(store.saving).toBe(true)
    expect((await saved).operation).toBe('import')

    expect(repertoireApi.saveLine).toHaveBeenCalledWith('r1', {
      fromPositionId: 'root',
      ucis: ['e2e4', 'e7e5'],
      replace: false,
      baseVersion: 3
    })
    expect(Object.keys(store.graph.moves).sort()).toEqual(['m1', 'm2'])
    expect(store.graph.version).toBe(4)
  })

  it('reloads the graph when a line is refused', async () => {
    const store = await loaded()
    repertoireApi.saveLine.mockRejectedValueOnce({ response: { status: 422 } })

    expect(await store.saveLine('root', ['e2e4'], false)).toBeNull()
    expect(store.failure).toEqual({ status: 422, operation: 'line' })
    expect(repertoireApi.graph).toHaveBeenCalledTimes(2)
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

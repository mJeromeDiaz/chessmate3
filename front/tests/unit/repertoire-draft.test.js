import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

vi.mock('@/services/api', () => ({
  repertoireApi: { graph: vi.fn(), saveLine: vi.fn(), undo: vi.fn() }
}))

const { repertoireApi } = await import('@/services/api')
const { useRepertoireStore, RepeatedPositionError } =
  await import('@/stores/repertoire')
const { useRepertoireEditor } =
  await import('@/composables/repertoire/useRepertoireEditor')
const { indexGraph } = await import('@/components/chess/moveTree')
const { draftConflict, draftLabels, loadDraft, storeDraft, walkLine } =
  await import('@/utils/repertoireDraft')

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -'
const AFTER_E4 = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -'
const AFTER_E4_E5 = 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -'
const AFTER_NF3 = 'rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq -'

const position = (id, fen, depth) => ({
  id,
  fen,
  turn: fen.split(' ')[1],
  depth,
  opening: null
})
const move = (id, from, to, uci, san, role) => ({
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
  segmentId: null
})

/** 1.e4 e5 2.Nf3, white. */
const GRAPH = {
  id: 'r1',
  name: 'Blancs',
  color: 'white',
  version: 3,
  rootPositionId: 'root',
  positions: [
    position('root', START, 0),
    position('p1', AFTER_E4, 1),
    position('p2', AFTER_E4_E5, 2),
    position('p3', AFTER_NF3, 3)
  ],
  moves: [
    move('m1', 'root', 'p1', 'e2e4', 'e4', 'reference'),
    move('m2', 'p1', 'p2', 'e7e5', 'e5', 'reply'),
    move('m3', 'p2', 'p3', 'g1f3', 'Nf3', 'reference')
  ],
  segments: []
}

async function editorOn() {
  repertoireApi.graph.mockResolvedValue(GRAPH)
  const store = useRepertoireStore()
  const editor = useRepertoireEditor(store)
  await store.load('r1')
  await nextTick()
  return { store, editor }
}

describe('repertoire draft', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
    localStorage.clear()
  })

  it('follows the repertoire, then drafts any other move without saving it', async () => {
    const { editor } = await editorOn()

    expect(editor.play('e2e4')).toEqual({ moveId: 'm1', drafted: false })
    editor.play('e7e5')
    expect(editor.play('f1c4')).toEqual({ drafted: true })
    editor.play('g8f6')

    expect(editor.path.value).toEqual(['m1', 'm2'])
    expect(editor.draft.value.from).toBe('p2')
    expect(editor.draft.value.moves.map(m => m.san)).toEqual(['Bc4', 'Nf6'])
    expect(editor.boardPosition.value.depth).toBe(4)
    expect(repertoireApi.saveLine).not.toHaveBeenCalled()
    expect(loadDraft('r1')?.moves.map(m => m.uci)).toEqual(['f1c4', 'g8f6'])
  })

  it('steps through the draft; another move after going back replaces the rest', async () => {
    const { editor } = await editorOn()
    editor.path.value = ['m1', 'm2']
    editor.play('f1c4')
    editor.play('g8f6')
    editor.play('d2d3')

    editor.back()
    editor.back()
    expect(editor.draft.value.cursor).toBe(1)
    expect(editor.shown.value.map(m => m.san)).toEqual(['Bc4'])
    editor.play('g8f6')
    expect(editor.draft.value.moves).toHaveLength(3)
    expect(editor.draft.value.cursor).toBe(2)

    editor.play('b1c3')
    expect(editor.draft.value.moves.map(m => m.san)).toEqual([
      'Bc4',
      'Nf6',
      'Nc3'
    ])

    expect(editor.stepDraft('start')).toBe(true)
    expect(editor.fen.value.startsWith(AFTER_E4_E5.split(' ')[0])).toBe(true)
    editor.stepDraft('end')
    expect(editor.draft.value.cursor).toBe(3)
  })

  it('refuses a move back to a position of the line', async () => {
    const { editor } = await editorOn()
    editor.play('g1f3')
    editor.play('g8f6')
    editor.play('f3g1')

    expect(() => editor.play('f6g8')).toThrow(RepeatedPositionError)
  })

  it('drops the draft when the path moves elsewhere', async () => {
    const { editor } = await editorOn()
    editor.path.value = ['m1', 'm2']
    editor.play('f1c4')

    editor.path.value = ['m1']
    await nextTick()
    expect(editor.drafting.value).toBe(false)
    expect(loadDraft('r1')).toBeNull()
  })

  it('brings back the draft kept in the browser, or drops it when its start is gone', async () => {
    storeDraft('r1', {
      from: 'p2',
      moves: [{ uci: 'f1c4', san: 'Bc4', fen: `${AFTER_E4_E5} 0 2` }],
      cursor: 1
    })
    const { editor } = await editorOn()
    expect(editor.path.value).toEqual(['m1', 'm2'])
    expect(editor.draft.value.moves.map(m => m.san)).toEqual(['Bc4'])

    setActivePinia(createPinia())
    storeDraft('r1', {
      from: 'gone',
      moves: [{ uci: 'f1c4', san: 'Bc4', fen: 'x' }],
      cursor: 1
    })
    const other = await editorOn()
    expect(other.editor.drafting.value).toBe(false)
    expect(other.editor.draftLost.value).toBe(1)
    expect(loadDraft('r1')).toBeNull()
  })

  it('finds the prepared move a line would replace, and walks the saved line', () => {
    const graph = {
      color: 'white',
      rootId: 'root',
      positions: Object.fromEntries(GRAPH.positions.map(p => [p.id, p])),
      moves: Object.fromEntries(GRAPH.moves.map(m => [m.id, m]))
    }
    const index = indexGraph(graph)
    const draft = uci => ({ uci, san: uci, fen: '' })

    expect(
      draftConflict(graph, index, 'root', [
        draft('e2e4'),
        draft('e7e5'),
        draft('g1f3')
      ])
    ).toBeNull()
    const conflict = draftConflict(graph, index, 'root', [
      draft('e2e4'),
      draft('e7e5'),
      draft('f1c4')
    ])
    expect([conflict?.prepared.id, conflict?.positionId]).toEqual(['m3', 'p2'])
    expect(
      draftConflict(graph, index, 'p1', [draft('c7c5'), draft('g1f3')])
    ).toBeNull()

    expect(walkLine(index, 'root', ['e2e4', 'e7e5', 'f1c4'])).toEqual([
      'm1',
      'm2'
    ])
  })

  it('numbers the draft from its start', () => {
    expect(draftLabels(2, [{ san: 'Nf3' }, { san: 'Nc6' }])).toEqual([
      '2.Nf3',
      'Nc6'
    ])
    expect(draftLabels(3, [{ san: 'Nc6' }, { san: 'Bb5' }])).toEqual([
      '2…Nc6',
      '3.Bb5'
    ])
  })
})

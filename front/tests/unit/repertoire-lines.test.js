import { describe, expect, it } from 'vitest'
import { indexGraph } from '@/components/chess/moveTree'
import { filterLines, repertoireLines } from '@/utils/repertoireLines'

const position = (id, turn, depth, opening = null) => ({
  id,
  fen: id,
  turn,
  depth,
  opening
})
const move = (id, from, to, san, role, extra = {}) => ({
  id,
  from,
  to,
  uci: id,
  san,
  role,
  sortOrder: 0,
  comment: null,
  nags: [],
  canonical: true,
  segmentId: null,
  ...extra
})

/**
 * 1.e4 e5 2.Nf3 Nc6, 1…c5 2.Nf3 joining 1…e5 2.Nf3 (a transposition, for the test), and 1.e4 e5
 * 2.Nf3 Nf6 3.Nxe5.
 */
function graph() {
  const g = {
    id: 'r1',
    color: 'white',
    rootId: 'root',
    positions: Object.fromEntries(
      [
        position('root', 'w', 0),
        position('p1', 'b', 1),
        position('p2', 'w', 2, { eco: 'C20', name: 'King’s Pawn Game' }),
        position('p3', 'b', 3),
        position('p4', 'w', 4),
        position('p5', 'w', 2, { eco: 'B20', name: 'Défense sicilienne' }),
        position('p6', 'w', 4, { eco: 'C42', name: 'Défense russe' }),
        position('p7', 'b', 5)
      ].map(p => [p.id, p])
    ),
    moves: Object.fromEntries(
      [
        move('m1', 'root', 'p1', 'e4', 'reference'),
        move('m2', 'p1', 'p2', 'e5', 'reply'),
        move('m5', 'p1', 'p5', 'c5', 'reply', { sortOrder: 1 }),
        move('m3', 'p2', 'p3', 'Nf3', 'reference'),
        move('m4', 'p3', 'p4', 'Nc6', 'reply'),
        move('m7', 'p3', 'p6', 'Nf6', 'reply', { sortOrder: 1 }),
        move('m8', 'p6', 'p7', 'Nxe5', 'reference'),
        move('m6', 'p5', 'p2', 'Nf3', 'reference', { canonical: false })
      ].map(m => [m.id, m])
    )
  }
  return { graph: g, index: indexGraph(g) }
}

describe('repertoire lines', () => {
  it('lists one line per line end, main line first, with what deleting it removes', () => {
    const { graph: g, index } = graph()
    const lines = repertoireLines(index, g)

    expect(lines.map(l => l.text)).toEqual([
      '1.e4 e5 2.Nf3 Nc6',
      '1.e4 e5 2.Nf3 Nf6 3.Nxe5',
      '1.e4 c5 2.Nf3'
    ])
    const [nc6, nf6, c5] = lines
    expect(nc6).toMatchObject({
      key: 'm4',
      path: ['m1', 'm2', 'm3', 'm4'],
      userMoves: 2,
      unanswered: true,
      transposition: false,
      cutMoveId: 'm4',
      cutIndex: 3
    })
    expect(nc6.opening?.eco).toBe('C20')
    expect(nf6.opening?.name).toBe('Défense russe')
    expect(nf6).toMatchObject({
      userMoves: 3,
      unanswered: false,
      cutMoveId: 'm7'
    })
    expect(c5).toMatchObject({
      path: ['m1', 'm5', 'm6'],
      transposition: true,
      unanswered: false,
      cutMoveId: 'm5'
    })
  })

  it('cuts a single line at its first move', () => {
    const { graph: g } = graph()
    for (const id of ['m5', 'm6', 'm7', 'm8']) delete g.moves[id]
    const [only] = repertoireLines(indexGraph(g), g)

    expect([only.text, only.cutMoveId, only.cutIndex]).toEqual([
      '1.e4 e5 2.Nf3 Nc6',
      'm1',
      0
    ])
  })

  it('has no line in an empty repertoire', () => {
    const g = {
      rootId: 'root',
      positions: { root: position('root', 'w', 0) },
      moves: {}
    }
    expect(repertoireLines(indexGraph(g), g)).toEqual([])
  })

  it('filters by moves and opening name, case and accents ignored', () => {
    const { graph: g, index } = graph()
    const lines = repertoireLines(index, g)
    const texts = query => filterLines(lines, query).map(l => l.key)

    expect(texts('')).toHaveLength(3)
    expect(texts('nxe5')).toEqual(['m8'])
    expect(texts('defense RUSSE')).toEqual(['m8'])
    expect(texts('C20 nc6')).toEqual(['m4'])
  })
})

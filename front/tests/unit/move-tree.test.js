import { describe, expect, it } from 'vitest'
import {
  back,
  canonicalPath,
  displayLines,
  forward,
  indexGraph,
  lineEnd,
  moveNumber,
  pathToMove,
  positionAt,
  reaches,
  removal,
  sibling,
  validPrefix
} from '@/components/chess/moveTree'

/**
 * 1.e4 e5 (1…c5 2.Nf3) 2.Nf3 (2.Nc3) Nc6, plus 2.Nc3 Nc6 3.Nf3 transposing to... a position
 * reached canonically by nothing else: t3 joins p5 (after 2.Nf3 Nc6 3.Nc3 in real chess; the FENs
 * are placeholders here, only ids matter).
 */
function sample() {
  const position = (id, depth) => ({
    id,
    fen: id,
    turn: depth % 2 === 0 ? 'w' : 'b',
    depth
  })
  const positions = Object.fromEntries(
    [
      ['root', 0],
      ['p1', 1],
      ['p2', 2],
      ['p3', 3],
      ['p4', 4],
      ['c1', 2],
      ['c2', 3],
      ['n3', 3],
      ['n4', 4],
      ['p5', 5]
    ].map(([id, depth]) => [id, position(id, depth)])
  )
  const move = (id, from, to, san, sortOrder, canonical = true) => ({
    id,
    from,
    to,
    uci: san,
    san,
    sortOrder,
    canonical
  })
  const moves = Object.fromEntries(
    [
      move('m1', 'root', 'p1', 'e4', 0),
      move('m2', 'p1', 'p2', 'e5', 0),
      move('mc5', 'p1', 'c1', 'c5', 1),
      move('mcf3', 'c1', 'c2', 'Nf3', 0),
      move('m3', 'p2', 'p3', 'Nf3', 0),
      move('mn3', 'p2', 'n3', 'Nc3', 1),
      move('m4', 'p3', 'p4', 'Nc6', 0),
      move('mn4', 'n3', 'n4', 'Nc6', 0),
      move('m5', 'p4', 'p5', 'Nc3', 0),
      move('t5', 'n4', 'p5', 'Nf3', 0, false)
    ].map(m => [m.id, m])
  )
  const graph = { rootId: 'root', positions, moves }
  return { graph, index: indexGraph(graph) }
}

describe('move tree navigation', () => {
  it('goes forward along the main line, back, and to the end of the line', () => {
    const { graph, index } = sample()

    expect(forward(index, graph, [])).toEqual(['m1'])
    expect(forward(index, graph, ['m1'])).toEqual(['m1', 'm2'])
    expect(back(['m1', 'm2'])).toEqual(['m1'])
    expect(back([])).toEqual([])
    expect(lineEnd(index, graph, ['m1'])).toEqual([
      'm1',
      'm2',
      'm3',
      'm4',
      'm5'
    ])
    expect(forward(index, graph, ['m1', 'm2', 'm3', 'm4', 'm5'])).toEqual([
      'm1',
      'm2',
      'm3',
      'm4',
      'm5'
    ])
    expect(positionAt(graph, ['m1', 'm2'])).toBe('p2')
    expect(positionAt(graph, [])).toBe('root')
  })

  it('switches between variations of the current move', () => {
    const { graph, index } = sample()

    expect(sibling(index, graph, ['m1', 'm2'], 1)).toEqual(['m1', 'mc5'])
    expect(sibling(index, graph, ['m1', 'mc5'], -1)).toEqual(['m1', 'm2'])
    expect(sibling(index, graph, ['m1', 'mc5'], 1)).toEqual(['m1', 'mc5'])
    expect(sibling(index, graph, [], 1)).toEqual([])
  })

  it('selects a move through the canonical path, transpositions included', () => {
    const { graph, index } = sample()

    expect(canonicalPath(index, graph, 'p5')).toEqual([
      'm1',
      'm2',
      'm3',
      'm4',
      'm5'
    ])
    expect(pathToMove(index, graph, 't5')).toEqual([
      'm1',
      'm2',
      'mn3',
      'mn4',
      't5'
    ])
    expect(pathToMove(index, graph, 'nope')).toEqual([])
    // Following a transposition goes on from the position it joins.
    expect(
      forward(index, graph, ['m1', 'm2', 'mn3', 'mn4', 't5'])
    ).toHaveLength(5)
  })

  it('keeps the valid part of a path after a deletion', () => {
    const { graph } = sample()
    delete graph.moves.m3

    expect(validPrefix(graph, ['m1', 'm2', 'm3', 'm4'])).toEqual(['m1', 'm2'])
    expect(validPrefix(graph, ['m2'])).toEqual([])
  })
})

describe('move tree graph checks', () => {
  it('detects that a move would close a cycle', () => {
    const { index } = sample()

    expect(reaches(index, 'p1', 'p4')).toBe(true)
    expect(reaches(index, 'p4', 'p1')).toBe(false)
    expect(reaches(index, 'n3', 'p5')).toBe(true)
  })

  it('removes what is only reachable through the deleted move', () => {
    const { graph, index } = sample()

    const main = removal(index, graph, 'm3')
    expect(main.positionIds.sort()).toEqual(['p3', 'p4'])
    expect(main.moveIds.sort()).toEqual(['m3', 'm4', 'm5'])

    const both = removal(index, graph, 'm2')
    expect(both.positionIds.sort()).toEqual([
      'n3',
      'n4',
      'p2',
      'p3',
      'p4',
      'p5'
    ])
    expect(both.moveIds.sort()).toEqual([
      'm2',
      'm3',
      'm4',
      'm5',
      'mn3',
      'mn4',
      't5'
    ])

    expect(removal(index, graph, 't5')).toEqual({
      moveIds: ['t5'],
      positionIds: []
    })
  })
})

describe('move tree display', () => {
  it('writes move numbers like a PGN', () => {
    expect(moveNumber({ turn: 'w', depth: 0 })).toBe('1.')
    expect(moveNumber({ turn: 'b', depth: 1 })).toBe('1…')
    expect(moveNumber({ turn: 'w', depth: 4 })).toBe('3.')
  })

  it('places variations after the move they replace and stops at transpositions', () => {
    const { graph, index } = sample()

    const text = line =>
      line
        .map(item =>
          item.kind === 'move'
            ? `${item.number ?? ''}${item.move.san}${item.move.canonical ? '' : '⤳'}`
            : `(${item.lines.map(text).join(' ; ')})`
        )
        .join(' ')

    expect(text(displayLines(index, graph))).toBe(
      '1.e4 e5 (1…c5 2.Nf3) 2.Nf3 (2.Nc3 Nc6 3.Nf3⤳) 2…Nc6 3.Nc3'
    )
    const variations = displayLines(index, graph).filter(
      i => i.kind === 'variations'
    )
    expect(variations.map(v => v.depth)).toEqual([1, 1])
  })

  it('shows an empty tree as an empty line', () => {
    const graph = {
      rootId: 'root',
      positions: { root: { id: 'root', fen: '', turn: 'w', depth: 0 } },
      moves: {}
    }

    expect(displayLines(indexGraph(graph), graph)).toEqual([])
  })
})

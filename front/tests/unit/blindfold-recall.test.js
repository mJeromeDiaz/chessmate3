import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { EMPTY_FEN, useRecall } from '@/composables/blindfold/useRecall'
import {
  PALETTE,
  announcedLine,
  boardFen,
  correctionMarks,
  levelState,
  placeOn,
  placementOf,
  recallKind,
  recallLines,
  squaresOf,
  toPlacement
} from '@/utils/blindfoldRecall'
import { missedItems } from '@/utils/runEnd'

/** Level 13 of the API test: 14 pieces, after Black's first move. */
const SHOWN = '5rk1/5ppp/8/2b5/2Bn4/2N5/5PPP/5RK1 w - - 1 2'

describe('position recall helpers', () => {
  it('reads and writes a placement', () => {
    const squares = squaresOf(SHOWN)
    expect(Object.keys(squares)).toHaveLength(14)
    expect(squares).toMatchObject({
      f8: 'r',
      g8: 'k',
      d4: 'n',
      c3: 'N',
      g1: 'K'
    })
    expect(placementOf(squares)).toBe(SHOWN.split(' ')[0])
    expect(placementOf({})).toBe('8/8/8/8/8/8/8/8')
    expect(boardFen({ e1: 'K' })).toBe('8/8/8/8/8/8/8/4K3 w - - 0 1')
    expect(PALETTE).toHaveLength(12)
  })

  it('places, takes back and erases', () => {
    let squares = placeOn({}, 'e4', 'P')
    expect(squares).toEqual({ e4: 'P' })
    // Another piece replaces it; the same piece again takes it back.
    squares = placeOn(squares, 'e4', 'n')
    expect(squares).toEqual({ e4: 'n' })
    expect(placeOn(squares, 'e4', 'n')).toEqual({})
    // The eraser empties a square.
    expect(placeOn({ e4: 'P', d5: 'p' }, 'd5', null)).toEqual({ e4: 'P' })
    expect(toPlacement({ e4: 'P' })).toEqual([{ square: 'e4', piece: 'P' }])
  })

  it('draws the correction on both boards', () => {
    const expected = { e1: 'K', e8: 'k', d4: 'P' }
    const placed = { e1: 'K', e8: 'k', d5: 'P', a1: 'R' }
    const marks = correctionMarks(placed, expected)
    expect(marks.mine).toEqual([
      { square: 'e1', type: 'success' },
      { square: 'e8', type: 'success' },
      { square: 'd5', type: 'error' },
      { square: 'a1', type: 'error' }
    ])
    expect(marks.answer).toEqual([
      { square: 'e1', type: 'success' },
      { square: 'e8', type: 'success' },
      { square: 'd4', type: 'hint' }
    ])
  })

  it('numbers the moves announced from the position shown', () => {
    expect(
      announcedLine(SHOWN, [
        { uci: 'c3d5', san: 'Nd5' },
        { uci: 'd4e2', san: 'Ne2+' },
        { uci: 'g1h1', san: 'Kh1' }
      ])
    ).toBe('2.Nd5 Ne2+ 3.Kh1')
    expect(announcedLine(SHOWN, [])).toBe('')
  })

  it('says where a level stands and what a verdict is', () => {
    const level = (n, exact, unlocked = true) => ({
      level: n,
      stage: 1,
      played: exact,
      exact,
      close: 0,
      missed: 0,
      unlocked
    })
    expect(levelState(level(2, 0, false), 3, 20)).toBe('locked')
    expect(levelState(level(2, 2), 3, 20)).toBe('open')
    expect(levelState(level(2, 3), 3, 20)).toBe('passed')
    expect(levelState(level(20, 5), 3, 20)).toBe('open')
    expect(['exact', 'close', 'missed'].map(recallKind)).toEqual([
      'win',
      'help',
      'miss'
    ])
  })

  it('writes the recap and the review', () => {
    expect(
      recallLines({
        level: 3,
        exact: 4,
        close: 1,
        missed: 1,
        accuracy: 0.88,
        unlockedLevel: 4
      })
    ).toEqual([
      'Niveau 3 · Distinguer les couleurs.',
      'Exactes : 4 · proches : 1 · ratées : 1 · précision : 88 %.',
      'Niveau 4 débloqué !'
    ])
    const [missed] = missedItems(
      [
        {
          index: 2,
          type: 'blindfold_recall',
          status: 'fail',
          durationMs: 12_000,
          data: { level: 5, correct: 4, expected: 7 }
        }
      ],
      key => key
    )
    expect(missed.title).toBe('Niveau 5 · 4/7 pièces')
    expect(missed.replayable).toBe(false)
  })
})

describe('useRecall', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  const DATA = {
    fen: SHOWN,
    level: 13,
    stage: 4,
    mode: 'free',
    visibleSeconds: 3,
    hiddenSeconds: 2,
    announced: []
  }

  it('shows, hides, then lets the position be rebuilt and sent', () => {
    const onSubmit = vi.fn()
    const game = useRecall({ onSubmit })
    game.load(DATA)
    expect([game.phase.value, game.countdown.value, game.fen.value]).toEqual([
      'show',
      3,
      SHOWN
    ])

    // Nothing can be placed while the position is shown.
    game.click('e4')
    expect(game.placedCount.value).toBe(0)

    vi.advanceTimersByTime(3000)
    expect([game.phase.value, game.fen.value]).toEqual(['hidden', EMPTY_FEN])
    vi.advanceTimersByTime(2000)
    expect(game.phase.value).toBe('build')

    game.select('K')
    game.click('g1')
    game.select('n')
    game.click('d4')
    game.click('e5')
    expect(game.placed.value).toEqual({ g1: 'K', d4: 'n', e5: 'n' })
    game.select(null)
    game.click('e5')
    expect(game.fen.value).toBe('8/8/8/8/3n4/8/8/6K1 w - - 0 1')

    game.submit()
    expect(game.phase.value).toBe('sending')
    expect(onSubmit).toHaveBeenCalledWith([
      { square: 'g1', piece: 'K' },
      { square: 'd4', piece: 'n' }
    ])
    // A failed request: the rebuild can be sent again.
    game.unlock()
    expect(game.phase.value).toBe('build')
  })

  it('hides early on "J’ai mémorisé" and clears the board on demand', () => {
    const game = useRecall()
    game.load(DATA)
    game.memorized()
    expect(game.phase.value).toBe('hidden')
    vi.advanceTimersByTime(2000)
    game.select('P')
    game.click('a2')
    game.clear()
    expect(game.placedCount.value).toBe(0)
  })

  it('shows the correction on the position, or on the rebuild', () => {
    const game = useRecall()
    game.load({ ...DATA, visibleSeconds: 0, hiddenSeconds: 0 })
    expect(game.phase.value).toBe('build')
    game.select('K')
    game.click('g1')
    game.select('Q')
    game.click('a1')
    game.submit()
    game.correct({
      status: 'missed',
      expected: 14,
      correct: 1,
      wrong: 1,
      extra: 1,
      missing: 13,
      accuracy: 0.07,
      expectedPlacement: SHOWN.split(' ')[0],
      unlockedLevel: null,
      durationMs: 9000
    })
    expect(game.phase.value).toBe('complete')
    expect(game.fen.value).toBe(`${SHOWN.split(' ')[0]} w - - 0 1`)
    expect(game.highlights.value.filter(h => h.type === 'success')).toEqual([
      { square: 'g1', type: 'success' }
    ])
    expect(game.highlights.value.filter(h => h.type === 'hint')).toHaveLength(
      13
    )

    game.show('mine')
    expect(game.fen.value).toBe('8/8/8/8/8/8/8/Q5K1 w - - 0 1')
    expect(game.highlights.value).toEqual([
      { square: 'g1', type: 'success' },
      { square: 'a1', type: 'error' }
    ])

    // The next position starts afresh.
    game.load(DATA)
    expect([
      game.phase.value,
      game.placedCount.value,
      game.result.value
    ]).toEqual(['show', 0, null])
  })
})

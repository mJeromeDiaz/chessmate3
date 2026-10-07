import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { usePuzzle } from '@/composables/puzzle/usePuzzle'

/** Lichess puzzle K69di (mate in 2), in the CSV format: FEN before the opponent's move. */
const MATE_IN_2 = {
  fen: '8/8/8/6pp/5r1k/5p1r/5K2/4Q3 b - - 0 62',
  moves: ['g5g4', 'e1e7', 'f4f6', 'e7f6'],
  playerColor: 'white'
}
/** Back-rank mate in one with two mating moves: Ra8# (the solution) and Rb8#. */
const MATE_IN_1 = {
  fen: '6k1/5ppp/2n5/8/8/8/8/RR4K1 b - - 0 1',
  moves: ['c6d4', 'a1a8'],
  playerColor: 'white'
}
const PROMOTION = {
  fen: '8/P6k/8/7p/8/8/8/6K1 b - - 0 1',
  moves: ['h5h4', 'a7a8q'],
  playerColor: 'white'
}

const DELAYS = { opponent: 600, reply: 350, solutionStep: 700 }

describe('usePuzzle', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  /** @param {object} [options] */
  async function started(data, options = {}) {
    const onResolve = vi.fn()
    const onComplete = vi.fn()
    const puzzle = usePuzzle({
      onResolve,
      onComplete,
      delays: DELAYS,
      ...options
    })
    const loading = puzzle.load(data)
    expect(puzzle.phase.value).toBe('intro')
    expect(puzzle.fen.value).toBe(data.fen)
    await vi.advanceTimersByTimeAsync(DELAYS.opponent)
    await loading
    return { puzzle, onResolve, onComplete }
  }

  it('plays the opponent move after a delay, then lets the player move', async () => {
    const { puzzle } = await started(MATE_IN_2)

    expect(puzzle.phase.value).toBe('playing')
    expect(puzzle.lastMove.value).toEqual({ from: 'g5', to: 'g4' })
    expect(puzzle.orientation.value).toBe('white')
    expect(puzzle.movableColor.value).toBe('white')
    expect(puzzle.fen.value).toMatch(/^8\/8\/8\/7p\/5rpk/)
  })

  it('ignores moves while the opponent is to play', () => {
    const puzzle = usePuzzle({ delays: DELAYS })
    puzzle.load(MATE_IN_2)

    expect(puzzle.play('e1e7')).toBe('ignored')
    expect(puzzle.movableColor.value).toBeNull()
  })

  it('runs a full solution: player move, automatic reply, final move', async () => {
    const { puzzle, onResolve, onComplete } = await started(MATE_IN_2)

    expect(puzzle.play('e1e7')).toBe('correct')
    expect(puzzle.phase.value).toBe('intro')
    await vi.advanceTimersByTimeAsync(DELAYS.reply)
    expect(puzzle.lastMove.value).toEqual({ from: 'f4', to: 'f6' })
    expect(puzzle.phase.value).toBe('playing')

    expect(puzzle.play('e7f6')).toBe('correct')
    expect(puzzle.phase.value).toBe('complete')
    expect(onComplete).toHaveBeenCalledOnce()
    expect(onResolve).toHaveBeenCalledExactlyOnceWith('solved', {
      moves: ['e1e7', 'e7f6'],
      hintLevel: 0,
      solutionShown: false
    })
  })

  it('reports a wrong move at once, keeps the position and lets the player go on', async () => {
    const { puzzle, onResolve, onComplete } = await started(MATE_IN_2)
    const before = puzzle.fen.value

    expect(puzzle.mistaken.value).toBe(false)
    expect(puzzle.play('e1e2')).toBe('wrong')
    expect(puzzle.fen.value).toBe(before)
    expect(puzzle.failed.value).toBe(true)
    expect(puzzle.mistaken.value).toBe(true)
    expect(puzzle.highlights.value).toContainEqual({
      square: 'e2',
      type: 'error'
    })
    expect(onResolve).toHaveBeenCalledExactlyOnceWith('failed', {
      moves: ['e1e2'],
      hintLevel: 0,
      solutionShown: false
    })

    puzzle.play('e1e7')
    await vi.advanceTimersByTimeAsync(DELAYS.reply)
    puzzle.play('e7f6')

    expect(puzzle.phase.value).toBe('complete')
    expect(puzzle.outcome.value).toBe('failed')
    expect(puzzle.moveLog.value).toEqual(['e1e2', 'e1e7', 'e7f6'])
    expect(onResolve).toHaveBeenCalledOnce()
    expect(onComplete).toHaveBeenCalledOnce()
  })

  it('accepts any checkmating move in a mate in one', async () => {
    const { puzzle, onResolve } = await started(MATE_IN_1)

    expect(puzzle.play('b1b8')).toBe('correct')

    expect(puzzle.phase.value).toBe('complete')
    expect(onResolve).toHaveBeenCalledWith('solved', expect.anything())
  })

  it('checks the promotion piece', async () => {
    const { puzzle } = await started(PROMOTION)

    expect(puzzle.play('a7a8n')).toBe('wrong')
    expect(puzzle.play('a7a8q')).toBe('correct')
    expect(puzzle.phase.value).toBe('complete')
    expect(puzzle.outcome.value).toBe('failed')
  })

  it('gives progressive hints (piece, then arrow), counted as a failure', async () => {
    const { puzzle, onResolve } = await started(MATE_IN_2)

    puzzle.hint()
    expect([puzzle.failed.value, puzzle.mistaken.value]).toEqual([true, false])
    expect(puzzle.highlights.value).toContainEqual({
      square: 'e1',
      type: 'hint'
    })
    expect(puzzle.arrows.value).toEqual([])
    expect(onResolve).toHaveBeenCalledExactlyOnceWith(
      'failed',
      expect.objectContaining({ hintLevel: 1 })
    )

    puzzle.hint()
    expect(puzzle.arrows.value).toEqual([
      { from: 'e1', to: 'e7', type: 'hint' }
    ])
    puzzle.hint()
    expect(puzzle.hintLevel.value).toBe(2)

    // The hint of a move disappears once it is played; the level used is kept.
    puzzle.play('e1e7')
    await vi.advanceTimersByTimeAsync(DELAYS.reply)
    expect(puzzle.arrows.value).toEqual([])
    expect(puzzle.highlights.value.some(h => h.type === 'hint')).toBe(false)
    expect(puzzle.hintLevel.value).toBe(2)
  })

  it('shows the solution move by move', async () => {
    const { puzzle, onResolve, onComplete } = await started(MATE_IN_2)

    const showing = puzzle.showSolution()
    expect(onResolve).toHaveBeenCalledExactlyOnceWith(
      'failed',
      expect.objectContaining({ solutionShown: true })
    )
    expect(puzzle.movableColor.value).toBeNull()

    await vi.advanceTimersByTimeAsync(DELAYS.solutionStep)
    expect(puzzle.lastMove.value).toEqual({ from: 'e1', to: 'e7' })
    await vi.advanceTimersByTimeAsync(DELAYS.solutionStep)
    expect(puzzle.lastMove.value).toEqual({ from: 'f4', to: 'f6' })
    await vi.advanceTimersByTimeAsync(DELAYS.solutionStep)
    await showing

    expect(puzzle.lastMove.value).toEqual({ from: 'e7', to: 'f6' })
    expect(puzzle.phase.value).toBe('complete')
    expect(onComplete).toHaveBeenCalledOnce()
  })

  it('stops pending moves when disposed or when another puzzle is loaded', async () => {
    const puzzle = usePuzzle({ delays: DELAYS })
    puzzle.load(MATE_IN_2)
    puzzle.dispose()
    await vi.advanceTimersByTimeAsync(DELAYS.opponent)

    expect(puzzle.fen.value).toBe(MATE_IN_2.fen)
    expect(puzzle.phase.value).toBe('intro')

    const loading = puzzle.load(MATE_IN_1)
    await vi.advanceTimersByTimeAsync(DELAYS.opponent)
    await loading
    expect(puzzle.lastMove.value).toEqual({ from: 'c6', to: 'd4' })
  })

  it('can play the solution right after a mistake (one try, Woodpecker)', async () => {
    const { puzzle, onResolve, onComplete } = await started(MATE_IN_2, {
      afterMistake: 'showSolution'
    })

    expect(puzzle.play('e1e2')).toBe('wrong')
    expect(onResolve).toHaveBeenCalledExactlyOnceWith('failed', {
      moves: ['e1e2'],
      hintLevel: 0,
      solutionShown: false
    })
    expect(puzzle.movableColor.value).toBeNull()

    await vi.advanceTimersByTimeAsync(DELAYS.solutionStep * 3)
    expect(puzzle.phase.value).toBe('complete')
    expect(puzzle.lastMove.value).toEqual({ from: 'e7', to: 'f6' })
    expect(onComplete).toHaveBeenCalledOnce()
  })
})

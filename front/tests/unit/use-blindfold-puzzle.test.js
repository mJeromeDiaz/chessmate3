import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { Chess } from 'chess.js'
import {
  EMPTY_BOARD,
  useBlindfoldPuzzle
} from '@/composables/blindfold/useBlindfoldPuzzle'

/** Lichess puzzle K69di (mate in 2): FEN before the opponent's move. */
const MATE_IN_2 = {
  fen: '8/8/8/6pp/5r1k/5p1r/5K2/4Q3 b - - 0 62',
  moves: ['g5g4', 'e1e7', 'f4f6', 'e7f6'],
  playerColor: 'white'
}
const PROMOTION = {
  fen: '8/P6k/8/7p/8/8/8/6K1 b - - 0 1',
  moves: ['h5h4', 'a7a8q'],
  playerColor: 'white'
}
const SETTINGS = { visibleSeconds: 5, hiddenSeconds: 3, peeks: 1 }
const DELAYS = { opponent: 600, reply: 600, flash: 1500, solutionStep: 700 }

/**
 * A legal move of the side to move that is neither `expected` nor a mate.
 *
 * @param {string} fen
 * @param {string} expected
 */
function wrongMove(fen, expected) {
  const chess = new Chess(fen)
  const move = chess.moves({ verbose: true }).find(m => {
    if (m.from + m.to === expected) return false
    const probe = new Chess(fen)
    probe.move(m)
    return !probe.isCheckmate()
  })
  return /** @type {import('chess.js').Move} */ (move)
}

describe('useBlindfoldPuzzle', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  /**
   * A puzzle loaded, played up to the player's turn on the empty board.
   *
   * @param {typeof MATE_IN_2} data
   */
  async function playing(data = MATE_IN_2) {
    const onResolve = vi.fn()
    const onComplete = vi.fn()
    const game = useBlindfoldPuzzle({ onResolve, onComplete, delays: DELAYS })
    game.load(data, SETTINGS)
    await vi.advanceTimersByTimeAsync(DELAYS.opponent)
    await vi.advanceTimersByTimeAsync(
      (SETTINGS.visibleSeconds + SETTINGS.hiddenSeconds) * 1000
    )
    expect(game.phase.value).toBe('play')
    return { game, onResolve, onComplete }
  }

  /**
   * @param {ReturnType<typeof useBlindfoldPuzzle>} game
   * @param {string} uci
   */
  function click(game, uci) {
    game.clickSquare(uci.slice(0, 2))
    return game.clickSquare(uci.slice(2, 4))
  }

  it('shows the position after the opponent’s move, hides it, then the board is empty', async () => {
    const game = useBlindfoldPuzzle({ delays: DELAYS })
    game.load(MATE_IN_2, SETTINGS)
    expect(game.phase.value).toBe('intro')

    await vi.advanceTimersByTimeAsync(DELAYS.opponent)
    expect(game.phase.value).toBe('show')
    expect(game.countdown.value).toBe(5)
    expect(game.boardFen.value).toBe(game.fen.value)
    expect(game.boardFen.value).not.toBe(MATE_IN_2.fen)
    expect(game.highlights.value).toEqual([
      { square: 'g5', type: 'lastMove' },
      { square: 'g4', type: 'lastMove' }
    ])
    expect(game.history.value).toEqual(['g4'])

    await vi.advanceTimersByTimeAsync(2000)
    expect(game.countdown.value).toBe(3)
    await vi.advanceTimersByTimeAsync(3000)
    expect(game.phase.value).toBe('hidden')
    expect(game.countdown.value).toBe(3)
    expect(game.boardFen.value).toBe(EMPTY_BOARD)
    expect(click(game, 'e1e7')).toBe('ignored')

    await vi.advanceTimersByTimeAsync(3000)
    expect(game.phase.value).toBe('play')
    expect(game.boardFen.value).toBe(EMPTY_BOARD)
  })

  it('hides the position at once on "J’ai mémorisé"', async () => {
    const game = useBlindfoldPuzzle({ delays: DELAYS })
    game.load(MATE_IN_2, SETTINGS)
    await vi.advanceTimersByTimeAsync(DELAYS.opponent + 1000)
    game.memorized()
    expect(game.phase.value).toBe('hidden')
    await vi.advanceTimersByTimeAsync(3000)
    expect(game.phase.value).toBe('play')
  })

  it('solves from memory: the reply comes as text and a flash, the end is visible', async () => {
    const { game, onResolve, onComplete } = await playing()

    expect(game.clickSquare('e1')).toBe('selected')
    expect(game.highlights.value).toEqual([{ square: 'e1', type: 'hint' }])
    expect(game.clickSquare('e7')).toBe('correct')
    expect(game.phase.value).toBe('reply')
    expect(game.boardFen.value).toBe(EMPTY_BOARD)

    await vi.advanceTimersByTimeAsync(DELAYS.reply)
    expect(game.phase.value).toBe('play')
    expect(game.message.value).toEqual({
      text: 'L’adversaire joue Rf6.',
      tone: 'info'
    })
    expect(game.highlights.value).toEqual([
      { square: 'f4', type: 'lastMove' },
      { square: 'f6', type: 'lastMove' }
    ])
    await vi.advanceTimersByTimeAsync(DELAYS.flash)
    expect(game.highlights.value).toEqual([])

    expect(click(game, 'e7f6')).toBe('correct')
    expect(game.phase.value).toBe('complete')
    expect(game.status.value).toBe('solved')
    expect(game.boardFen.value).toBe(game.fen.value)
    expect(game.history.value).toEqual(['g4', 'Qe7+', 'Rf6', 'Qxf6#'])
    expect(onResolve).toHaveBeenCalledOnce()
    expect(onResolve).toHaveBeenCalledWith('solved', {
      moves: ['e1e7', 'e7f6'],
      hintLevel: 0,
      solutionShown: false
    })
    expect(onComplete).toHaveBeenCalledWith('solved')
  })

  it('refuses an impossible move without counting it, and a second click on the start clears it', async () => {
    const { game } = await playing()

    expect(click(game, 'a1a2')).toBe('impossible')
    expect(game.message.value?.tone).toBe('error')
    expect(game.mistakes.value).toBe(0)
    expect(game.moveLog.value).toEqual([])
    expect(game.selected.value).toBeNull()

    game.clickSquare('e1')
    expect(game.clickSquare('e1')).toBe('cleared')
    expect(game.selected.value).toBeNull()
    expect(game.phase.value).toBe('play')
  })

  it('shows the current position again after a mistake, then the puzzle goes on "with help"', async () => {
    const { game, onResolve } = await playing()
    const before = game.fen.value
    const wrong = wrongMove(before, 'e1e7')

    expect(click(game, wrong.from + wrong.to)).toBe('wrong')
    expect(game.phase.value).toBe('show')
    expect(game.peeking.value).toBe(true)
    expect(game.peeksLeft.value).toBe(0)
    expect(game.fen.value).toBe(before)
    expect(game.boardFen.value).toBe(before)
    expect(onResolve).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(
      (SETTINGS.visibleSeconds + SETTINGS.hiddenSeconds) * 1000
    )
    expect(game.phase.value).toBe('play')
    expect(game.peeking.value).toBe(false)

    click(game, 'e1e7')
    await vi.advanceTimersByTimeAsync(DELAYS.reply)
    click(game, 'e7f6')
    expect(game.status.value).toBe('helped')
    expect(onResolve).toHaveBeenCalledWith('helped', {
      moves: [wrong.from + wrong.to, 'e1e7', 'e7f6'],
      hintLevel: 0,
      solutionShown: false
    })
  })

  it('fails on one mistake more than the peeks, then plays the solution on the visible board', async () => {
    const { game, onResolve, onComplete } = await playing()
    const wrong = wrongMove(game.fen.value, 'e1e7')
    const uci = wrong.from + wrong.to

    click(game, uci)
    await vi.advanceTimersByTimeAsync(
      (SETTINGS.visibleSeconds + SETTINGS.hiddenSeconds) * 1000
    )
    expect(click(game, uci)).toBe('wrong')
    expect(game.status.value).toBe('failed')
    expect(game.phase.value).toBe('solution')
    expect(game.visible.value).toBe(true)
    expect(onResolve).toHaveBeenCalledOnce()
    expect(onResolve).toHaveBeenCalledWith('failed', {
      moves: [uci, uci],
      hintLevel: 0,
      solutionShown: false
    })

    await vi.advanceTimersByTimeAsync(DELAYS.solutionStep * 3)
    expect(game.phase.value).toBe('complete')
    expect(game.history.value).toEqual(['g4', 'Qe7+', 'Rf6', 'Qxf6#'])
    expect(onComplete).toHaveBeenCalledWith('failed')
    expect(onResolve).toHaveBeenCalledOnce()
  })

  it('gives up: the solution is shown and the puzzle fails', async () => {
    const { game, onResolve } = await playing()
    game.giveUp()
    expect(game.solutionShown.value).toBe(true)
    expect(onResolve).toHaveBeenCalledWith('failed', {
      moves: [],
      hintLevel: 0,
      solutionShown: true
    })
    game.giveUp()
    expect(onResolve).toHaveBeenCalledOnce()
  })

  it('asks for the piece of a promotion', async () => {
    const { game, onResolve } = await playing(PROMOTION)
    expect(click(game, 'a7a8')).toBe('promotion')
    expect(game.promotion.value).toEqual({ from: 'a7', to: 'a8' })
    expect(game.clickSquare('a1')).toBe('ignored')
    expect(game.promote('q')).toBe('correct')
    expect(game.status.value).toBe('solved')
    expect(onResolve).toHaveBeenCalledWith('solved', {
      moves: ['a7a8q'],
      hintLevel: 0,
      solutionShown: false
    })
  })

  it('forgets the timers of a puzzle left for the next one', async () => {
    const game = useBlindfoldPuzzle({ delays: DELAYS })
    game.load(MATE_IN_2, SETTINGS)
    await vi.advanceTimersByTimeAsync(DELAYS.opponent)
    game.load(PROMOTION, SETTINGS)
    expect(game.phase.value).toBe('intro')
    await vi.advanceTimersByTimeAsync(DELAYS.opponent)
    expect(game.phase.value).toBe('show')
    expect(game.history.value).toEqual(['h4'])
    await vi.advanceTimersByTimeAsync(8000)
    expect(game.phase.value).toBe('play')
  })
})

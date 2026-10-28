import { describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { useArenaReview } from '@/composables/arena/useArenaReview'
import {
  CLAMP_CP,
  finalScore,
  mistakesOf,
  pgnFileName,
  pgnOf,
  pgnResult,
  positionsOf,
  reviewMoves,
  verdictOf,
  whiteValue
} from '@/utils/arenaReview'

const SCHOLAR = ['e2e4', 'e7e5', 'f1c4', 'b8c6', 'd1h5', 'g8f6', 'h5f7']

/** @returns {import('@/utils/arenaBoss').ArenaGame} */
const gameOf = (/** @type {object} */ over = {}) => ({
  id: 'g1',
  boss: 'zombie',
  color: 'white',
  elo: 700,
  endgameElo: null,
  styles: [],
  guarded: false,
  cadence: '5+3',
  incrementMs: 3000,
  moves: SCHOLAR,
  playerMs: 0,
  bossMs: 0,
  turn: 'boss',
  clocksRunning: false,
  status: 'won',
  end: 'checkmate',
  crystals: 100,
  xp: 30,
  startedAt: '2026-10-27T10:00:00+00:00',
  endedAt: '2026-10-27T10:05:00+00:00',
  ...over
})

describe('arena review', () => {
  it('replays the positions and scores the end of a game without Stockfish', () => {
    const positions = positionsOf(SCHOLAR)
    expect(positions).toHaveLength(8)
    expect(positions[7].san).toBe('Qxf7#')
    expect(finalScore(positions[7].fen)).toEqual({ mate: 0 })
    expect(finalScore(positions[0].fen)).toBeNull()
    expect(finalScore('7k/5Q2/6K1/8/8/8/8/8 b - - 0 1')).toEqual({ cp: 0 })
  })

  it('judges a move by what it lost for its side', () => {
    expect(verdictOf(0, true)).toBe('best')
    expect(verdictOf(30, false)).toBe('good')
    expect(verdictOf(50, false)).toBe('inaccuracy')
    expect(verdictOf(120, true)).toBe('mistake')
    expect(verdictOf(200, false)).toBe('blunder')
    expect(whiteValue({ cp: 40 }, positionsOf([])[0].fen)).toBe(40)
    expect(whiteValue({ cp: 40 }, positionsOf(['e2e4'])[1].fen)).toBe(-40)
    expect(whiteValue({ mate: 3 }, positionsOf([])[0].fen)).toBe(CLAMP_CP)
  })

  it('finds the black blunder that lets the mate in', () => {
    const positions = positionsOf(SCHOLAR)
    /** @type {import('@/utils/arenaReview').PositionEval[]} */
    const evals = [
      { score: { cp: 30 }, bestMove: 'e2e4' },
      { score: { cp: -30 }, bestMove: 'e7e5' },
      { score: { cp: 30 }, bestMove: 'g1f3' },
      { score: { cp: -40 }, bestMove: 'b8c6' },
      { score: { cp: 30 }, bestMove: 'd1h5' },
      // Black to move after Qh5: g6 holds (−20 for black), Nf6 lets the mate in.
      { score: { cp: -20 }, bestMove: 'g7g6' },
      { score: { mate: 1 }, bestMove: 'h5f7' },
      { score: { mate: 0 }, bestMove: null }
    ]
    const moves = reviewMoves(positions, evals)
    expect(moves).toHaveLength(7)
    expect(moves[0]).toMatchObject({
      san: 'e4',
      side: 'white',
      verdict: 'best'
    })
    expect(moves[2]).toMatchObject({ san: 'Bc4', verdict: 'good', loss: 0 })
    expect(moves[5]).toMatchObject({
      san: 'Nf6',
      side: 'black',
      verdict: 'blunder',
      bestMove: 'g7g6'
    })
    expect(moves[6]).toMatchObject({ san: 'Qxf7#', verdict: 'best', loss: 0 })
    expect(mistakesOf(moves, 'black')).toEqual({
      inaccuracy: 0,
      mistake: 0,
      blunder: 1
    })
    expect(mistakesOf(moves, 'white')).toEqual({
      inaccuracy: 0,
      mistake: 0,
      blunder: 0
    })
    expect(reviewMoves(positions, evals.slice(0, 3))).toHaveLength(2)
  })

  it('writes the game in PGN, the boss on its side with its Elo', () => {
    const sans = positionsOf(SCHOLAR)
      .slice(1)
      .map(p => p.san ?? '')
    const pgn = pgnOf({
      game: gameOf(),
      sans,
      player: 'Alice "A"',
      boss: 'Le Zombie',
      site: 'https://example.test',
      termination: 'Échec et mat'
    })
    expect(pgn).toContain('[White "Alice \\"A\\""]')
    expect(pgn).toContain('[Black "Le Zombie"]')
    expect(pgn).toContain('[BlackElo "700"]')
    expect(pgn).toContain('[Date "2026.10.27"]')
    expect(pgn).toContain('[TimeControl "300+3"]')
    expect(pgn).toContain('[Result "1-0"]')
    expect(pgn).toContain('[Site "https://example.test"]')
    expect(
      pgn.trim().endsWith('1. e4 e5 2. Bc4 Nc6 3. Qh5 Nf6 4. Qxf7# 1-0')
    ).toBe(true)
    expect(pgnResult('lost', 'black')).toBe('1-0')
    expect(pgnResult('drawn', 'white')).toBe('1/2-1/2')
    expect(pgnResult('aborted', 'white')).toBe('*')
    expect(pgnFileName(gameOf())).toBe(
      'dontstayrooky-arene-zombie-2026-10-27.pgn'
    )
  })

  it('wraps a long game at 80 characters', () => {
    const sans = Array.from({ length: 60 }, (_, i) => (i % 2 ? 'Nf6' : 'Nf3'))
    const pgn = pgnOf({
      game: gameOf({ status: 'drawn' }),
      sans,
      player: 'A',
      boss: 'B'
    })
    const moves = pgn.split('\n\n')[1].trim().split('\n')
    expect(moves.length).toBeGreaterThan(1)
    expect(moves.every(line => line.length <= 80)).toBe(true)
  })
})

describe('useArenaReview', () => {
  it('has Stockfish look at each position but the mate, one after the other', async () => {
    const analyse = vi.fn(async () => ({
      lines: [{ move: 'e2e4', score: { cp: 20 }, pv: [], depth: 10 }],
      bestMove: 'e2e4'
    }))
    const engine = {
      analyse,
      stop: vi.fn(),
      bestMove: vi.fn(),
      status: { value: 'ready' }
    }
    const scope = effectScope()
    const review = /** @type {ReturnType<typeof useArenaReview>} */ (
      scope.run(() =>
        useArenaReview({ engine: /** @type {any} */ (engine), movetime: 10 })
      )
    )
    await review.start(SCHOLAR)
    expect(review.state.value).toBe('done')
    expect(analyse).toHaveBeenCalledTimes(7)
    expect(review.evals.value).toHaveLength(8)
    expect(review.evals.value[7]).toEqual({
      score: { mate: 0 },
      bestMove: null
    })
    expect(review.progress.value).toBe(1)
    expect(review.moves.value).toHaveLength(7)
    expect(review.curve.value[7]).toBe(CLAMP_CP)
    scope.stop()
  })

  it('says so when Stockfish fails', async () => {
    const engine = {
      analyse: vi.fn(async () => {
        throw new Error('no engine')
      }),
      stop: vi.fn(),
      bestMove: vi.fn(),
      status: { value: 'failed' }
    }
    const scope = effectScope()
    const review = /** @type {ReturnType<typeof useArenaReview>} */ (
      scope.run(() => useArenaReview({ engine: /** @type {any} */ (engine) }))
    )
    await review.start(['e2e4'])
    expect(review.state.value).toBe('failed')
    scope.stop()
  })
})

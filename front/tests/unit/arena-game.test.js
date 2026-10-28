import { describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick } from 'vue'
import {
  GUARD_CP,
  guardCp,
  useArenaGame
} from '@/composables/arena/useArenaGame'
import {
  ENDGAME_MATERIAL,
  MIN_THINK_MS,
  OPENING_PLIES,
  OPENING_THINK_MS,
  eloFor,
  isEndgame,
  reactionTo,
  replay,
  reweigh,
  thinkMs
} from '@/utils/arenaBoss'

const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'

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
  moves: [],
  playerMs: 300_000,
  bossMs: 300_000,
  turn: 'player',
  clocksRunning: false,
  status: 'active',
  end: null,
  crystals: 0,
  xp: 0,
  startedAt: '2026-10-27T10:00:00+00:00',
  endedAt: null,
  ...over
})

/** Waits for the promises queued (the boss's turn runs on its own). */
const flush = async () => {
  for (let i = 0; i < 10; i++) await Promise.resolve()
  await nextTick()
}

/**
 * A game against fakes: the server plays back what it receives, Maia proposes `maiaMove`,
 * Stockfish answers `bestMove` with `lines`.
 */
function setup({
  game = gameOf(),
  maiaMove = 'e7e5',
  maiaFails = false,
  lines = /** @type {any[]} */ ([]),
  bestMove = 'd7d5'
} = {}) {
  let current = game
  const api = {
    current: vi.fn(async () => current),
    start: vi.fn(async () => current),
    game: vi.fn(async () => current),
    act: vi.fn(
      async (/** @type {string} */ _id, /** @type {string} */ action) => {
        current = {
          ...current,
          status: action === 'resign' ? 'lost' : 'aborted'
        }
        return current
      }
    ),
    move: vi.fn(
      async (
        /** @type {string} */ _id,
        /** @type {number} */ ply,
        /** @type {string} */ move
      ) => {
        if (ply !== current.moves.length)
          throw {
            response: { status: 409, data: { detail: 'arena_out_of_sync' } }
          }
        const moves = [...current.moves, move]
        const playerToMove =
          (moves.length % 2 === 0) === (current.color === 'white')
        current = { ...current, moves, turn: playerToMove ? 'player' : 'boss' }
        return current
      }
    )
  }
  const maia = {
    status: { value: 'ready' },
    progress: { value: 1 },
    preload: vi.fn(async () => {}),
    predict: vi.fn(async () => {
      if (maiaFails) throw new Error('no model')
      return { chances: [{ uci: maiaMove, p: 1 }], outlook: {} }
    }),
    chooseMove: vi.fn(),
    stop: vi.fn()
  }
  const engine = {
    status: { value: 'ready' },
    analyse: vi.fn(async () => ({ lines, bestMove })),
    bestMove: vi.fn(async () => bestMove),
    stop: vi.fn()
  }
  const scope = effectScope()
  const arena = /** @type {ReturnType<typeof useArenaGame>} */ (
    scope.run(() =>
      useArenaGame({
        api: /** @type {any} */ (api),
        maia: /** @type {any} */ (maia),
        engine: /** @type {any} */ (engine),
        wait: async () => {},
        random: () => 0.5
      })
    )
  )
  return { arena, api, maia, engine, scope, server: () => current }
}

describe('arena boss', () => {
  it('replays a game and stops at a move that does not follow', () => {
    const game = replay(['e2e4', 'e7e5', 'g1f3'])
    expect(game.sans).toEqual(['e4', 'e5', 'Nf3'])
    expect(game.lastMove).toEqual({ from: 'g1', to: 'f3' })
    expect(replay(['e2e4', 'e2e4']).sans).toEqual(['e4'])
    expect(replay([]).fen).toBe(START)
  })

  it('plays at its endgame Elo once little material is left', () => {
    expect(isEndgame(START)).toBe(false)
    expect(isEndgame('4k3/8/8/8/8/8/4P3/4K2R w K - 0 1')).toBe(true)
    // Two rooks and a bishop each: 2 × 13 = 26, an endgame; with the queens, not.
    expect(
      isEndgame('r1b1k2r/pppp1ppp/8/8/8/8/PPPP1PPP/R1B1K2R w - - 0 1')
    ).toBe(true)
    expect(
      isEndgame('r1bqk2r/pppp1ppp/8/8/8/8/PPPP1PPP/R1BQK2R w - - 0 1')
    ).toBe(false)
    expect(ENDGAME_MATERIAL).toBe(26)
    const bourreau = { elo: 1200, endgameElo: 1700 }
    expect(eloFor(bourreau, START)).toBe(1200)
    expect(eloFor(bourreau, '4k3/8/8/8/8/8/4P3/4K2R w K - 0 1')).toBe(1700)
    expect(
      eloFor(
        { elo: 1000, endgameElo: null },
        '4k3/8/8/8/8/8/4P3/4K2R w K - 0 1'
      )
    ).toBe(1000)
  })

  it('plays a professor\u2019s opening at its opening Elo, then its Elo', () => {
    const aaron = { elo: 2650, endgameElo: null, openingElo: 2850 }
    expect(OPENING_PLIES).toBe(20)
    expect(eloFor(aaron, START, 0)).toBe(2850)
    expect(eloFor(aaron, START, 19)).toBe(2850)
    expect(eloFor(aaron, START, 20)).toBe(2650)
    expect(eloFor(aaron, START)).toBe(2650)
    expect(eloFor({ elo: 1200, endgameElo: null }, START, 0)).toBe(1200)
  })

  it('tightens Stockfish\u2019s guard beyond Maia\u2019s 2600', () => {
    expect(guardCp(1500)).toBe(GUARD_CP)
    expect(guardCp(2600)).toBe(GUARD_CP)
    expect(guardCp(2700)).toBe(100)
    expect(guardCp(2800)).toBe(50)
    expect(guardCp(3200)).toBe(25)
  })

  it('weighs Maia’s moves by its styles, never a move Maia did not see', () => {
    // After 1.e4 d5: exd5 takes a pawn.
    const fen = 'rnbqkbnr/ppp1pppp/8/3p4/4P3/8/PPPP1PPP/RNBQKBNR w KQkq - 0 2'
    const chances = [
      { uci: 'g1f3', p: 0.5 },
      { uci: 'e4d5', p: 0.5 }
    ]
    const captures = reweigh(fen, chances, ['captures'])
    expect(captures[0].uci).toBe('e4d5')
    expect(captures[0].p).toBeCloseTo(1.6 / 2.6)
    expect(captures.reduce((sum, move) => sum + move.p, 0)).toBeCloseTo(1)
    expect(reweigh(fen, chances, [])).toBe(chances)
    expect(reweigh(fen, chances, ['unknown_style'])).toBe(chances)
    const varied = reweigh(
      fen,
      [
        { uci: 'g1f3', p: 0.8 },
        { uci: 'e4d5', p: 0.2 }
      ],
      ['varied']
    )
    expect(varied[1].p).toBeGreaterThan(0.2)
    const mate = reweigh(
      '6k1/5ppp/8/8/8/8/8/R5K1 w - - 0 1',
      [
        { uci: 'a1a2', p: 0.9 },
        { uci: 'a1a8', p: 0.1 }
      ],
      ['mate_finder']
    )
    expect(mate[0].uci).toBe('a1a8')
  })

  it('thinks by its speed, at once for its first move, never long on a short clock', () => {
    const game = { cadence: '5+3', bossMs: 300_000, clocksRunning: true }
    expect(thinkMs(3, { ...game, clocksRunning: false })).toBe(OPENING_THINK_MS)
    expect(thinkMs(5, game, () => 0.5)).toBe(800)
    expect(thinkMs(1, game, () => 0.5)).toBe(4000)
    expect(thinkMs(1, { ...game, cadence: '15+10' }, () => 0.5)).toBe(
      Math.round(4000 * Math.sqrt(3))
    )
    expect(thinkMs(1, { ...game, bossMs: 20_000 }, () => 0.5)).toBe(1000)
    expect(thinkMs(1, { ...game, bossMs: 1000 }, () => 0.5)).toBe(MIN_THINK_MS)
  })

  it('reacts to captures and checks, not to quiet moves', () => {
    expect(reactionTo(['e2e4'], false)).toBeNull()
    expect(reactionTo(['e2e4', 'd7d5', 'e4d5'], false)).toBe(
      'player_takes_pawn'
    )
    expect(reactionTo(['e2e4', 'd7d5', 'e4d5', 'd8d5'], true)).toBe(
      'boss_captures'
    )
    expect(reactionTo(['e2e4', 'f7f6', 'd2d4', 'g7g5', 'd1h5'], false)).toBe(
      'player_checks'
    )
    expect(reactionTo(['f2f3', 'e7e5', 'g2g4', 'd8h4'], true)).toBe(
      'boss_checks'
    )
  })
})

describe('useArenaGame', () => {
  it('sends the player’s move, then the boss answers with Maia’s move', async () => {
    const { arena, api, maia, scope } = setup()
    await arena.begin({ boss: 'zombie', color: 'white', cadence: '5+3' })
    expect(arena.reaction.value.key).toBe('start')
    expect(arena.playerTurn.value).toBe(true)
    expect(await arena.play('e2e4')).toBe(true)
    await flush()
    expect(api.move).toHaveBeenNthCalledWith(1, 'g1', 0, 'e2e4')
    expect(api.move).toHaveBeenNthCalledWith(2, 'g1', 1, 'e7e5')
    expect(maia.predict).toHaveBeenCalledWith(expect.stringContaining(' b '), {
      elo: 700,
      opponentElo: 700
    })
    expect(arena.game.value?.moves).toEqual(['e2e4', 'e7e5'])
    expect(arena.playerTurn.value).toBe(true)
    scope.stop()
  })

  it('resumes a game where the boss has the move: it plays at once', async () => {
    const { arena, api, scope } = setup({
      game: gameOf({ color: 'black', turn: 'boss' }),
      maiaMove: 'd2d4'
    })
    expect(await arena.resume()).toBe(true)
    await flush()
    expect(api.move).toHaveBeenCalledWith('g1', 0, 'd2d4')
    expect(arena.game.value?.turn).toBe('player')
    scope.stop()
  })

  it('has a guarded boss play Stockfish’s best when Maia’s move is not good enough', async () => {
    const { arena, api, scope } = setup({
      game: gameOf({ guarded: true }),
      maiaMove: 'f7f6',
      lines: [
        { move: 'e7e5', score: { cp: 20 }, pv: [], depth: 10 },
        { move: 'd7d5', score: { cp: 10 }, pv: [], depth: 10 }
      ]
    })
    await arena.begin({ boss: 'avocat', color: 'white', cadence: '5+3' })
    await arena.play('e2e4')
    await flush()
    expect(api.move).toHaveBeenLastCalledWith('g1', 1, 'e7e5')
    scope.stop()
  })

  it('keeps a guarded boss’s move among the best', async () => {
    const { arena, api, scope } = setup({
      game: gameOf({ guarded: true }),
      maiaMove: 'd7d5',
      lines: [
        { move: 'e7e5', score: { cp: 20 }, pv: [], depth: 10 },
        { move: 'd7d5', score: { cp: -50 }, pv: [], depth: 10 }
      ]
    })
    await arena.begin({ boss: 'avocat', color: 'white', cadence: '5+3' })
    await arena.play('e2e4')
    await flush()
    expect(api.move).toHaveBeenLastCalledWith('g1', 1, 'd7d5')
    scope.stop()
  })

  it('lets Stockfish play at the boss’s strength when Maia is unavailable', async () => {
    const { arena, api, engine, scope } = setup({
      maiaFails: true,
      bestMove: 'c7c5'
    })
    await arena.begin({ boss: 'zombie', color: 'white', cadence: '5+3' })
    await arena.play('e2e4')
    await flush()
    expect(engine.bestMove).toHaveBeenCalledWith(
      expect.any(String),
      expect.objectContaining({
        options: { UCI_LimitStrength: true, UCI_Elo: 1320 }
      })
    )
    expect(api.move).toHaveBeenLastCalledWith('g1', 1, 'c7c5')
    expect(arena.notice.value).toBe('maiaUnavailable')
    scope.stop()
  })

  it('reads the game again when the server says it is out of sync', async () => {
    const { arena, api, scope, server } = setup()
    await arena.begin({ boss: 'zombie', color: 'white', cadence: '5+3' })
    api.move.mockRejectedValueOnce({
      response: { status: 409, data: { detail: 'arena_out_of_sync' } }
    })
    expect(await arena.play('e2e4')).toBe(false)
    expect(api.game).toHaveBeenCalledWith('g1')
    expect(arena.game.value).toEqual(server())
    scope.stop()
  })

  it('resigns into a lost game, the boss proud', async () => {
    const { arena, api, scope } = setup()
    await arena.begin({ boss: 'zombie', color: 'white', cadence: '5+3' })
    await arena.act('resign')
    expect(api.act).toHaveBeenCalledWith('g1', 'resign')
    expect(arena.game.value?.status).toBe('lost')
    expect(arena.reaction.value.key).toBe('lost')
    expect(arena.active.value).toBe(false)
    scope.stop()
  })
})

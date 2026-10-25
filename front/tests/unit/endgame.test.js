import { describe, expect, it, vi } from 'vitest'
import { effectScope, ref } from 'vue'
import { Chess } from 'chess.js'
import {
  atLeast,
  bareKing,
  counter,
  DEMO_MAX_PLIES,
  demoMove,
  demoOver,
  endgameFeedback,
  engineKeeps,
  goalText,
  keepingMoves,
  lessonText,
  lessonTitle,
  netSquares,
  opponentLabel,
  playUci,
  reached,
  sanAt
} from '@/utils/endgame'
import { itemTitle, missedItems } from '@/utils/runEnd'
import fr from '@/i18n/fr/endgame.json'
import { useEndgameDemo } from '@/composables/endgame/useEndgameDemo'
import { useEndgameGame } from '@/composables/endgame/useEndgameGame'
import { EngineFailureError } from '@/utils/engine/UciEngine'

// The rule of the square (docs/ENDGAMES.md): White queens the a-pawn, the black king too far.
const START = '8/8/8/P7/8/8/5k2/7K w - - 0 1'
const GAME = ['a5a6', 'f2e3', 'a6a7', 'e3d4', 'a7a8q']

/** @returns {import('@/composables/endgame/useEndgameGame').EndgameItem} */
function item(overrides = {}) {
  return {
    fen: START,
    lesson: 'square-promote',
    family: 'pawns',
    goal: 'promote',
    result: 'win',
    textbook: true,
    mode: 'review',
    explain: false,
    budgetMoves: 18,
    holdMoves: null,
    opponent: { elo: 1500, perfect: false },
    index: 1,
    ...overrides
  }
}

/**
 * A tablebase answer, each move `[uci, result for the side playing it]`.
 *
 * @param {[string, string|null][]} moves
 * @param {string[]} [defence]
 */
function answer(moves, defence = []) {
  return {
    known: true,
    result: 'win',
    moves: moves.map(([uci, result]) => ({
      uci,
      result,
      dtm: null,
      dtz: null,
      zeroing: false
    })),
    defence
  }
}

/** The answers by position (placement and side to move); any other position is unknown. */
const SQUARE = {
  '8/8/8/P7/8/8/5k2/7K w': answer([
    ['a5a6', 'win'],
    ['h1h2', 'draw']
  ]),
  '8/8/P7/8/8/4k3/8/7K w': answer([['a6a7', 'win']]),
  '8/P7/8/8/3k4/8/8/7K w': answer([['a7a8q', 'win']]),
  // The opponent's positions, asked at « Parfait » or when Maia cannot play.
  '8/8/P7/8/8/8/5k2/7K b': answer([['f2e3', 'loss']], ['f2e3']),
  '8/P7/8/8/8/4k3/8/7K b': answer([['e3d4', 'loss']], ['e3d4'])
}

/** @param {Record<string, any>} entries */
function fakeTablebase(entries) {
  return vi.fn(async (/** @type {string} */ fen) => {
    const found = entries[fen.split(' ').slice(0, 2).join(' ')]
    if (found instanceof Error || found?.response) throw found
    return found ?? { known: false, moves: [], defence: [] }
  })
}

/** Maia replying with `replies` in turn (or failing). */
function fakeMaia(
  replies = ['f2e3', 'e3d4'],
  { status = 'ready', fail = false } = {}
) {
  const queue = [...replies]
  return {
    status: ref(status),
    progress: ref(1),
    chooseMove: vi.fn(async () => {
      if (fail) throw new EngineFailureError('no model')
      return queue.shift() ?? null
    }),
    preload: vi.fn(),
    predict: vi.fn(),
    stop: vi.fn()
  }
}

/** Stockfish scoring every position reached `score` (from its side to move), best move `best`. */
function fakeEngine(score = { cp: -500 }, best = 'a5a6') {
  return {
    status: ref('ready'),
    analyse: vi.fn(async () => ({
      lines: [{ move: best, score, pv: [best], depth: 12 }],
      bestMove: best
    })),
    bestMove: vi.fn(async () => best),
    stop: vi.fn()
  }
}

const settle = async () => {
  for (let i = 0; i < 10; i++) await new Promise(resolve => setTimeout(resolve))
}

/** A game with fakes, `onFinish` recorded. */
function setup({
  tablebase = fakeTablebase(SQUARE),
  maia = fakeMaia(),
  engine = fakeEngine()
} = {}) {
  const finished = vi.fn()
  const scope = effectScope()
  const game = scope.run(() =>
    useEndgameGame({
      tablebase,
      maia,
      engine,
      delayMs: 0,
      random: () => 0,
      onFinish: finished
    })
  )
  return { game, finished, tablebase, maia, engine, scope }
}

/** Plays the player's moves of `GAME`, each after the opponent's reply. */
async function playAll(game, moves = [GAME[0], GAME[2], GAME[4]]) {
  for (const uci of moves) {
    expect(game.play(uci)).toBe(true)
    await settle()
  }
}

describe('endgame rules', () => {
  it('ranks results: a win keeps a draw to hold, uncertain keeps nothing', () => {
    expect(atLeast('win', 'draw')).toBe(true)
    expect(atLeast('draw', 'draw')).toBe(true)
    expect(atLeast('draw', 'win')).toBe(false)
    expect(atLeast(null, 'draw')).toBe(false)
    expect(keepingMoves(SQUARE['8/8/8/P7/8/8/5k2/7K w'], 'win')).toEqual([
      'a5a6'
    ])
    expect(keepingMoves(SQUARE['8/8/8/P7/8/8/5k2/7K w'], 'draw')).toEqual([
      'a5a6',
      'h1h2'
    ])
  })

  it('lets Stockfish judge with +4 to win and −2 to hold', () => {
    // Scores of the position reached, from the opponent to move there.
    expect(engineKeeps({ cp: -450 }, 'win')).toBe(true)
    expect(engineKeeps({ cp: -300 }, 'win')).toBe(false)
    expect(engineKeeps({ mate: -3 }, 'win')).toBe(true)
    expect(engineKeeps({ mate: 2 }, 'draw')).toBe(false)
    expect(engineKeeps({ cp: 150 }, 'draw')).toBe(true)
    expect(engineKeeps({ cp: 250 }, 'draw')).toBe(false)
  })

  it('sees the goals as the server does', () => {
    const lesson = { goal: 'promote', holdMoves: null }
    const game = new Chess('8/P7/8/8/3k4/8/8/7K w - - 0 1')
    expect(reached(lesson, game, playUci(game, 'a7a8q'), true, 3)).toBe('won')
    expect(bareKing('8/8/8/8/8/8/k7/QK6 b - - 0 1')).toBe(true)
    expect(bareKing('8/8/8/8/8/8/kp6/QK6 b - - 0 1')).toBe(false)

    const hold = { goal: 'hold', holdMoves: 2 }
    const defence = new Chess('8/8/8/P7/3k4/8/8/7K b - - 0 1')
    expect(reached(hold, defence, playUci(defence, 'd4c5'), true, 1)).toBeNull()
    expect(
      reached(hold, defence, playUci(defence, 'h1g2'), false, 1)
    ).toBeNull()
    expect(reached(hold, defence, playUci(defence, 'c5b5'), true, 2)).toBe(
      'held'
    )
    expect(playUci(defence, 'a1a8')).toBeNull()

    expect(counter(item(), 3)).toEqual({ kind: 'budget', done: 3, total: 18 })
    expect(counter(item({ goal: 'hold', holdMoves: 8 }), 2)).toEqual({
      kind: 'hold',
      done: 2,
      total: 8
    })
  })
})

describe('endgame texts', () => {
  it('has a title and Lizy’s explanation for every lesson', () => {
    const keys = Object.keys(fr.lessons)
    // As many as the catalogue (App\Endgame\Lesson\Catalogue).
    expect(keys).toHaveLength(27)
    for (const key of keys) {
      expect(lessonTitle(key)).not.toBe(`endgame.lessons.${key}.title`)
      expect(lessonText(key).length).toBeGreaterThan(80)
    }
  })

  it('says the goal and the opponent', () => {
    expect(goalText(item())).toBe(
      'Promeus ton pion en gardant le gain, en 18 coups au plus.'
    )
    expect(goalText(item({ goal: 'hold', holdMoves: 8 }))).toBe(
      'Tiens la nulle pendant 8 coups.'
    )
    expect(opponentLabel({ elo: 1500, perfect: false })).toBe('Maia 1500')
    expect(opponentLabel({ elo: null, perfect: true })).toBe('Défense parfaite')
  })

  it('names the right move where it was to be played', () => {
    expect(sanAt(START, ['h1h2'], 0, 'a5a6')).toBe('a6')
    expect(sanAt(START, GAME, 4, 'a7a8q')).toBe('a8=Q')
    expect(sanAt(START, [], 0, 'a1a8')).toBe('')
  })

  it('builds the result sheet from the server’s verdict', () => {
    const base = {
      moves: ['h1h2'],
      mistakeAt: 0,
      bestMove: 'a5a6',
      playerMoves: 1,
      budgetMoves: 18,
      holdMoves: null
    }
    expect(
      endgameFeedback({ ...base, status: 'spoiled' }, START)
    ).toMatchObject({
      kind: 'miss',
      title: 'Le résultat s’envole',
      sub: 'Le bon coup était a6.'
    })
    expect(
      endgameFeedback(
        {
          ...base,
          status: 'won',
          moves: GAME,
          mistakeAt: null,
          bestMove: null,
          playerMoves: 3
        },
        START
      )
    ).toMatchObject({ kind: 'win', sub: 'En 3 coups.' })
    expect(
      endgameFeedback(
        { ...base, status: 'overtime', mistakeAt: null, bestMove: null },
        START
      )
    ).toMatchObject({
      kind: 'miss',
      palette: 'help',
      sub: 'Le gain est resté, mais il fallait conclure en 18 coups.'
    })
    // « Passer »: the server gives no move, the one found here is shown.
    expect(
      endgameFeedback(
        {
          ...base,
          status: 'resigned',
          moves: [],
          mistakeAt: null,
          bestMove: null
        },
        START,
        'a5a6'
      ).sub
    ).toBe('Un bon coup : a6.')
    // The Roi des cendres' clock out.
    expect(
      endgameFeedback(
        { ...base, status: 'timeout', mistakeAt: null, bestMove: null },
        START
      )
    ).toMatchObject({
      kind: 'miss',
      badge: '⏱︎',
      title: 'Temps écoulé',
      sub: 'Le chrono du Roi a sonné avant la fin.'
    })
  })

  it('lists a missed position in the run’s review, not replayable', () => {
    const missed = {
      index: 2,
      type: 'endgame_position',
      status: 'fail',
      durationMs: 30_000,
      data: { fen: START, lesson: 'lucena', status: 'spoiled' }
    }
    expect(itemTitle(missed, key => key)).toBe(
      'La position de Lucena · Le résultat s’envole'
    )
    expect(missedItems([missed], key => key)[0].replayable).toBe(false)
  })
})

describe('useEndgameGame', () => {
  it('wins against Maia, each move judged by the tablebase', async () => {
    const { game, finished, maia, engine, tablebase } = setup()
    game.load(item())
    await playAll(game)

    expect(game.outcome.value).toBe('won')
    expect(game.phase.value).toBe('over')
    expect(finished).toHaveBeenCalledWith({ moves: GAME }, 'won')
    expect(maia.chooseMove).toHaveBeenCalledWith(
      expect.stringMatching(/^8\/8\/P7/),
      { elo: 1500 }
    )
    // The player's positions only: Maia's are not asked.
    expect(tablebase).toHaveBeenCalledTimes(3)
    expect(engine.analyse).not.toHaveBeenCalled()
    expect(game.counter.value).toEqual({ kind: 'budget', done: 3, total: 18 })
  })

  it('stops at a move that spoils the win, the right one shown', async () => {
    const { game, finished, maia } = setup()
    game.load(item())
    expect(game.play('a1a8')).toBe(false)
    game.play('h1h2')
    await settle()

    expect(game.outcome.value).toBe('spoiled')
    expect(game.mistake.value).toEqual({ ply: 0, bestMove: 'a5a6' })
    expect(finished).toHaveBeenCalledWith({ moves: ['h1h2'] }, 'spoiled')
    expect(maia.chooseMove).not.toHaveBeenCalled()
  })

  it('plays the best defence at « Parfait », without downloading Maia', async () => {
    const maia = fakeMaia([], { status: 'idle' })
    const { game, finished, tablebase } = setup({ maia })
    game.load(item({ opponent: { elo: null, perfect: true } }))
    await playAll(game)

    expect(finished).toHaveBeenCalledWith({ moves: GAME }, 'won')
    expect(maia.chooseMove).not.toHaveBeenCalled()
    // The opponent's positions too: the server checks the best defence from its kept answers.
    expect(tablebase).toHaveBeenCalledTimes(5)
  })

  it('falls back on the best defence when Maia cannot play', async () => {
    const { game, finished } = setup({ maia: fakeMaia([], { fail: true }) })
    game.load(item())
    game.play('a5a6')
    await settle()

    expect(game.notice.value).toBe('maiaUnavailable')
    expect(game.moves.value).toEqual(['a5a6', 'f2e3'])
    await playAll(game, ['a6a7', 'a7a8q'])
    expect(finished).toHaveBeenCalledWith({ moves: GAME }, 'won')
  })

  it('lets Stockfish judge when the tablebase is unavailable, its verdict sent', async () => {
    const down = {
      response: { status: 503, headers: { 'x-lichess-unavailable': 'down' } }
    }
    const engine = fakeEngine({ cp: 0 })
    const { game, finished } = setup({
      tablebase: fakeTablebase({ '8/8/8/P7/8/8/5k2/7K w': down }),
      engine
    })
    game.load(item())
    game.play('h1h2')
    await settle()

    expect(game.notice.value).toBe('tablebaseUnavailable')
    expect(engine.analyse).toHaveBeenCalledWith(expect.any(String), {
      movetime: 1000
    })
    expect(game.mistake.value).toEqual({ ply: 0, bestMove: 'a5a6' })
    expect(finished).toHaveBeenCalledWith(
      { moves: ['h1h2'], engine: { right: false, mistakeAt: 0 } },
      'spoiled'
    )
  })

  it('keeps a move Stockfish scores +4 or more', async () => {
    const engine = fakeEngine({ cp: -450 })
    const { game } = setup({ tablebase: fakeTablebase({}), engine })
    game.load(item())
    game.play('a5a6')
    await settle()

    expect(game.outcome.value).toBeNull()
    expect(game.phase.value).toBe('player')
  })

  it('gives a free hint in learn mode only', async () => {
    const { game } = setup()
    game.load(item())
    await settle()
    expect(game.canHint.value).toBe(false)

    game.load(item({ mode: 'learn' }))
    await settle()
    expect(game.canHint.value).toBe(true)
    game.askHint()
    await settle()
    expect(game.hint.value).toBe('a5a6')
    game.play('a5a6')
    expect(game.hint.value).toBeNull()
  })

  it('gives the position up, a right move shown', async () => {
    const { game, finished } = setup()
    game.load(item())
    game.pass()
    await settle()

    expect(game.outcome.value).toBe('resigned')
    expect(finished).toHaveBeenCalledWith({ moves: [], pass: true }, 'resigned')
    expect(game.mistake.value).toEqual({ ply: 0, bestMove: 'a5a6' })
  })

  it('runs out of moves', async () => {
    const { game, finished } = setup()
    game.load(item({ budgetMoves: 1 }))
    game.play('a5a6')
    await settle()

    expect(finished).toHaveBeenCalledWith({ moves: ['a5a6'] }, 'overtime')
  })

  it('stops on the Roi des cendres’ clock, the game kept to be sent as given up', async () => {
    const maia = fakeMaia()
    let release = () => {}
    maia.chooseMove.mockImplementationOnce(
      () => new Promise(resolve => (release = () => resolve('f2e3')))
    )
    const { game, finished, engine } = setup({ maia })
    game.load(item())
    game.play('a5a6')
    await settle()

    expect(game.timeUp()).toEqual({ moves: ['a5a6'], pass: true })
    expect(game.outcome.value).toBe('timeout')
    expect(game.phase.value).toBe('over')
    expect(maia.stop).toHaveBeenCalled()
    expect(engine.stop).toHaveBeenCalled()
    // Sent later by the player, once late for the server: not here.
    expect(finished).not.toHaveBeenCalled()
    release()
    await settle()
    expect(game.moves.value).toEqual(['a5a6'])
    // Over already: nothing more to send.
    expect(game.timeUp()).toBeNull()
  })

  it('answers at once against the Roi des cendres’ clock, after a pause otherwise', async () => {
    const wait = vi.fn(async () => {})
    const scope = effectScope()
    const game = scope.run(() =>
      useEndgameGame({
        tablebase: fakeTablebase(SQUARE),
        maia: fakeMaia(),
        engine: fakeEngine(),
        delayMs: 60_000,
        wait,
        random: () => 0
      })
    )
    game.load(item({ clockSeconds: 90, deadline: '2026-10-24T10:00:00Z' }))
    game.play('a5a6')
    await settle()
    expect(wait).not.toHaveBeenCalled()

    game.load(item({ index: 2 }))
    game.play('a5a6')
    await settle()
    expect(wait).toHaveBeenCalledTimes(1)
  })

  it('drops the answers of a position left for another', async () => {
    const maia = fakeMaia()
    let release = () => {}
    maia.chooseMove.mockImplementationOnce(
      () => new Promise(resolve => (release = () => resolve('f2e3')))
    )
    const { game } = setup({ maia })
    game.load(item())
    game.play('a5a6')
    await settle()
    game.load(item({ index: 2 }))
    release()
    await settle()

    expect(game.moves.value).toEqual([])
    expect(game.phase.value).toBe('player')
  })
})

describe('the demonstration', () => {
  it('plays the fastest keeping move for the player, the best defence for the other side', () => {
    const position = answer(
      [
        ['h1h2', 'draw'],
        ['a5a6', 'win'],
        ['h1g1', 'win']
      ],
      ['f2e3']
    )
    // The tablebase's order: its first keeping move.
    expect(demoMove(position, true, 'win')).toBe('a5a6')
    expect(demoMove(position, true, 'draw')).toBe('h1h2')
    expect(demoMove(position, false, 'win')).toBe('f2e3')
    // No defence given: the tablebase's first move.
    expect(demoMove(answer([['e3d4', 'loss']]), false, 'win')).toBe('e3d4')
    expect(demoMove(answer([['h1h2', 'draw']]), true, 'win')).toBeNull()
  })

  it('stops at the lesson’s goal, the game’s end or its length', () => {
    const promote = { goal: 'promote', holdMoves: null }
    const game = new Chess('8/P7/8/8/3k4/8/8/7K w - - 0 1')
    const move = playUci(game, 'a7a8q')
    expect(demoOver(promote, game, move, true, 3, 5)).toBe(true)
    const quiet = new Chess(START)
    const step = playUci(quiet, 'a5a6')
    expect(demoOver(promote, quiet, step, true, 1, 1)).toBe(false)
    const hold = { goal: 'hold', holdMoves: 1 }
    expect(demoOver(hold, quiet, step, true, 1, 1)).toBe(true)
    expect(demoOver(promote, quiet, step, true, 1, DEMO_MAX_PLIES)).toBe(true)
  })

  it('works the whole line out from the tablebase, shown move by move', async () => {
    const tablebase = fakeTablebase(SQUARE)
    const scope = effectScope()
    const demo = scope.run(() => useEndgameDemo({ tablebase }))
    demo.load({ fen: START, goal: 'promote', result: 'win', holdMoves: null })
    expect(demo.waiting.value).toBe(true)
    await settle()

    expect(demo.status.value).toBe('done')
    expect(demo.moves.value).toEqual(GAME)
    expect(demo.sans.value).toEqual(['a6', 'Ke3', 'a7', 'Kd4', 'a8=Q'])
    expect(demo.fen.value).toBe(START)
    demo.next()
    demo.next()
    expect(demo.cursor.value).toBe(2)
    expect(demo.lastMove.value).toBe('f2e3')
    demo.previous()
    expect(demo.fen.value).toMatch(/^8\/8\/P7\/8\/8\/8\/5k2\/7K b/)
    demo.goTo(99)
    expect(demo.atEnd.value).toBe(true)
    demo.restart()
    expect(demo.cursor.value).toBe(0)
    scope.stop()
  })

  it('stops where it is when Lichess cannot answer', async () => {
    const tablebase = vi.fn(async (/** @type {string} */ fen) => {
      if (fen === START) return SQUARE['8/8/8/P7/8/8/5k2/7K w']
      throw Object.assign(new Error('busy'), { response: { status: 503 } })
    })
    const scope = effectScope()
    const demo = scope.run(() => useEndgameDemo({ tablebase }))
    demo.load({ fen: START, goal: 'promote', result: 'win', holdMoves: null })
    await settle()

    expect(demo.status.value).toBe('unavailable')
    expect(demo.moves.value).toEqual(['a5a6'])
    scope.stop()
  })

  describe('net', () => {
    it('draws the squares the rook and the king deny to the enemy king', () => {
      const net = netSquares('8/4k3/8/R7/8/8/8/4K3 w - - 0 1', 'white')
      // The rook cuts the fifth rank and the a-file; the king guards its own squares.
      for (const square of ['b5', 'e5', 'h5', 'a8', 'a1', 'd2', 'e2', 'f1'])
        expect(net).toContain(square)
      // Not under the player's own pieces, nor beyond the rook's lines.
      for (const square of ['a5', 'e1', 'e6', 'd7'])
        expect(net).not.toContain(square)
    })

    it('denies the square behind a checked king on the checking line', () => {
      const net = netSquares('8/8/8/4k3/8/8/4R3/K7 b - - 0 1', 'white')
      expect(net).toContain('e6')
      expect(net).toContain('e8')
    })

    it('counts a pawn’s captures, not its push, and works for Black', () => {
      const white = netSquares('8/8/8/8/3P4/8/8/k1K5 w - - 0 1', 'white')
      expect(white).toEqual(expect.arrayContaining(['c5', 'e5']))
      expect(white).not.toContain('d5')
      const black = netSquares('8/8/8/3p4/8/8/8/k1K5 b - - 0 1', 'black')
      expect(black).toEqual(expect.arrayContaining(['c4', 'e4', 'a2', 'b2']))
      expect(black).not.toContain('d4')
    })

    it('draws nothing for a position it cannot read', () => {
      expect(netSquares('not a fen', 'white')).toEqual([])
    })
  })
})

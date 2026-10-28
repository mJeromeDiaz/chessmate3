import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { isEngineAbort, useEngine } from '@/composables/engine/useEngine'
import { useMaia } from '@/composables/maia/useMaia'
import { arenaApi } from '@/services/api'
import { speedOf } from '@/utils/arena'
import {
  endReaction,
  eloFor,
  reactionTo,
  replay,
  reweigh,
  thinkMs
} from '@/utils/arenaBoss'
import { isNearBest } from '@/utils/engine/uci'
import { drawMove } from '@/utils/maia/encoding'

/**
 * A game of the arena in the browser (docs/ARENA.md, § 3): the player's moves and the boss's sent
 * to the server, which checks them and keeps the clocks. The boss is Maia at its Elo (its endgame
 * Elo once in an endgame, its opening Elo in the first plies), its styles weighing Maia's moves; a
 * guarded boss has Stockfish replace the move when it is not one of the best (within
 * {@link guardCp}, tighter beyond Maia's 2600: the professors). It thinks as long as its
 * speed says. Maia unavailable: Stockfish plays at the boss's strength, and `notice` says so.
 *
 * A game found in progress is resumed: the boss plays its move if it had the move.
 *
 * @typedef {import('@/utils/arenaBoss').ArenaGame} ArenaGame
 * @typedef {import('@/utils/arenaBoss').ReactionKey} ReactionKey
 * @typedef {{boss: string, color: string, cadence: string}} GameSettings
 */

/** A guarded boss keeps Maia's move when it is within this of Stockfish's best (centipawns). */
export const GUARD_CP = 150

/** Maia's highest strength: beyond it (the professors), Stockfish's guard tightens. */
export const MAIA_TOP_ELO = 2600

/**
 * The guard's tolerance at a strength: {@link GUARD_CP} up to Maia's top, then 50 cp less per 100
 * Elo above it, down to 25 (Ray, 2800: 50 cp).
 *
 * @param {number} elo
 */
export function guardCp(elo) {
  if (elo <= MAIA_TOP_ELO) return GUARD_CP
  return Math.max(25, GUARD_CP - ((elo - MAIA_TOP_ELO) / 100) * 50)
}

/** Stockfish's look at a guarded boss's move, and its move when it stands in for Maia (ms). */
export const ENGINE_MOVETIME = 400

/** Stockfish's weakest strength (UCI_Elo), when it stands in for a weaker Maia. */
const STOCKFISH_MIN_ELO = 1320

/** The player's clock ran out this long ago: the game is read again (the server closes it). */
export const FLAG_CHECK_MS = 2_500

/** How often the clocks shown move. */
const TICK_MS = 200

/**
 * The server's reason for a refusal ('arena_out_of_sync'…), '' without one.
 *
 * @param {any} error
 */
export function refusalOf(error) {
  return String(error?.response?.data?.detail ?? '')
}

/**
 * @param {{
 *   api?: typeof arenaApi,
 *   maia?: ReturnType<typeof useMaia>,
 *   engine?: ReturnType<typeof useEngine>,
 *   wait?: (ms: number) => Promise<void>,
 *   now?: () => number,
 *   random?: () => number
 * }} [options]
 */
export function useArenaGame({
  api = arenaApi,
  maia = useMaia(),
  engine = useEngine(),
  wait = ms => new Promise(resolve => setTimeout(resolve, ms)),
  now = () => Date.now(),
  random = Math.random
} = {}) {
  /** @type {import('vue').ShallowRef<ArenaGame|null>} */
  const game = shallowRef(null)
  /** When the clocks of `game` were read. */
  const syncedAt = ref(0)
  const tick = ref(now())
  /** The boss is choosing its move. */
  const thinking = ref(false)
  /** The player's move on its way to the server. */
  const sending = ref(false)
  const error = ref(/** @type {unknown} */ (null))
  /** @type {import('vue').Ref<'maiaUnavailable'|null>} */
  const notice = ref(null)
  /**
   * The boss's last reaction (`n` grows with each, for its animation); `thinking` shows its
   * focused face without changing its words.
   *
   * @type {import('vue').Ref<{key: ReactionKey, n: number}>}
   */
  const reaction = ref({ key: /** @type {ReactionKey} */ ('start'), n: 0 })
  const thinkingFace = ref(false)
  /** @type {GameSettings|null} the settings of the last game started here */
  let settings = null
  /** Bumped by each new game, each action and on dispose: an older boss move is dropped. */
  let generation = 0
  let reloading = false

  const position = computed(() => replay(game.value?.moves ?? []))
  const active = computed(() => game.value?.status === 'active')
  const playerTurn = computed(
    () => active.value && game.value?.turn === 'player' && !sending.value
  )
  const clocks = computed(() => {
    const g = game.value
    if (!g) return { player: 0, boss: 0 }
    const elapsed =
      g.status === 'active' && g.clocksRunning
        ? Math.max(0, tick.value - syncedAt.value)
        : 0
    return {
      player:
        g.turn === 'player' ? Math.max(0, g.playerMs - elapsed) : g.playerMs,
      boss: g.turn === 'boss' ? Math.max(0, g.bossMs - elapsed) : g.bossMs
    }
  })

  const timer = setInterval(() => {
    tick.value = now()
    const g = game.value
    if (
      g?.status === 'active' &&
      g.turn === 'player' &&
      g.clocksRunning &&
      !sending.value &&
      g.playerMs - (tick.value - syncedAt.value) < -FLAG_CHECK_MS
    )
      reload()
  }, TICK_MS)

  onScopeDispose(() => {
    clearInterval(timer)
    generation++
  })

  /** @param {ReactionKey|null} key */
  function react(key) {
    thinkingFace.value = false
    if (key) reaction.value = { key, n: reaction.value.n + 1 }
  }

  /**
   * The game as the server sent it; the boss plays if it has the move.
   *
   * @param {ArenaGame} next
   */
  function set(next) {
    game.value = next
    syncedAt.value = now()
    tick.value = syncedAt.value
    if (next.status !== 'active') {
      react(endReaction(next.status))
      return
    }
    if (next.turn === 'boss') bossTurn(next)
  }

  /**
   * A new game, or one just found: the boss's taunt first.
   *
   * @param {ArenaGame} next
   */
  function open(next) {
    generation++
    notice.value = null
    error.value = null
    react('start')
    set(next)
  }

  /**
   * Starts a game against a boss.
   *
   * @param {GameSettings} chosen
   */
  async function begin(chosen) {
    settings = chosen
    open(await api.start(chosen))
  }

  /** Resumes the game in progress, if any: true when there is one. */
  async function resume() {
    const current = await api.current()
    if (current) open(current)
    return !!current
  }

  /** Reads the game again (out of sync, its end, the player's clock run out). */
  async function reload() {
    const g = game.value
    if (!g || reloading) return
    reloading = true
    try {
      generation++
      set(await api.game(g.id))
    } catch (e) {
      error.value = e
    } finally {
      reloading = false
    }
  }

  /**
   * The player's move: false when it was not the player's turn or the server refused it (the
   * board goes back to the game).
   *
   * @param {string} uci
   */
  async function play(uci) {
    const g = game.value
    if (!g || !playerTurn.value) return false
    sending.value = true
    // At once on the board: the boss starts thinking when the server answers.
    game.value = { ...g, moves: [...g.moves, uci], turn: 'boss' }
    try {
      const next = await api.move(g.id, g.moves.length, uci)
      react(reactionTo(next.moves, false))
      set(next)
      return true
    } catch (e) {
      game.value = g
      if (refusalOf(e).startsWith('arena_')) await reload()
      else error.value = e
      return false
    } finally {
      sending.value = false
    }
  }

  /**
   * The boss chooses its move, waits as long as it thinks, then sends it.
   *
   * @param {ArenaGame} g
   */
  async function bossTurn(g) {
    const mine = ++generation
    thinking.value = true
    thinkingFace.value = true
    const started = now()
    try {
      const fen = replay(g.moves).fen
      const move = await chooseMove(fen, g)
      if (!move || mine !== generation) return
      const left = thinkMs(speedOf(g.boss), g, random) - (now() - started)
      if (left > 0) await wait(left)
      if (mine !== generation) return
      const next = await api.move(g.id, g.moves.length, move)
      if (mine !== generation) return
      react(reactionTo(next.moves, true))
      set(next)
    } catch (e) {
      if (isEngineAbort(e) || mine !== generation) return
      if (refusalOf(e).startsWith('arena_')) await reload()
      else error.value = e
    } finally {
      if (mine === generation) thinking.value = false
    }
  }

  /**
   * The boss's move in this position: Maia's, weighed by its styles; Stockfish's best when it is
   * guarded and Maia's is not good enough, or when Maia is unavailable.
   *
   * @param {string} fen
   * @param {ArenaGame} g
   * @returns {Promise<string|null>}
   */
  async function chooseMove(fen, g) {
    const elo = eloFor(g, fen, g.moves.length)
    /** @type {string|null} */
    let move
    try {
      const prediction = await maia.predict(fen, { elo, opponentElo: elo })
      move = drawMove(reweigh(fen, prediction.chances, g.styles), { random })
    } catch (e) {
      if (isEngineAbort(e)) throw e
      notice.value = 'maiaUnavailable'
      return engine.bestMove(fen, {
        movetime: ENGINE_MOVETIME,
        options: {
          UCI_LimitStrength: true,
          UCI_Elo: Math.max(STOCKFISH_MIN_ELO, elo)
        }
      })
    }
    if (!g.guarded || !move) return move
    try {
      const { lines } = await engine.analyse(fen, {
        multiPv: 3,
        movetime: ENGINE_MOVETIME
      })
      if (lines[0] && !isNearBest(lines, move, guardCp(elo)))
        return lines[0].move
    } catch (e) {
      if (isEngineAbort(e)) throw e
      // Stockfish unavailable: Maia's move stands.
    }
    return move
  }

  /**
   * Resigns (lost), cancels (counted nowhere) or restarts (cancels, then the same game again).
   *
   * @param {'resign'|'abort'|'restart'} action
   */
  async function act(action) {
    const g = game.value
    if (!g) return
    generation++
    maia.stop()
    engine.stop()
    thinking.value = false
    const next = await api.act(g.id, action)
    if (action === 'restart') open(next)
    else set(next)
  }

  /** The same game again once this one is over (the side chosen, « random » drawn again). */
  async function rematch() {
    const g = game.value
    if (!g) return
    await begin(
      settings && settings.boss === g.boss
        ? settings
        : { boss: g.boss, color: g.color, cadence: g.cadence }
    )
  }

  /** Lets go of the game shown (the page leaves it): the boss stops thinking. */
  function leave() {
    generation++
    maia.stop()
    engine.stop()
  }

  return {
    game,
    position,
    active,
    playerTurn,
    clocks,
    thinking,
    thinkingFace,
    sending,
    reaction,
    notice,
    error,
    /** Maia's state and download (46 MB the first time): shown while the boss waits for it. */
    maiaStatus: maia.status,
    maiaProgress: maia.progress,
    preload: () => maia.preload(),
    begin,
    resume,
    reload,
    play,
    act,
    rematch,
    leave
  }
}

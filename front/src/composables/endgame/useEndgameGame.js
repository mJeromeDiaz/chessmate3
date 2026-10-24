import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { endgameApi } from '@/services/api'
import { isEngineAbort, useEngine } from '@/composables/engine/useEngine'
import { useMaia } from '@/composables/maia/useMaia'
import {
  atLeast,
  counter as counterOf,
  engineKeeps,
  gameFrom,
  keepingMoves,
  playUci,
  reached
} from '@/utils/endgame'

/**
 * One position of the endgames module played in the browser (docs/ENDGAMES.md, § 8): the player's
 * moves judged as they come by the tablebase (asked for each position where the player is to move,
 * kept by the server, which judges the whole game again at the end), the opponent's moves by Maia
 * at the Elo chosen, or the tablebase's best defence at « Parfait ».
 *
 * - The tablebase unavailable (Lichess down, rate-limited, offline): Stockfish judges the rest of
 *   the position (a mate or +4 pawns keeps a win, no worse than −2 a draw), and its verdict is sent.
 * - Maia unavailable: the opponent plays the best defence, and `notice` says so.
 *
 * @typedef {import('@/utils/endgame').TablebaseAnswer} TablebaseAnswer
 * @typedef {import('@/utils/endgame').Outcome} Outcome
 * @typedef {{fen: string, lesson: string, family: string, goal: import('@/utils/endgame').Goal, result: import('@/utils/endgame').Result, textbook: boolean, mode: 'learn'|'review', explain: boolean, budgetMoves: number|null, holdMoves: number|null, opponent: {elo: number|null, perfect: boolean}, index: number, count?: number|null, lives?: number|null, clockSeconds?: number|null, deadline?: string|null, tier?: number|null}} EndgameItem
 * @typedef {{moves: string[], pass?: true, engine?: {right: boolean, mistakeAt: number|null}}} EndgameReport
 * @typedef {'idle'|'player'|'opponent'|'over'} GamePhase
 * @typedef {'maiaUnavailable'|'tablebaseUnavailable'|null} GameNotice
 */

/** Stockfish's thinking time for a verdict or a move, when it stands in for the tablebase. */
export const ENGINE_MOVETIME = 1000
/**
 * The opponent answers after this long at least: an instant reply feels like a machine. Not against
 * the Roi des cendres' clock, which counts its time too.
 */
export const OPPONENT_DELAY_MS = 500

/**
 * Whether a failed tablebase call means « no tablebase now » (Lichess down or busy, too many
 * calls, offline) rather than a bad request.
 *
 * @param {any} error an axios error
 */
export function tablebaseUnavailable(error) {
  const status = error?.response?.status
  return !error?.response || status === 503 || status === 429
}

/**
 * @param {{
 *   tablebase?: (fen: string) => Promise<TablebaseAnswer>,
 *   maia?: ReturnType<typeof useMaia>,
 *   engine?: ReturnType<typeof useEngine>,
 *   delayMs?: number,
 *   wait?: (ms: number) => Promise<void>,
 *   random?: () => number,
 *   onFinish?: (report: EndgameReport, outcome: Outcome) => void
 * }} [options]
 */
export function useEndgameGame({
  tablebase = fen => endgameApi.tablebase(fen),
  maia = useMaia(),
  engine = useEngine(),
  delayMs = OPPONENT_DELAY_MS,
  wait = ms => new Promise(resolve => setTimeout(resolve, ms)),
  random = Math.random,
  onFinish
} = {}) {
  /** @type {import('vue').ShallowRef<EndgameItem|null>} */
  const item = shallowRef(null)
  const fen = ref('')
  /** @type {import('vue').Ref<string[]>} the game so far, both sides, UCI */
  const moves = ref([])
  const playerMoves = ref(0)
  /** @type {import('vue').Ref<GamePhase>} */
  const phase = ref('idle')
  /** @type {import('vue').Ref<Outcome|null>} the verdict seen here (the server's makes it final) */
  const outcome = ref(null)
  /** @type {import('vue').Ref<{ply: number, bestMove: string|null}|null>} the move that spoiled it */
  const mistake = ref(null)
  /** @type {import('vue').Ref<string|null>} a move that keeps the result, asked for (learn mode) */
  const hint = ref(null)
  /** @type {import('vue').Ref<GameNotice>} */
  const notice = ref(null)
  /** Judging the player's move (the tablebase or Stockfish answering). */
  const busy = ref(false)
  const error = ref(/** @type {unknown} */ (null))

  /** @type {Map<string, Promise<TablebaseAnswer|null>>} by FEN; null: no tablebase now */
  let answers = new Map()
  /** Stockfish stands in for the tablebase for the rest of the position. */
  let engineMode = false
  /** A position the server needs was not kept: Stockfish's verdict goes with the game. */
  let engineUsed = false
  /** Bumped by each new position and on dispose: the answers of an older one are dropped. */
  let generation = 0

  const counter = computed(() =>
    item.value ? counterOf(item.value, playerMoves.value) : null
  )
  const canHint = computed(
    () =>
      item.value?.mode === 'learn' && phase.value === 'player' && !busy.value
  )

  /**
   * The tablebase's answer for a position (asked once), null when it cannot answer now: Stockfish
   * then judges the rest of the position.
   *
   * @param {string} position
   * @returns {Promise<TablebaseAnswer|null>}
   */
  function ask(position) {
    if (engineMode) return Promise.resolve(null)
    let answer = answers.get(position)
    if (!answer) {
      answer = tablebase(position).then(
        value => (value.known ? value : null),
        e => {
          if (!tablebaseUnavailable(e)) throw e
          return null
        }
      )
      answers.set(position, answer)
    }
    return answer.then(value => {
      if (!value && !engineMode) {
        engineMode = true
        engineUsed = true
        notice.value ??= 'tablebaseUnavailable'
      }
      return value
    })
  }

  /**
   * A new position to play (the run's item): asks the tablebase for it at once.
   *
   * @param {EndgameItem} next
   */
  function load(next) {
    generation++
    engine.stop()
    maia.stop()
    answers = new Map()
    engineMode = false
    engineUsed = false
    item.value = next
    fen.value = next.fen
    moves.value = []
    playerMoves.value = 0
    outcome.value = null
    mistake.value = null
    hint.value = null
    notice.value = null
    busy.value = false
    error.value = null
    phase.value = 'player'
    ask(next.fen).catch(e => {
      error.value = e
    })
  }

  /**
   * Runs `task` for the position on screen; its end is dropped when another one came meanwhile.
   * A failure is kept in `error` (the player can still give the position up).
   *
   * @param {(stale: () => boolean) => Promise<void>} task
   */
  async function guarded(task) {
    const mine = generation
    try {
      await task(() => mine !== generation)
    } catch (e) {
      if (mine !== generation || isEngineAbort(e)) return
      busy.value = false
      error.value = e
    }
  }

  /**
   * The player's move, judged before the opponent answers. False when it cannot be played now
   * (not the player's turn, a move being judged, illegal).
   *
   * @param {string} uci
   */
  function play(uci) {
    const current = item.value
    if (!current || phase.value !== 'player' || busy.value) return false
    const before = fen.value
    const game = gameFrom(before)
    const move = playUci(game, uci)
    if (!move) return false
    busy.value = true
    hint.value = null
    guarded(async stale => {
      const keeps = await judge(current, before, uci, game)
      if (stale()) return
      busy.value = false
      const ply = moves.value.length
      moves.value = [...moves.value, uci]
      fen.value = game.fen()
      if (!keeps.right) {
        mistake.value = { ply, bestMove: keeps.bestMove }
        return finish('spoiled')
      }
      playerMoves.value++
      const done = reached(current, game, move, true, playerMoves.value)
      if (done) return finish(done)
      if (
        current.budgetMoves !== null &&
        playerMoves.value >= current.budgetMoves
      ) {
        return finish('overtime')
      }
      await opponent(current, stale)
    })
    return true
  }

  /**
   * Whether the player's move keeps at least the lesson's result, and a move that did when not.
   *
   * @param {EndgameItem} current
   * @param {string} before the position the move was played in
   * @param {string} uci
   * @param {import('chess.js').Chess} after
   * @returns {Promise<{right: boolean, bestMove: string|null}>}
   */
  async function judge(current, before, uci, after) {
    const answer = await ask(before)
    if (answer) {
      const keeping = keepingMoves(answer, current.result)
      return { right: keeping.includes(uci), bestMove: keeping[0] ?? null }
    }
    let right
    if (after.isCheckmate()) right = true
    else if (after.isStalemate() || after.isInsufficientMaterial()) {
      right = atLeast('draw', current.result)
    } else {
      const { lines } = await engine.analyse(after.fen(), {
        movetime: ENGINE_MOVETIME
      })
      right = lines[0] ? engineKeeps(lines[0].score, current.result) : false
    }
    if (right) return { right, bestMove: null }
    return {
      right,
      bestMove: await engine.bestMove(before, { movetime: ENGINE_MOVETIME })
    }
  }

  /**
   * The opponent's reply, then the player's turn (its position asked to the tablebase at once).
   *
   * @param {EndgameItem} current
   * @param {() => boolean} stale
   */
  async function opponent(current, stale) {
    phase.value = 'opponent'
    const started = Date.now()
    const position = fen.value
    const uci = await opponentMove(current, position)
    if (stale()) return
    const pause = current.clockSeconds ? 0 : delayMs
    const left = pause - (Date.now() - started)
    if (left > 0) await wait(left)
    if (stale() || !uci) return
    const game = gameFrom(position)
    const move = playUci(game, uci)
    if (!move) throw new Error(`The opponent's move ${uci} is illegal.`)
    moves.value = [...moves.value, uci]
    fen.value = game.fen()
    const done = reached(current, game, move, false, playerMoves.value)
    if (done) return finish(done)
    phase.value = 'player'
    ask(fen.value).catch(e => {
      if (!stale()) error.value = e
    })
  }

  /**
   * The opponent's move: Maia at the Elo chosen, the best defence at « Parfait » (or when Maia
   * cannot play), Stockfish when the tablebase cannot answer either.
   *
   * @param {EndgameItem} current
   * @param {string} position
   * @returns {Promise<string|null>}
   */
  async function opponentMove(current, position) {
    if (!current.opponent.perfect && current.opponent.elo !== null) {
      const played = await maiaMove(position, { elo: current.opponent.elo })
      if (played) return played
      notice.value = 'maiaUnavailable'
    }
    const answer = await ask(position)
    if (answer && answer.defence.length > 0)
      return defenceMove(position, answer.defence)
    // At « Parfait », an opponent's position missing from the tablebase: Stockfish judges.
    if (current.opponent.perfect) engineUsed = true
    return engine.bestMove(position, { movetime: ENGINE_MOVETIME })
  }

  /**
   * One of the best defence's moves: Maia breaks the tie when it is loaded (it is never
   * downloaded for « Parfait » alone), chance otherwise.
   *
   * @param {string} position
   * @param {string[]} defence
   */
  async function defenceMove(position, defence) {
    if (defence.length > 1 && maia.status.value === 'ready') {
      const played = await maiaMove(position, { elo: 2600, among: defence })
      if (played) return played
    }
    return defence[
      Math.min(defence.length - 1, Math.floor(random() * defence.length))
    ]
  }

  /**
   * Maia's move, null when Maia cannot play (an abort still stops everything).
   *
   * @param {string} position
   * @param {{elo: number, among?: string[]}} options
   */
  async function maiaMove(position, options) {
    try {
      return await maia.chooseMove(position, options)
    } catch (e) {
      if (isEngineAbort(e)) throw e
      return null
    }
  }

  /** A move that keeps the result, shown on the board (learn mode only, free). */
  function askHint() {
    const current = item.value
    if (!current || !canHint.value) return
    const position = fen.value
    guarded(async stale => {
      const answer = await ask(position)
      const best = answer
        ? (keepingMoves(answer, current.result)[0] ?? null)
        : await engine.bestMove(position, { movetime: ENGINE_MOVETIME })
      if (!stale() && fen.value === position) hint.value = best
    })
  }

  /** « Passer »: the position given up, a move that kept the result shown. */
  function pass() {
    const current = item.value
    if (!current || phase.value === 'over') return
    const position = fen.value
    const ply = moves.value.length
    generation++
    engine.stop()
    maia.stop()
    busy.value = false
    finish('resigned', true)
    guarded(async stale => {
      const answer = await ask(position)
      const best = answer
        ? (keepingMoves(answer, current.result)[0] ?? null)
        : await engine.bestMove(position, { movetime: ENGINE_MOVETIME })
      if (!stale()) mistake.value = { ply, bestMove: best }
    })
  }

  /**
   * The Roi des cendres' clock out: everything stops, the position is lost (`timeout`). Gives the
   * report to send once the server finds it late (as given up: never refused as unfinished).
   *
   * @returns {EndgameReport|null} null when the game was already over
   */
  function timeUp() {
    if (!item.value || phase.value === 'over') return null
    generation++
    engine.stop()
    maia.stop()
    busy.value = false
    hint.value = null
    phase.value = 'over'
    outcome.value = 'timeout'
    return { moves: [...moves.value], pass: true }
  }

  /**
   * @param {Outcome} reachedOutcome
   * @param {boolean} [resigned]
   */
  function finish(reachedOutcome, resigned = false) {
    phase.value = 'over'
    outcome.value = reachedOutcome
    /** @type {EndgameReport} */
    const report = { moves: [...moves.value] }
    if (resigned) report.pass = true
    if (engineUsed) {
      const spoiled = reachedOutcome === 'spoiled'
      report.engine = {
        right: !spoiled,
        mistakeAt: spoiled ? (mistake.value?.ply ?? null) : null
      }
    }
    onFinish?.(report, reachedOutcome)
  }

  onScopeDispose(() => {
    generation++
  })

  return {
    item,
    fen,
    moves,
    playerMoves,
    phase,
    outcome,
    mistake,
    hint,
    notice,
    busy,
    error,
    counter,
    canHint,
    load,
    play,
    askHint,
    pass,
    timeUp
  }
}

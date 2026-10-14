import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { coachApi, repertoireApi } from '@/services/api'
import { COACH_GAMES, COACH_PERFS, coachErrorText } from '@/utils/coach'
import { feedPace } from '@/utils/repertoireAuto'
import { t } from '@/i18n'

/** The analysis in progress, kept for a reload of the page (same tab). */
export const STORAGE_KEY = 'dontstayrooky.coach'
/** A module of the composition appears every... */
export const REVEAL_MS = 1900
/** ...after a first pause (the orb looks for a need). */
export const REVEAL_FIRST_MS = 900
/** Wait before asking Lichess again when it gives no Retry-After. */
const RETRY_MS = 5000

/**
 * @typedef {'connect'|'scan'|'compose'|'session'|'go'} CoachStep
 * @typedef {{buildId: string, step: CoachStep, withRepertoire: boolean, composition: import('@/utils/coach').Composition|null}} Stored
 */

/**
 * @param {number} ms
 * @returns {Promise<void>}
 */
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))

/** @param {any} error */
function retryDelay(error) {
  const seconds = Number(error?.response?.headers?.['retry-after'])
  return Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : RETRY_MS
}

/** @param {Stored|null} value */
function remember(value) {
  try {
    if (value) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value))
    else sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // Storage unavailable: a reload starts over.
  }
}

/** @returns {Stored|null} */
function remembered() {
  try {
    const value = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null')
    return value && typeof value.buildId === 'string' ? value : null
  } catch {
    return null
  }
}

/**
 * The state of the tailored session page (docs/COACH.md): the user's last games read from
 * Lichess (a build of the repertoire's, revealed game by game), the diagnosis, the session
 * composed and saved as the coach plan (its modules revealed one by one), then the first module.
 */
export function useCoachSession() {
  /** @type {import('vue').Ref<CoachStep>} */
  const step = ref('connect')
  /** Generate the repertoire from these games when the user has none. */
  const withRepertoire = ref(true)
  /** @type {import('vue').ShallowRef<any>} the build as the API last described it */
  const build = shallowRef(null)
  /** Games revealed so far (the counter follows them). */
  const shown = ref(0)
  /** @type {import('vue').Ref<{white: Record<string, number>, black: Record<string, number>}>} families met, by colour */
  const openings = ref({ white: {}, black: {} })
  /** @type {import('vue').Ref<Record<string, number>>} endgame kinds met, games each */
  const endgames = ref({})
  /** @type {import('vue').ShallowRef<import('@/utils/coach').Diagnosis|null>} */
  const diagnosis = shallowRef(null)
  /** @type {import('vue').ShallowRef<import('@/utils/coach').Composition|null>} */
  const composition = shallowRef(null)
  /** Modules of the composition revealed so far (the orb forges them one by one). */
  const revealed = ref(0)
  /** The need being shown (index), -1 before the first. */
  const current = ref(-1)
  const error = ref('')
  /** Lichess asked us to wait: what the scan screen says meanwhile. */
  const waiting = ref('')
  const busy = ref(false)

  let disposed = false
  /** The reading in progress: a new one makes the previous one stop. */
  let run = 0
  /** @type {ReturnType<typeof setTimeout>[]} */
  let timers = []
  onScopeDispose(() => {
    disposed = true
    clearTimers()
  })

  function clearTimers() {
    timers.forEach(clearTimeout)
    timers = []
  }

  const progress = computed(() =>
    Math.min(1, shown.value / (build.value?.maxGames || COACH_GAMES))
  )
  const scanDone = computed(
    () =>
      !!diagnosis.value &&
      !!build.value &&
      build.value.status !== 'fetching' &&
      shown.value >= build.value.games
  )
  const composed = computed(
    () =>
      !!composition.value && revealed.value >= composition.value.modules.length
  )

  function save() {
    remember(
      build.value
        ? {
            buildId: build.value.id,
            step: step.value,
            withRepertoire: withRepertoire.value,
            composition: composition.value
          }
        : null
    )
  }

  /** Reveals the games of a batch one by one, one every `pace` ms. */
  function reveal(batch, id, pace) {
    batch.forEach((game, i) => {
      timers.push(
        setTimeout(() => {
          if (disposed || id !== run) return
          shown.value++
          const color = game.color === 'black' ? 'black' : 'white'
          openings.value = {
            ...openings.value,
            [color]: {
              ...openings.value[color],
              [game.family]: (openings.value[color][game.family] ?? 0) + 1
            }
          }
          if (game.endgame) {
            endgames.value = {
              ...endgames.value,
              [game.endgame]: (endgames.value[game.endgame] ?? 0) + 1
            }
          }
        }, i * pace)
      )
    })
  }

  /** Starts reading the user's last games (a previous reading stops). */
  async function start() {
    if (busy.value) return
    busy.value = true
    error.value = ''
    const id = ++run
    let created = null
    try {
      created = await repertoireApi.createAutoBuild({
        maxGames: COACH_GAMES,
        perfs: COACH_PERFS
      })
    } catch (e) {
      error.value = coachErrorText(e)
    } finally {
      busy.value = false
    }
    if (!created || id !== run) return
    clearTimers()
    build.value = created
    shown.value = 0
    openings.value = { white: {}, black: {} }
    endgames.value = {}
    diagnosis.value = null
    composition.value = null
    step.value = 'scan'
    save()
    await readAll(id)
  }

  /**
   * Asks for batches until every game is in (waiting when Lichess asks to), then the diagnosis.
   *
   * @param {number} id the reading this loop belongs to
   */
  async function readAll(id) {
    while (!disposed && id === run && build.value?.status === 'fetching') {
      try {
        const started = Date.now()
        const next = await repertoireApi.fetchAutoBuild(build.value.id)
        if (id !== run) return
        waiting.value = ''
        build.value = next
        const pace = feedPace(next.maxGames)
        reveal(next.batch ?? [], id, pace)
        await sleep(
          Math.max(0, (next.batch?.length ?? 0) * pace - (Date.now() - started))
        )
      } catch (e) {
        if (id !== run) return
        if (![503, 429].includes(/** @type {any} */ (e)?.response?.status)) {
          error.value = coachErrorText(e)
          return
        }
        const delay = retryDelay(e)
        waiting.value = t('coach.waiting', { n: Math.round(delay / 1000) })
        await sleep(delay)
      }
    }
    if (disposed || id !== run || !build.value) return
    try {
      diagnosis.value = await coachApi.diagnosis(build.value.id)
    } catch (e) {
      error.value = coachErrorText(e)
    }
  }

  /** Composes the session (saved as the coach plan), then reveals its modules one by one. */
  async function compose() {
    if (!build.value || busy.value) return
    busy.value = true
    error.value = ''
    clearTimers()
    composition.value = null
    revealed.value = 0
    current.value = -1
    step.value = 'compose'
    try {
      const [value] = await Promise.all([
        coachApi.compose(build.value.id, {
          withRepertoire: withRepertoire.value
        }),
        sleep(REVEAL_FIRST_MS)
      ])
      if (disposed) return
      composition.value = value
      save()
      value.modules.forEach((_, i) => {
        timers.push(setTimeout(() => (current.value = i), i * REVEAL_MS))
        timers.push(
          setTimeout(
            () => (revealed.value = i + 1),
            i * REVEAL_MS + REVEAL_MS * 0.7
          )
        )
      })
    } catch (e) {
      error.value = coachErrorText(e)
    } finally {
      busy.value = false
    }
  }

  /** @param {CoachStep} next */
  function go(next) {
    clearTimers()
    if (next === 'session' && composition.value) {
      revealed.value = composition.value.modules.length
    }
    step.value = next
    save()
  }

  /** Back to the start: the account and a new reading. */
  function restart() {
    run++
    clearTimers()
    build.value = null
    diagnosis.value = null
    composition.value = null
    error.value = ''
    waiting.value = ''
    step.value = 'connect'
    remember(null)
  }

  /** Picks up an analysis of this tab after a reload (or a return from Lichess). */
  async function resume() {
    const stored = remembered()
    if (!stored) return
    withRepertoire.value = stored.withRepertoire
    try {
      build.value = await repertoireApi.getAutoBuild(stored.buildId)
    } catch {
      remember(null)
      return
    }
    if (build.value.status === 'fetching') {
      step.value = 'scan'
      await readAll(++run)
      return
    }
    shown.value = build.value.games
    diagnosis.value = await coachApi.diagnosis(stored.buildId).catch(() => null)
    if (stored.composition && ['session', 'go'].includes(stored.step)) {
      composition.value = stored.composition
      revealed.value = stored.composition.modules.length
      step.value = stored.step
    } else {
      step.value = 'scan'
    }
  }

  return {
    step,
    withRepertoire,
    build,
    shown,
    openings,
    endgames,
    diagnosis,
    composition,
    revealed,
    current,
    error,
    waiting,
    busy,
    progress,
    scanDone,
    composed,
    start,
    compose,
    go,
    restart,
    resume
  }
}

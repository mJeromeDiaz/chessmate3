import { computed, onScopeDispose, reactive, ref, shallowRef } from 'vue'
import { repertoireApi } from '@/services/api'
import {
  autoErrorText,
  defaultFamilies,
  feedPace
} from '@/utils/repertoireAuto'

/** The build in progress, kept for a reload of the page (same tab). */
export const STORAGE_KEY = 'dontstayrooky.autoBuild'
/** Live feed: games shown at a time (their pace: feedPace()). */
export const FEED_SIZE = 4
/** A phase of the generation lasts at least this long (the animation reads). */
export const PHASE_MS = 1300
/** Wait before asking Lichess again when it gives no Retry-After. */
const RETRY_MS = 5000
/** How long the "line removed" toast offers to undo. */
export const TOAST_MS = 3500

/**
 * @typedef {'connect'|'scan'|'select'|'gen'|'tree'|'done'} AutoStep
 * @typedef {{families: string[], choices: Record<string, Record<string, string>>, removed: Record<string, string[]>}} SelectionPayload
 */

/**
 * @param {number} ms
 * @returns {Promise<void>}
 */
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))

/**
 * @template T
 * @param {Promise<T>} promise
 * @param {number} ms
 * @returns {Promise<T>} the promise's value, not before ms
 */
async function atLeast(promise, ms) {
  const [value] = await Promise.all([promise, sleep(ms)])
  return value
}

/** @param {any} error */
function retryDelay(error) {
  const seconds = Number(error?.response?.headers?.['retry-after'])
  return Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : RETRY_MS
}

/** @param {any} error */
function isLichessPause(error) {
  return [503, 429].includes(error?.response?.status)
}

/** @param {string|null} id */
function remember(id) {
  try {
    if (id) sessionStorage.setItem(STORAGE_KEY, id)
    else sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // Storage unavailable: a reload starts over.
  }
}

/** @returns {string|null} */
function remembered() {
  try {
    return sessionStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

/**
 * The state of the "repertoire from my games" page (docs/REPERTOIRE.md): settings, the games
 * read batch by batch (live feed), the openings chosen, the generation (selection sent, line ends
 * evaluated by the Lichess cloud), the tree with its choices and removals (undoable), and the
 * repertoires created.
 */
export function useAutoBuild() {
  /** @type {import('vue').Ref<AutoStep>} */
  const step = ref('connect')
  const settings = reactive({ count: 200, perfs: ['blitz', 'rapid'] })
  /** @type {import('vue').ShallowRef<any>} the build as the API last described it */
  const build = shallowRef(null)
  /** @type {import('vue').Ref<any[]>} the games shown in the live feed, newest last */
  const feed = ref([])
  /** Games revealed by the feed so far (the counter follows it). */
  const shown = ref(0)
  /** @type {import('vue').Ref<Record<string, {family: string, color: string, n: number}>>} openings met in the games revealed */
  const found = ref({})
  const error = ref('')
  /** Lichess asked us to wait: what the scan screen says meanwhile. */
  const waiting = ref('')
  const busy = ref(false)
  /** @type {import('vue').Ref<string[]>} family keys checked on the selection screen */
  const selected = ref([])
  /** Generation phase: 0 not started, 1 to 4 the phases, 5 done. */
  const phase = ref(0)
  /** Some line ends could not be evaluated (Lichess unavailable). */
  const evaluationFailed = ref(false)
  /** @type {import('vue').Ref<{text: string, undo: SelectionPayload}|null>} */
  const toast = ref(null)

  let disposed = false
  /** The reading in progress: a new one (or going back) makes the previous one stop. */
  let run = 0
  /** @type {ReturnType<typeof setTimeout>[]} */
  let timers = []
  onScopeDispose(() => {
    disposed = true
    timers.forEach(clearTimeout)
  })

  const ready = computed(() => build.value?.status === 'ready')
  const scanDone = computed(
    () =>
      !!build.value &&
      build.value.status !== 'fetching' &&
      shown.value >= build.value.games
  )
  const trees = computed(() => build.value?.trees ?? [])
  const lines = computed(() => trees.value.flatMap(t => t.lines))

  /** @returns {SelectionPayload} the selection as the server holds it */
  function currentSelection() {
    const selection = build.value?.selection
    return {
      families: [...(selection?.families ?? [])],
      choices: structuredClone(toObject(selection?.choices)),
      removed: structuredClone(toObject(selection?.removed))
    }
  }

  /** An empty JSON object comes back as []: an object again. */
  function toObject(value) {
    return value && !Array.isArray(value) ? value : {}
  }

  /** @param {any} next */
  function receive(next) {
    build.value = next
    remember(next?.id ?? null)
  }

  /** Reveals the games of a batch one by one in the feed, one every `pace` ms. */
  function reveal(batch, id, pace) {
    batch.forEach((game, i) => {
      timers.push(
        setTimeout(() => {
          if (disposed || id !== run) return
          feed.value = [
            ...feed.value,
            { ...game, key: `${game.id}-${shown.value}` }
          ].slice(-FEED_SIZE)
          shown.value++
          const key = `${game.color}:${game.family}`
          found.value = {
            ...found.value,
            [key]: {
              family: game.family,
              color: game.color,
              n: (found.value[key]?.n ?? 0) + 1
            }
          }
        }, i * pace)
      )
    })
  }

  /** Starts reading the user's games (a previous reading stops). */
  async function start() {
    if (busy.value || !settings.perfs.length) return
    busy.value = true
    error.value = ''
    const id = ++run
    let created = null
    try {
      created = await repertoireApi.createAutoBuild({
        maxGames: settings.count,
        perfs: settings.perfs
      })
    } catch (e) {
      error.value = autoErrorText(e)
    } finally {
      busy.value = false
    }
    if (!created || id !== run) return
    receive(created)
    feed.value = []
    shown.value = 0
    found.value = {}
    selected.value = []
    waiting.value = ''
    step.value = 'scan'
    await readAll(id)
  }

  /**
   * Asks for batches until every game is in, waiting when Lichess asks to (a reading just
   * stopped may still hold Lichess for a few seconds).
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
        receive(next)
        const pace = feedPace(next.maxGames)
        reveal(next.batch ?? [], id, pace)
        // Let the feed play before the next batch.
        await sleep(
          Math.max(
            0,
            (next.batch?.length ?? 0) * pace - (Date.now() - started)
          )
        )
      } catch (e) {
        if (id !== run) return
        if (!isLichessPause(e)) {
          error.value = autoErrorText(e)
          return
        }
        const delay = retryDelay(e)
        waiting.value = `Lichess finit de traiter une demande précédente : patiente quelques secondes, je reprends dans ${Math.round(delay / 1000)} s…`
        await sleep(delay)
      }
    }
    if (id !== run) return
    if (build.value?.synthesis && !selected.value.length) {
      selected.value = defaultFamilies(build.value.synthesis)
    }
  }

  function toSelect() {
    error.value = ''
    if (build.value?.synthesis && !selected.value.length) {
      selected.value = defaultFamilies(build.value.synthesis)
    }
    step.value = 'select'
  }

  /** @param {string} key */
  function toggleFamily(key) {
    selected.value = selected.value.includes(key)
      ? selected.value.filter(k => k !== key)
      : [...selected.value, key]
  }

  function toggleAll() {
    const all = (build.value?.synthesis?.families ?? []).map(f => f.key)
    selected.value = selected.value.length === all.length ? [] : all
  }

  /**
   * Builds the repertoire of the families checked: the selection sent (the trees come back),
   * the line ends evaluated, each phase shown long enough to be read.
   */
  async function generate() {
    if (!selected.value.length || busy.value) return
    busy.value = true
    error.value = ''
    evaluationFailed.value = false
    step.value = 'gen'
    phase.value = 1
    try {
      const previous = currentSelection()
      const keep = key => selected.value.includes(key)
      const payload = {
        families: selected.value,
        choices: Object.fromEntries(
          Object.entries(previous.choices).filter(([k]) => keep(k))
        ),
        removed: Object.fromEntries(
          Object.entries(previous.removed).filter(([k]) => keep(k))
        )
      }
      receive(
        await atLeast(
          repertoireApi.selectAutoBuild(build.value.id, payload),
          PHASE_MS
        )
      )
      phase.value = 2
      await sleep(PHASE_MS)
      phase.value = 3
      await atLeast(evaluatePending(), PHASE_MS)
      phase.value = 4
      await sleep(PHASE_MS)
      phase.value = 5
      await sleep(PHASE_MS)
      if (!disposed) step.value = 'tree'
    } catch (e) {
      error.value = autoErrorText(e)
      step.value = 'select'
      phase.value = 0
    } finally {
      busy.value = false
    }
  }

  /** Evaluates the line ends Lichess was not asked about yet, until none is left or it fails. */
  async function evaluatePending() {
    while (!disposed && (build.value?.pendingEvaluations ?? 0) > 0) {
      try {
        receive(await repertoireApi.evaluateAutoBuild(build.value.id))
        evaluationFailed.value = false
      } catch {
        evaluationFailed.value = true
        return
      }
    }
  }

  /**
   * Sends a new selection (choices or removals), the trees come back; then evaluates the new
   * line ends.
   *
   * @param {SelectionPayload} selection
   */
  async function send(selection) {
    error.value = ''
    try {
      receive(await repertoireApi.selectAutoBuild(build.value.id, selection))
      evaluatePending()
      return true
    } catch (e) {
      error.value = autoErrorText(e)
      return false
    }
  }

  /**
   * Removes a line (with what follows it), undoable for a moment.
   *
   * @param {string} familyKey
   * @param {{cut: string, name: string}} line
   */
  async function removeLine(familyKey, line) {
    const before = currentSelection()
    const next = currentSelection()
    next.removed[familyKey] = [...(next.removed[familyKey] ?? []), line.cut]
    if (await send(next)) {
      toast.value = { text: `« ${line.name} » retirée`, undo: before }
      const shownToast = toast.value
      timers.push(
        setTimeout(() => {
          if (toast.value === shownToast) toast.value = null
        }, TOAST_MS)
      )
    }
  }

  async function undo() {
    const previous = toast.value?.undo
    toast.value = null
    if (previous) await send(previous)
  }

  /**
   * Plays another move in a position of the user's.
   *
   * @param {string} familyKey
   * @param {string} parentPath the position (node path, '' for the initial one)
   * @param {string} uci
   */
  async function chooseMove(familyKey, parentPath, uci) {
    const next = currentSelection()
    next.choices[familyKey] = {
      ...next.choices[familyKey],
      [parentPath]: uci
    }
    return send(next)
  }

  /** Creates the repertoires. */
  async function apply() {
    if (busy.value) return
    busy.value = true
    error.value = ''
    try {
      receive(await repertoireApi.applyAutoBuild(build.value.id))
      step.value = 'done'
    } catch (e) {
      error.value = autoErrorText(e)
    } finally {
      busy.value = false
    }
  }

  /** Back to the settings, the build forgotten. */
  function restart() {
    run++
    waiting.value = ''
    remember(null)
    build.value = null
    feed.value = []
    shown.value = 0
    found.value = {}
    selected.value = []
    phase.value = 0
    error.value = ''
    step.value = 'connect'
  }

  /** Picks up the build of this tab after a reload, at the step it had reached. */
  async function resume() {
    const id = remembered()
    if (!id) return
    try {
      const saved = await repertoireApi.getAutoBuild(id)
      receive(saved)
      shown.value = saved.games
      settings.count = saved.maxGames
      settings.perfs = [...saved.perfs]
      if (saved.status === 'applied') {
        step.value = 'done'
      } else if (saved.status === 'fetching') {
        step.value = 'scan'
        await readAll(++run)
      } else if (saved.selection?.families?.length) {
        selected.value = [...saved.selection.families]
        step.value = 'tree'
        evaluatePending()
      } else {
        toSelect()
      }
    } catch {
      remember(null)
    }
  }

  return {
    step,
    settings,
    build,
    feed,
    shown,
    found,
    error,
    waiting,
    busy,
    selected,
    phase,
    evaluationFailed,
    toast,
    ready,
    scanDone,
    trees,
    lines,
    start,
    toSelect,
    toggleFamily,
    toggleAll,
    generate,
    evaluatePending,
    removeLine,
    undo,
    chooseMove,
    apply,
    restart,
    resume
  }
}

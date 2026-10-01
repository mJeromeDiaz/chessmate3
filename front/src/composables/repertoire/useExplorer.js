import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue'
import { repertoireApi } from '@/services/api'

/** Stillness before asking (navigating with the arrows asks only for the position reached). */
export const QUERY_DELAY_MS = 300
/** Answers kept per panel (positions already seen are shown at once). */
const CACHE_SIZE = 200

/**
 * @typedef {'no_token'|'rate_limited'|'busy'|'down'|'too_many'|'invalid'|'offline'|'error'} FailureReason
 * @typedef {{reason: FailureReason, message: string, retryAfter: number|null}} QueryFailure
 * @typedef {{key: string, run: (signal: AbortSignal) => Promise<any>}} QueryRequest
 */

/**
 * Why the proxy could not answer, in words for the user. A 503 carries its reason in the
 * X-Lichess-Unavailable header (the API hides the detail of any 5xx).
 *
 * @param {any} error an axios error
 * @returns {QueryFailure}
 */
export function describeFailure(error) {
  const response = error?.response
  if (!response) {
    return {
      reason: 'offline',
      message: 'Le serveur est injoignable. Vérifiez votre connexion.',
      retryAfter: null
    }
  }
  const retryAfter = Number(response.headers?.['retry-after']) || null
  if (response.status === 503) {
    const reason = response.headers?.['x-lichess-unavailable'] ?? 'down'
    const messages = {
      no_token:
        'L’explorateur Lichess demande un compte Lichess : liez le vôtre dans votre profil.',
      rate_limited: `Lichess limite les requêtes pour le moment : réessayez dans ${retryAfter ?? 60} s.`,
      busy: 'Lichess est occupé : réessayez dans un instant.',
      down: 'Lichess ne répond pas pour le moment.'
    }
    return {
      reason: messages[reason] ? reason : 'down',
      message: messages[reason] ?? messages.down,
      retryAfter
    }
  }
  if (response.status === 429) {
    return {
      reason: 'too_many',
      message:
        'Trop de requêtes vers Lichess en peu de temps : patientez quelques minutes.',
      retryAfter
    }
  }
  if (response.status === 422) {
    return {
      reason: 'invalid',
      message: 'Position non reconnue.',
      retryAfter: null
    }
  }
  return {
    reason: 'error',
    message: 'Une erreur est survenue. Réessayez.',
    retryAfter: null
  }
}

/**
 * Asks the server for what `request` describes, once the request has stayed the same for
 * {@link QUERY_DELAY_MS}; answers are cached by key, an answer that arrives for a request no
 * longer current is dropped (and its HTTP call aborted). A null request asks nothing (panel
 * hidden, no position).
 *
 * @param {() => QueryRequest|null} request
 * @param {{delay?: number}} [options]
 */
export function useLichessQuery(request, { delay = QUERY_DELAY_MS } = {}) {
  /** @type {import('vue').ShallowRef<any>} */
  const data = shallowRef(null)
  const loading = ref(false)
  /** @type {import('vue').Ref<QueryFailure|null>} */
  const failure = ref(null)
  /** @type {Map<string, any>} */
  const cache = new Map()
  /** @type {ReturnType<typeof setTimeout>|null} */
  let timer = null
  /** @type {AbortController|null} */
  let controller = null
  /** @type {QueryRequest|null} */
  let current = null

  function cancel() {
    if (timer) clearTimeout(timer)
    timer = null
    controller?.abort()
    controller = null
  }

  /** @param {QueryRequest|null} next */
  function schedule(next, wait = delay) {
    cancel()
    current = next
    failure.value = null
    if (!next) {
      loading.value = false
      return
    }
    if (cache.has(next.key)) {
      data.value = cache.get(next.key)
      loading.value = false
      return
    }
    data.value = null
    loading.value = true
    timer = setTimeout(() => fetch(next), wait)
  }

  /** @param {QueryRequest} target */
  async function fetch(target) {
    timer = null
    const mine = new AbortController()
    controller = mine
    try {
      const answer = await target.run(mine.signal)
      if (current !== target) return
      cache.delete(target.key)
      cache.set(target.key, answer)
      if (cache.size > CACHE_SIZE) cache.delete(cache.keys().next().value)
      data.value = answer
    } catch (error) {
      if (current !== target || mine.signal.aborted) return
      failure.value = describeFailure(error)
    } finally {
      if (current === target) {
        loading.value = false
        controller = null
      }
    }
  }

  /** Asks again now (after a failure). */
  function retry() {
    if (current) {
      cache.delete(current.key)
      schedule(current, 0)
    }
  }

  const source = computed(request)
  watch(source, next => schedule(next), { immediate: true })
  onScopeDispose(cancel)

  return { data, loading, failure, retry }
}

const STORAGE_KEY = 'chessmate.explorer'

/**
 * The explorer's settings, remembered in this browser.
 *
 * @returns {{source: 'masters'|'lichess', speeds: string[], ratings: number[]}}
 */
function storedSettings() {
  const defaults = {
    source: 'masters',
    speeds: ['blitz', 'rapid', 'classical'],
    ratings: [1800, 2000, 2200, 2500]
  }
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')
    return saved && typeof saved === 'object'
      ? { ...defaults, ...saved }
      : defaults
  } catch {
    return defaults
  }
}

/**
 * Game statistics of the current position (Lichess opening explorer), for the editor's panel.
 *
 * @param {import('vue').Ref<string|null>} fen normalized FEN of the position
 * @param {import('vue').Ref<boolean>} enabled asks only while the panel is shown
 */
export function useExplorer(fen, enabled) {
  const initial = storedSettings()
  /** @type {import('vue').Ref<'masters'|'lichess'>} */
  const source = ref(initial.source)
  /** @type {import('vue').Ref<string[]>} */
  const speeds = ref(initial.speeds)
  /** @type {import('vue').Ref<number[]>} */
  const ratings = ref(initial.ratings)

  watch([source, speeds, ratings], () => {
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          source: source.value,
          speeds: speeds.value,
          ratings: ratings.value
        })
      )
    } catch {
      // Storage unavailable (private mode...): the settings last for this page only.
    }
  })

  const query = useLichessQuery(() => {
    if (!enabled.value || !fen.value) return null
    const position = fen.value
    const from = source.value
    const filters =
      from === 'lichess'
        ? {
            speeds: [...speeds.value].sort(),
            ratings: [...ratings.value].sort((a, b) => a - b)
          }
        : {}
    return {
      key: `${from}|${position}|${filters.speeds ?? ''}|${filters.ratings ?? ''}`,
      run: signal => repertoireApi.explorer(from, position, filters, signal)
    }
  })

  return { source, speeds, ratings, ...query }
}

/**
 * The Lichess cloud evaluation of the current position.
 *
 * @param {import('vue').Ref<string|null>} fen normalized FEN of the position
 * @param {import('vue').Ref<boolean>} enabled asks only while the panel is shown
 * @param {number} [lines]
 */
export function useCloudEval(fen, enabled, lines = 3) {
  return useLichessQuery(() => {
    if (!enabled.value || !fen.value) return null
    const position = fen.value
    return {
      key: `${lines}|${position}`,
      run: signal => repertoireApi.cloudEval(position, lines, signal)
    }
  })
}

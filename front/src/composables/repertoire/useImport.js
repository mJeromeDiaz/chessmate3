import { onScopeDispose, ref, shallowRef, watch } from 'vue'
import { repertoireApi } from '@/services/api'
import { describeFailure } from '@/composables/repertoire/useExplorer'
import {
  bulkChoices,
  defaultChoices,
  failureText
} from '@/utils/repertoireImport'

/** How often a worker's progress is asked for. */
export const POLL_MS = 1000
/** Stillness before the preview is asked again for another destination. */
const PREVIEW_DELAY_MS = 250

/**
 * @typedef {'source'|'analyzing'|'preview'|'applying'|'done'|'failed'} ImportPhase
 * @typedef {{mode: 'new'|'existing', name: string, color: 'white'|'black', repertoireId: string|null, baseVersion: number|null}} Destination
 */

/**
 * The code an API error carries: its detail for a 422 (the API words them as codes), the Lichess
 * reason of a 503.
 *
 * @param {any} error an axios error
 * @returns {{code: string|null, message: string}}
 */
export function importError(error) {
  const response = error?.response
  if (response?.status === 422) {
    const code = response.data?.detail ?? null
    if (typeof code === 'string' && /^[a-z_]+$/.test(code)) {
      return { code, message: failureText(code) }
    }
    const violations = response.data?.violations
    if (Array.isArray(violations) && violations.length) {
      return {
        code: 'invalid',
        message: 'Donnez un PGN, un fichier ou un lien d’étude Lichess.'
      }
    }
  }
  if (response?.status === 409) {
    return { code: 'stale', message: failureText('stale') }
  }
  if (response?.status === 404) {
    return {
      code: 'not_found',
      message: 'Cet import a expiré (24 h) ou n’existe plus : recommencez.'
    }
  }
  if (response?.status === 503 || response?.status === 429 || !response) {
    return { code: 'lichess', message: describeFailure(error).message }
  }
  return { code: null, message: 'L’import a échoué. Réessayez.' }
}

/**
 * The import page's state: send a PGN (text, file), an OpenBook backup or a study URL, wait for
 * the analysis, preview it against a destination (a new repertoire, or one of the user's), choose
 * the conflicts, apply, wait for a worker when it is big.
 *
 * The preview follows the choices: choosing the file's move in a position can reveal conflicts
 * further on (docs/REPERTOIRE.md, "Import").
 */
export function useImport() {
  /** @type {import('vue').Ref<ImportPhase>} */
  const phase = ref('source')
  /** @type {import('vue').ShallowRef<any>} the import as the API last described it */
  const current = shallowRef(null)
  /** @type {import('vue').Ref<{code: string|null, message: string}|null>} */
  const error = ref(null)
  const busy = ref(false)
  const previewLoading = ref(false)
  /** @type {import('vue').Ref<Destination>} */
  const destination = ref({
    mode: 'new',
    name: '',
    color: 'white',
    repertoireId: null,
    baseVersion: null
  })
  /** @type {import('vue').Ref<Record<string, string>>} normalized FEN => UCI */
  const choices = ref({})

  /** @type {ReturnType<typeof setTimeout>|null} */
  let timer = null
  let disposed = false
  onScopeDispose(() => {
    disposed = true
    stop()
  })

  function stop() {
    if (timer) clearTimeout(timer)
    timer = null
  }

  /** @param {() => Promise<void>} step */
  function later(step, ms) {
    stop()
    if (!disposed) timer = setTimeout(step, ms)
  }

  /**
   * @param {{pgn?: string, fileName?: string|null, studyUrl?: string}} payload
   */
  async function start(payload) {
    stop()
    busy.value = true
    error.value = null
    try {
      receive(await repertoireApi.createImport(payload))
    } catch (e) {
      error.value = importError(e)
      phase.value = 'source'
    } finally {
      busy.value = false
    }
  }

  /**
   * Follows the import's status: poll while a worker works on it.
   *
   * @param {any} data
   */
  function receive(data) {
    current.value = data
    switch (data.status) {
      case 'analyzing':
        phase.value = 'analyzing'
        later(poll, POLL_MS)
        break
      case 'applying':
        phase.value = 'applying'
        later(poll, POLL_MS)
        break
      case 'analyzed':
        if (phase.value === 'applying' && data.error) {
          // A worker refused the application: back to the preview, with the reason.
          error.value = { code: data.error, message: failureText(data.error) }
        }
        if (phase.value !== 'preview') {
          phase.value = 'preview'
          const suggested = data.suggestedColor ?? 'white'
          destination.value = {
            ...destination.value,
            name:
              destination.value.name ||
              data.suggestedName ||
              data.label ||
              'Import',
            color: suggested
          }
          choices.value = defaultChoices(data.preview?.conflicts ?? [])
        }
        break
      case 'done':
        phase.value = 'done'
        break
      default:
        phase.value = 'failed'
        error.value = {
          code: data.error,
          message: failureText(data.error, data.errorLine)
        }
    }
  }

  async function poll() {
    if (!current.value) return
    try {
      receive(await repertoireApi.getImport(current.value.id, query()))
    } catch (e) {
      error.value = importError(e)
      phase.value = 'failed'
    }
  }

  /** @returns {{repertoireId?: string, color?: 'white'|'black', choices?: Record<string, string>}} */
  function query() {
    const d = destination.value
    const target =
      d.mode === 'existing' && d.repertoireId
        ? { repertoireId: d.repertoireId }
        : { color: d.color }
    return Object.keys(choices.value).length
      ? { ...target, choices: choices.value }
      : target
  }

  /**
   * The preview for the destination and the choices made now (conflicts depend on both).
   *
   * @param {boolean} [keepChoices] false: another destination, the choices start over
   */
  async function refreshPreview(keepChoices = false) {
    if (phase.value !== 'preview' || !current.value) return
    if (!keepChoices) choices.value = {}
    previewLoading.value = true
    try {
      const data = await repertoireApi.getImport(current.value.id, query())
      current.value = data
      choices.value = {
        ...defaultChoices(data.preview?.conflicts ?? []),
        ...choices.value
      }
    } catch (e) {
      error.value = importError(e)
    } finally {
      previewLoading.value = false
    }
  }

  /**
   * The user's choices; the preview is asked again for them.
   *
   * @param {Record<string, string>} value normalized FEN => UCI
   */
  function setChoices(value) {
    choices.value = value
    later(() => refreshPreview(true), PREVIEW_DELAY_MS)
  }

  watch(
    () => [
      destination.value.mode,
      destination.value.color,
      destination.value.repertoireId
    ],
    () => {
      if (phase.value === 'preview') later(refreshPreview, PREVIEW_DELAY_MS)
    }
  )

  /**
   * Keeps the repertoire's moves, or takes the file's, everywhere: again on the conflicts each
   * round of choices reveals.
   *
   * @param {'existing'|'file'} side
   */
  async function chooseAll(side) {
    stop()
    for (let round = 0; round < 20; round++) {
      const conflicts = current.value?.preview?.conflicts ?? []
      const next = { ...choices.value, ...bulkChoices(conflicts, side) }
      const changed = conflicts.some(c => choices.value[c.fen] !== next[c.fen])
      if (round > 0 && !changed) break
      choices.value = next
      await refreshPreview(true)
    }
  }

  async function apply() {
    if (!current.value) return
    const d = destination.value
    busy.value = true
    error.value = null
    try {
      const payload =
        d.mode === 'existing'
          ? {
              repertoireId: d.repertoireId,
              ...(d.baseVersion === null ? {} : { baseVersion: d.baseVersion }),
              choices: choices.value
            }
          : { name: d.name.trim(), color: d.color, choices: choices.value }
      receive(await repertoireApi.applyImport(current.value.id, payload))
    } catch (e) {
      error.value = importError(e)
      if (error.value.code === 'stale') await refreshPreview()
    } finally {
      busy.value = false
    }
  }

  function reset() {
    stop()
    phase.value = 'source'
    current.value = null
    error.value = null
    choices.value = {}
    destination.value = {
      mode: 'new',
      name: '',
      color: 'white',
      repertoireId: null,
      baseVersion: null
    }
  }

  return {
    phase,
    current,
    error,
    busy,
    previewLoading,
    destination,
    choices,
    start,
    apply,
    chooseAll,
    refreshPreview,
    setChoices,
    reset
  }
}

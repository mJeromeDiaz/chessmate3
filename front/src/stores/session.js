import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import {
  coordinatesApi,
  planApi,
  repertoireApi,
  woodpeckerApi
} from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePuzzleStore } from '@/stores/puzzle'
import { apiErrorMessage } from '@/utils/apiError'
import {
  MODULES_BY_ID,
  defaultValues,
  fromStep,
  itemIssue,
  move as moveItem,
  sessionMinutes,
  toStep,
  withFixedDuration
} from '@/utils/session/catalog'
import {
  normalizeSettings,
  settingsIssue,
  settingsPayload
} from '@/utils/session/plans'

/** Where the draft is kept between visits (this browser only; wiped on sign-out). */
export const DRAFT_KEY = 'dontstayrooky.session.draft'

/**
 * @typedef {import('@/utils/session/catalog').SessionItem} SessionItem
 * @typedef {import('@/utils/session/catalog').SubjectContext} SubjectContext
 *
 * @typedef {object} Draft
 * @property {string} title
 * @property {string} description
 * @property {SessionItem[]} items
 * @property {import('@/utils/session/plans').SessionSettings} settings
 */

/**
 * A deep copy of settings (plain JSON data; structuredClone rejects Vue's reactive proxies).
 *
 * @param {Record<string, any>} values
 * @returns {Record<string, any>}
 */
function clone(values) {
  return JSON.parse(JSON.stringify(values))
}

/**
 * A stored item's settings on its module's current fields: settings the module no longer has are
 * dropped, new ones get their default (a draft may predate a catalogue change).
 *
 * @param {import('@/utils/session/catalog').Module} module
 * @param {Record<string, any>} values
 * @returns {Record<string, any>}
 */
function normalize(module, values) {
  const defaults = defaultValues(module)
  return Object.fromEntries(
    Object.entries(defaults).map(([key, def]) => {
      const v = values[key]
      const ok =
        v !== undefined &&
        (Array.isArray(def) ? Array.isArray(v) : typeof v === typeof def)
      return [key, ok ? v : def]
    })
  )
}

/** @returns {Draft} */
function emptyDraft() {
  return {
    title: '',
    description: '',
    items: [],
    settings: normalizeSettings(null)
  }
}

/**
 * The stored draft, keeping only items of modules that still exist and can be added.
 *
 * @returns {Draft}
 */
function readDraft() {
  try {
    const saved = JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null')
    if (!saved || !Array.isArray(saved.items)) return emptyDraft()
    return {
      title: String(saved.title ?? ''),
      description: String(saved.description ?? ''),
      settings: normalizeSettings(saved.settings),
      items: saved.items
        .filter(
          (/** @type {any} */ item) =>
            Number.isInteger(item?.uid) &&
            MODULES_BY_ID[item.moduleId]?.available &&
            item.values &&
            typeof item.values === 'object'
        )
        .map((/** @type {SessionItem} */ item) => ({
          ...item,
          values: normalize(MODULES_BY_ID[item.moduleId], item.values)
        }))
    }
  } catch {
    return emptyDraft()
  }
}

/**
 * The training session being composed (design "Session Builder"): a title, a goal, an ordered
 * program of configured modules and its settings (repetition, reminder...). A new session is a
 * draft in localStorage until saved; a saved one (`planId`) is edited here without touching that
 * draft. The modules' subjects are the user's real data: repertoires, ongoing light set, puzzle
 * themes (docs/TRAINING.md, saved sessions).
 */
export const useSessionStore = defineStore('session', () => {
  const draft = readDraft()
  const title = ref(draft.title)
  const description = ref(draft.description)
  /** @type {import('vue').Ref<SessionItem[]>} */
  const items = ref(draft.items)
  const settings = ref(draft.settings)
  /** The saved session being edited; null for a new one (the draft). */
  const planId = ref(/** @type {string|null} */ (null))
  let lastUid = Math.max(0, ...draft.items.map(i => i.uid))

  const totalMinutes = computed(() => sessionMinutes(items.value))

  const puzzles = usePuzzleStore()
  const subjectsLoaded = ref(false)
  const subjectsLoading = ref(false)
  const subjectsError = ref('')
  /** @type {import('vue').Ref<SubjectContext['repertoires']>} */
  const repertoires = ref([])
  /** @type {import('vue').Ref<SubjectContext['lightSet']>} */
  const lightSet = ref(null)
  /** @type {import('vue').Ref<Record<string, number>>} */
  const fixedMinutes = ref({})

  /** @type {import('vue').ComputedRef<SubjectContext>} */
  const context = computed(() => ({
    loaded: subjectsLoaded.value,
    repertoires: repertoires.value,
    lightSet: lightSet.value,
    themeLabel: key => puzzles.themeLabel(key),
    fixedMinutes: fixedMinutes.value
  }))

  /**
   * Fetches what the modules play on: the user's repertoires, their ongoing light set, the
   * puzzle themes and the length of a coordinates series (the program's items follow it). Fetched
   * again on each call (the user may have created one meanwhile).
   */
  async function fetchSubjects() {
    subjectsLoading.value = true
    subjectsError.value = ''
    try {
      const [repertoireList, sets, , coordinates] = await Promise.all([
        repertoireApi.list(),
        woodpeckerApi.sets(),
        puzzles.fetchThemes(),
        coordinatesApi.overview()
      ])
      fixedMinutes.value = {
        coordonnees: Math.round(coordinates.rules.seriesSeconds / 60)
      }
      items.value = items.value.map(item => {
        const module = MODULES_BY_ID[item.moduleId]
        if (!module) return item
        const values = withFixedDuration(module, item.values, context.value)
        return values === item.values ? item : { ...item, values }
      })
      repertoires.value = repertoireList.map((/** @type {any} */ r) => ({
        id: r.id,
        name: r.name,
        color: r.color
      }))
      const light = sets.find(
        (/** @type {any} */ s) =>
          s.mode === 'light' &&
          !s.archived &&
          (s.status === 'active' || s.status === 'paused')
      )
      lightSet.value = light
        ? {
            id: light.id,
            name: light.name,
            status: light.status,
            puzzleCount: light.puzzleCount,
            runCount: light.runs?.length ?? 0
          }
        : null
      subjectsLoaded.value = true
    } catch (e) {
      subjectsError.value = apiErrorMessage(e)
    } finally {
      subjectsLoading.value = false
    }
  }

  watch(
    [title, description, items, settings],
    () => {
      // A saved session is edited in memory: the draft of a new one stays as it was.
      if (planId.value) return
      try {
        if (!title.value && !description.value && !items.value.length) {
          localStorage.removeItem(DRAFT_KEY)
          return
        }
        localStorage.setItem(
          DRAFT_KEY,
          JSON.stringify({
            title: title.value,
            description: description.value,
            items: items.value,
            settings: settings.value
          })
        )
      } catch {
        // Storage full or disabled: the draft lives for this visit only.
      }
    },
    { deep: true }
  )

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) {
        reset()
        repertoires.value = []
        lightSet.value = null
        fixedMinutes.value = {}
        subjectsLoaded.value = false
      }
    }
  )

  const settingsError = computed(() => settingsIssue(settings.value))

  /** Every module can be played, as far as the user's data tells, and the settings hold. */
  const canSave = computed(
    () =>
      items.value.length > 0 &&
      context.value.loaded &&
      !settingsError.value &&
      items.value.every(
        item =>
          !itemIssue(MODULES_BY_ID[item.moduleId], item.values, context.value)
      )
  )

  /**
   * Saves the session (creates it, or updates the one being edited). A new one empties the draft
   * (it now lives in the list of saved sessions) and is then edited as a saved one.
   *
   * @returns {Promise<import('@/utils/session/plans').Plan>}
   */
  async function save() {
    const payload = {
      title: title.value.trim(),
      description: description.value.trim(),
      steps: items.value.map(toStep),
      ...settingsPayload(settings.value)
    }
    if (planId.value) return planApi.update(planId.value, payload)
    const plan = await planApi.create(payload)
    reset()
    edit(plan)
    return plan
  }

  /**
   * Edits a saved session (the draft of a new one is left aside).
   *
   * @param {import('@/utils/session/plans').Plan} plan
   */
  function edit(plan) {
    planId.value = plan.id
    title.value = plan.title
    description.value = plan.description
    settings.value = normalizeSettings({
      ...plan,
      time: plan.time ?? undefined,
      weekdays: plan.weekdays.length ? plan.weekdays : undefined
    })
    items.value = plan.steps.flatMap(step => {
      const item = fromStep(step)
      return item ? [{ uid: ++lastUid, ...item }] : []
    })
  }

  /** Back to the draft of a new session (after editing a saved one). */
  function startNew() {
    if (!planId.value) return
    planId.value = null
    const stored = readDraft()
    title.value = stored.title
    description.value = stored.description
    items.value = stored.items
    settings.value = stored.settings
    lastUid = Math.max(lastUid, ...stored.items.map(i => i.uid))
  }

  /**
   * Adds a configured module at the end of the program.
   *
   * @param {string} moduleId
   * @param {Record<string, any>} [values] defaults to the module's defaults
   * @returns {SessionItem|null} null for an unknown or not yet available module
   */
  function add(moduleId, values) {
    const module = MODULES_BY_ID[moduleId]
    if (!module?.available) return null
    const item = {
      uid: ++lastUid,
      moduleId,
      values: clone(
        withFixedDuration(
          module,
          values ?? defaultValues(module),
          context.value
        )
      )
    }
    items.value = [...items.value, item]
    return item
  }

  /**
   * @param {number} uid
   * @param {Record<string, any>} values
   */
  function update(uid, values) {
    items.value = items.value.map(item =>
      item.uid === uid ? { ...item, values: clone(values) } : item
    )
  }

  /** @param {number} uid */
  function remove(uid) {
    items.value = items.value.filter(item => item.uid !== uid)
  }

  /**
   * @param {number} from index
   * @param {number} to index
   */
  function move(from, to) {
    items.value = moveItem(items.value, from, to)
  }

  /** Empties the draft (and its stored copy). */
  function reset() {
    planId.value = null
    const empty = emptyDraft()
    title.value = empty.title
    description.value = empty.description
    items.value = empty.items
    settings.value = empty.settings
    try {
      localStorage.removeItem(DRAFT_KEY)
    } catch {
      // Nothing stored.
    }
  }

  return {
    title,
    description,
    items,
    totalMinutes,
    context,
    subjectsLoading,
    subjectsError,
    fetchSubjects,
    settings,
    settingsError,
    planId,
    canSave,
    save,
    edit,
    startNew,
    add,
    update,
    remove,
    move,
    reset
  }
})

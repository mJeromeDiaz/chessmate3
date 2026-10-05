import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import {
  dashboardApi,
  puzzleApi,
  repertoireApi,
  woodpeckerApi
} from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { describeFailure } from '@/composables/repertoire/useExplorer'
import { DEFAULT_PERIOD, validPeriod } from '@/utils/dashboard/stats'

/**
 * @typedef {import('@/utils/dashboard/heatmap').ActivityDay} ActivityDay
 * @typedef {import('@/utils/dashboard/curve').RatingPoint} RatingPoint
 *
 * @typedef {object} Activity
 * @property {string} timezone
 * @property {string} from
 * @property {string} today
 * @property {ActivityDay[]} days
 * @property {Record<string, {count: number, successCount: number, durationMs: number}>} totals by exercise type
 *
 * @typedef {{from: string, today: string, points: RatingPoint[]}} RatingHistory
 *
 * @typedef {object} LichessHistory
 * @property {boolean} linked
 * @property {string|null} username
 * @property {string} from
 * @property {string} today
 * @property {{blitz: RatingPoint[], rapid: RatingPoint[], classical: RatingPoint[]}} perfs
 *
 * @typedef {'activity'|'rating'|'puzzle'|'woodpecker'|'repertoire'} Section
 *
 * @typedef {object} ThemeRow
 * @property {string} key
 * @property {number} attempts
 * @property {number} successCount
 * @property {number} successRate 0..1
 *
 * @typedef {object} Themes
 * @property {string} from
 * @property {string} today
 * @property {number} attempts rated puzzles of the period
 * @property {number} successCount solved without help
 * @property {number} minAttempts
 * @property {ThemeRow[]} themes
 * @property {ThemeRow[]} strong
 * @property {ThemeRow[]} weak
 *
 * @typedef {object} Training
 * @property {string} from
 * @property {string} today
 * @property {import('@/utils/dashboard/stats').TrainingWeek[]} weeks
 * @property {Record<string, {count: number, durationMs: number}>} totals by module
 * @property {{closed: number, completed: number, abandoned: number, expired: number, playedMs: number, averageMs: number|null}} sessions
 *
 * @typedef {object} Fragile
 * @property {string} repertoireId
 * @property {string} repertoireName
 * @property {'white'|'black'} color
 * @property {string} segmentId
 * @property {{opening: {eco: string, name: string}|null, move: string|null}|null} label
 * @property {number} tests
 * @property {number} recentFailureRate
 *
 * @typedef {object} RepertoireHealth
 * @property {number} repertoires
 * @property {{total: number, new: number, due: number}} cards
 * @property {{total: number, succeeded: number, successRate: number|null}} tests
 * @property {Fragile[]} fragile
 *
 * @typedef {'training'|'themes'|'repertoire'} StatsSection
 */

/** Heatmap: 12 weeks. */
export const ACTIVITY_DAYS = 84
/** Rating curves. */
export const CURVE_DAYS = 90
/** The home page's weak-theme tip. */
export const TIP_DAYS = 30
/** The statistics page's period, remembered in this browser. */
const PERIOD_KEY = 'cm.stats.days'

/** @returns {number} */
function storedPeriod() {
  try {
    return validPeriod(localStorage.getItem(PERIOD_KEY))
  } catch {
    return DEFAULT_PERIOD
  }
}

/**
 * The dashboard's data (docs/DASHBOARD.md). Each section loads and fails on its own, so one
 * broken card never blanks the page. Lichess is only asked when its tab is opened.
 */
export const useDashboardStore = defineStore('dashboard', () => {
  /** @type {import('vue').Ref<Activity|null>} */
  const activity = ref(null)
  /** @type {import('vue').Ref<RatingHistory|null>} */
  const rating = ref(null)
  /** @type {import('vue').Ref<any>} the puzzle rating (/puzzles/rating; 1500 provisional before any rated puzzle) */
  const puzzleRating = ref(null)
  /** @type {import('vue').Ref<import('@/stores/woodpecker').WoodpeckerSet[]>} */
  const sets = ref([])
  /** @type {import('vue').Ref<any>} repertoire overview (/repertoires/stats) */
  const repertoires = ref(null)
  /** @type {import('vue').Ref<LichessHistory|null>} */
  const lichess = ref(null)

  const loading = ref(false)
  const loaded = ref(false)
  /** @type {import('vue').Ref<Partial<Record<Section, string>>>} */
  const errors = ref({})
  const lichessLoading = ref(false)
  /** @type {import('vue').Ref<string|null>} */
  const lichessError = ref(null)

  /** Statistics page (lot B): the period in local days and its three blocks. */
  const period = ref(storedPeriod())
  /** @type {import('vue').Ref<Training|null>} */
  const training = ref(null)
  /** @type {import('vue').Ref<Themes|null>} */
  const themes = ref(null)
  /** @type {import('vue').Ref<RepertoireHealth|null>} */
  const health = ref(null)
  const statsLoading = ref(false)
  /** @type {import('vue').Ref<Partial<Record<StatsSection, string>>>} */
  const statsErrors = ref({})
  /** Only the answers of the latest period are kept. */
  let statsRequest = 0
  /** @type {import('vue').Ref<Themes|null>} the home page's tip, over {@link TIP_DAYS} days */
  const tipThemes = ref(null)

  /** Nothing played yet: the page welcomes the user instead of drawing empty charts. */
  const isNewUser = computed(
    () =>
      loaded.value &&
      activity.value !== null &&
      Object.keys(activity.value.totals).length === 0 &&
      !(rating.value?.points.length ?? 0) &&
      !sets.value.length &&
      !(repertoires.value?.repertoires?.length ?? 0)
  )

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) reset()
    }
  )

  function reset() {
    activity.value = null
    rating.value = null
    puzzleRating.value = null
    sets.value = []
    repertoires.value = null
    lichess.value = null
    loading.value = false
    loaded.value = false
    errors.value = {}
    lichessLoading.value = false
    lichessError.value = null
    training.value = null
    themes.value = null
    health.value = null
    statsLoading.value = false
    statsErrors.value = {}
    statsRequest++
    tipThemes.value = null
  }

  /**
   * Loads every section at once; a failed one keeps its error message.
   *
   * @returns {Promise<void>}
   */
  async function load() {
    loading.value = true
    errors.value = {}
    const message = 'Impossible de charger ces données. Réessayez.'
    /** @type {[Section, Promise<any>, (value: any) => void][]} */
    const sections = [
      [
        'activity',
        dashboardApi.activity(ACTIVITY_DAYS),
        v => (activity.value = v)
      ],
      [
        'rating',
        dashboardApi.ratingHistory(CURVE_DAYS),
        v => (rating.value = v)
      ],
      ['puzzle', puzzleApi.rating(), v => (puzzleRating.value = v)],
      ['woodpecker', woodpeckerApi.sets(), v => (sets.value = v)],
      ['repertoire', repertoireApi.overview(), v => (repertoires.value = v)]
    ]
    const results = await Promise.allSettled(sections.map(([, p]) => p))
    results.forEach((result, i) => {
      const [section, , assign] = sections[i]
      if (result.status === 'fulfilled') assign(result.value)
      else errors.value = { ...errors.value, [section]: message }
    })
    loading.value = false
    loaded.value = true
  }

  /**
   * The linked Lichess account's ratings, asked once per visit (cached 6 h by the server), again
   * after a failure.
   *
   * @returns {Promise<void>}
   */
  async function loadLichess() {
    if (lichessLoading.value || lichess.value) return
    lichessLoading.value = true
    lichessError.value = null
    try {
      lichess.value = await dashboardApi.lichessRatingHistory(CURVE_DAYS)
    } catch (error) {
      lichessError.value = describeFailure(error).message
    } finally {
      lichessLoading.value = false
    }
  }

  /**
   * Loads the statistics page's blocks for a period (remembered in this browser); a failed block
   * keeps its error message. A period chosen meanwhile wins over this one.
   *
   * @param {number} [days]
   * @returns {Promise<void>}
   */
  async function loadStats(days = period.value) {
    period.value = validPeriod(days)
    try {
      localStorage.setItem(PERIOD_KEY, String(period.value))
    } catch {
      // Storage unavailable (private window): the period is not remembered.
    }
    const turn = ++statsRequest
    statsLoading.value = true
    statsErrors.value = {}
    const message = 'Impossible de charger ces données. Réessayez.'
    /** @type {[StatsSection, Promise<any>, (value: any) => void][]} */
    const sections = [
      [
        'training',
        dashboardApi.training(period.value),
        v => (training.value = v)
      ],
      ['themes', dashboardApi.themes(period.value), v => (themes.value = v)],
      [
        'repertoire',
        dashboardApi.repertoire(period.value),
        v => (health.value = v)
      ]
    ]
    const results = await Promise.allSettled(sections.map(([, p]) => p))
    if (turn !== statsRequest) return
    results.forEach((result, i) => {
      const [section, , assign] = sections[i]
      if (result.status === 'fulfilled') assign(result.value)
      else {
        assign(null)
        statsErrors.value = { ...statsErrors.value, [section]: message }
      }
    })
    statsLoading.value = false
  }

  /**
   * The home page's tip: the weakest theme of the last {@link TIP_DAYS} days. A failure only hides
   * the tip.
   *
   * @returns {Promise<void>}
   */
  async function loadTip() {
    try {
      tipThemes.value = await dashboardApi.themes(TIP_DAYS)
    } catch {
      tipThemes.value = null
    }
  }

  return {
    period,
    training,
    themes,
    health,
    statsLoading,
    statsErrors,
    tipThemes,
    loadStats,
    loadTip,
    activity,
    rating,
    puzzleRating,
    sets,
    repertoires,
    lichess,
    loading,
    loaded,
    errors,
    lichessLoading,
    lichessError,
    isNewUser,
    load,
    loadLichess,
    reset
  }
})

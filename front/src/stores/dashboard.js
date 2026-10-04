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
 */

/** Heatmap: 12 weeks. */
export const ACTIVITY_DAYS = 84
/** Rating curves. */
export const CURVE_DAYS = 90

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

  return {
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

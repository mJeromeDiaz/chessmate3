import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { puzzleApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { themeText } from '@/utils/puzzle'

/**
 * @typedef {object} Rating
 * @property {number} rating
 * @property {number} deviation
 * @property {boolean} provisional
 * @property {number} ratedCount
 * @property {'default'|'lichess'} source
 * @property {boolean} lichessImportAvailable
 *
 * @typedef {object} Theme
 * @property {string} key
 * @property {string} category
 * @property {string} categoryLabelFr
 * @property {string} [categoryLabelEn]
 * @property {string} labelFr
 * @property {string} [labelEn]
 * @property {string} descriptionFr
 * @property {string} [descriptionEn]
 * @property {number} puzzleCount
 *
 * @typedef {object} Attempt
 * @property {string} id
 * @property {'pending'|'solved'|'failed'} status
 * @property {boolean} rated
 * @property {number|null} [ratingBefore]
 * @property {number|null} ratingAfter
 * @property {number|null} ratingDelta
 * @property {number|null} [xp] submission only: the XP gained
 * @property {boolean} [pardoned] missed, then pardoned with the store's pardon (the rating came back)
 * @property {number|null} [pardons] pardon only: the pardons left
 * @property {{id: string, fen: string, moves: string[], playerColor: 'white'|'black', rating: number, themes: string[], gameUrl: string}} puzzle
 */

/**
 * Puzzle state shared by the play, themes and history pages: the user's rating, the theme list
 * (fetched once), the selection filters and the attempt being played.
 */
export const usePuzzleStore = defineStore('puzzle', () => {
  /** @type {import('vue').Ref<Rating|null>} */
  const rating = ref(null)
  /** @type {import('vue').Ref<Theme[]>} */
  const themes = ref([])
  /** Selected theme keys (OR) and relative difficulty for the next puzzles. */
  const filters = ref({
    themes: /** @type {string[]} */ ([]),
    difficulty: 'normal'
  })
  /** @type {import('vue').Ref<Attempt|null>} */
  const attempt = ref(null)
  /** @type {import('vue').Ref<Attempt|null>} The server's verdict on the current attempt. */
  const result = ref(null)

  /** @type {Promise<Theme[]>|null} */
  let themesRequest = null

  // Signed out (logout or expired session): nothing of this user may remain for the next one.
  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) reset()
    }
  )

  /** Forgets the user-specific state (the theme list is the same for everyone). */
  function reset() {
    rating.value = null
    attempt.value = null
    result.value = null
    filters.value = { themes: [], difficulty: 'normal' }
  }

  async function fetchRating() {
    rating.value = await puzzleApi.rating()
    return rating.value
  }

  /** Themes change only after an import: fetched once per session. */
  function fetchThemes() {
    themesRequest ??= puzzleApi.themes().then(
      list => (themes.value = list),
      error => {
        themesRequest = null
        throw error
      }
    )
    return themesRequest
  }

  /** @param {string} key */
  function themeLabel(key) {
    const theme = themes.value.find(t => t.key === key)
    return theme ? themeText(theme, 'label') : key
  }

  /** Starts (or resumes) the next rated puzzle with the current filters. */
  async function next() {
    result.value = null
    attempt.value = await puzzleApi.start({
      themes: filters.value.themes,
      difficulty: filters.value.difficulty
    })
    return attempt.value
  }

  /** @param {string} puzzleId Lichess id of a puzzle from the history */
  async function replay(puzzleId) {
    result.value = null
    attempt.value = await puzzleApi.replay(puzzleId)
    return attempt.value
  }

  /**
   * Sends the move log of the current attempt; refreshes the rating after a rated one.
   *
   * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
   */
  async function submit(report) {
    if (!attempt.value) throw new Error('No attempt in progress')
    const answer = await puzzleApi.submit(attempt.value.id, report)
    result.value = answer
    // The server's rating (deviation, provisional flag...) rather than a local estimate.
    if (answer.rated) await fetchRating()
    return answer
  }

  /**
   * The store's pardon on the rated puzzle just missed (docs/PUZZLES.md): the rating comes back,
   * the verdict stays a miss with no rating change.
   *
   * @returns {Promise<number>} the pardons left
   */
  async function pardon() {
    if (!result.value) throw new Error('No result to pardon')
    const answer = await puzzleApi.pardon(result.value.id)
    result.value = {
      ...answer,
      xp: result.value.xp,
      ratingAfter: answer.ratingBefore,
      ratingDelta: 0
    }
    await fetchRating()
    return answer.pardons ?? 0
  }

  async function importLichessRating() {
    rating.value = await puzzleApi.importLichessRating()
    return rating.value
  }

  /** @param {string[]} keys */
  function setThemes(keys) {
    filters.value = { ...filters.value, themes: [...keys] }
  }

  /** @param {'easier'|'normal'|'harder'} difficulty */
  function setDifficulty(difficulty) {
    filters.value = { ...filters.value, difficulty }
  }

  return {
    reset,
    rating,
    themes,
    filters,
    attempt,
    result,
    fetchRating,
    fetchThemes,
    themeLabel,
    next,
    replay,
    submit,
    pardon,
    importLichessRating,
    setThemes,
    setDifficulty
  }
})

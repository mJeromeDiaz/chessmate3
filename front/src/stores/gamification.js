import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { gamificationApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { localToday } from '@/utils/streak'

/**
 * The gamification (docs/GAMIFICATION.md): summary (level, XP, streaks), trophies and the weekly
 * quest. Each part loads and fails on its own; a failure keeps the part empty (its block hides
 * or says so). Also the streak celebration (shown once a day, after the day's first exercise) and
 * the "streak in danger" reminder settings.
 *
 * @typedef {'summary'|'trophies'|'quest'} Part
 * @typedef {{enabled: boolean, hour: number, email: boolean}} ReminderSettings
 */
export const useGamificationStore = defineStore('gamification', () => {
  /** @type {import('vue').Ref<import('@/utils/gamification').Summary|null>} */
  const summary = ref(null)
  /** @type {import('vue').Ref<import('@/utils/gamification').Trophy[]>} */
  const trophies = ref([])
  /** @type {import('vue').Ref<import('@/utils/gamification').Quest|null>} */
  const quest = ref(null)
  /** @type {import('vue').Ref<Partial<Record<Part, string>>>} */
  const errors = ref({})
  /** @type {import('vue').Ref<import('@/utils/streak').StreakNotice|null>} the announcement on screen */
  const celebration = ref(null)
  /** @type {import('vue').Ref<ReminderSettings|null>} */
  const reminder = ref(null)

  /** The local day whose announcement was shown: no need to ask again until tomorrow. */
  let shownOn = ''
  /** @type {Promise<void>|null} the check under way (or the celebration on screen) */
  let checking = null
  /** @type {(() => void)[]} */
  let onClosed = []

  watch(
    () => useAuthStore().isAuthenticated,
    signedIn => {
      if (!signedIn) reset()
    }
  )

  function reset() {
    summary.value = null
    trophies.value = []
    quest.value = null
    errors.value = {}
    celebration.value = null
    reminder.value = null
    shownOn = ''
    checking = null
    onClosed.splice(0).forEach(resolve => resolve())
  }

  /**
   * Loads the parts asked (all by default).
   *
   * @param {Part[]} [parts]
   * @returns {Promise<void>}
   */
  async function load(parts = ['summary', 'trophies', 'quest']) {
    const message = 'Impossible de charger ces données. Réessayez.'
    /** @type {Record<Part, () => Promise<void>>} */
    const loaders = {
      summary: async () => {
        summary.value = await gamificationApi.summary()
      },
      trophies: async () => {
        trophies.value = await gamificationApi.trophies()
      },
      quest: async () => {
        quest.value = await gamificationApi.quest()
      }
    }
    const results = await Promise.allSettled(parts.map(part => loaders[part]()))
    const next = { ...errors.value }
    results.forEach((result, i) => {
      if (result.status === 'rejected') next[parts[i]] = message
      else delete next[parts[i]]
    })
    errors.value = next
  }

  /** The user's local day, as the server counts it. */
  function today() {
    return localToday(useAuthStore().profile?.timezone)
  }

  /**
   * Shows today's streak announcement if there is one not shown yet (docs/GAMIFICATION.md,
   * "Annonce de la série"): after a free exercise's result, at the end of a timed run, on the
   * dashboard. Resolves once the player closed it, at once when there is none; never rejects
   * (a failure only skips the celebration).
   *
   * @param {{afterExercise?: boolean}} [options] afterExercise (default): an exercise of today
   *   just ended, so its announcement was written with it; none pending means it was already
   *   shown (another tab, another device), and nothing is asked again today
   * @returns {Promise<void>}
   */
  function celebrateStreak({ afterExercise = true } = {}) {
    if (shownOn === today()) return Promise.resolve()
    if (checking) return checking
    checking = gamificationApi
      .streakNotice()
      .then(notice => {
        if (!notice?.pending) {
          if (afterExercise) shownOn = today()
          return
        }
        celebration.value = notice
        return new Promise(resolve => onClosed.push(resolve))
      })
      .catch(() => {})
      .finally(() => {
        checking = null
      })
    return checking
  }

  /** "Continuer": the announcement is shown; the streak figures follow without a reload. */
  function closeCelebration() {
    const notice = celebration.value
    if (notice) {
      shownOn = notice.localDate
      gamificationApi.acknowledgeStreak().catch(() => {})
      if (summary.value) {
        const streak = summary.value.streak
        summary.value = {
          ...summary.value,
          streak: {
            ...streak,
            current: notice.streak,
            best: Math.max(streak.best, notice.streak),
            playedToday: true,
            week: notice.week,
            nextMilestone: notice.nextMilestone
          },
          today: { ...summary.value.today, date: notice.localDate }
        }
      }
      if (notice.badge && trophies.value.length) load(['trophies'])
    }
    celebration.value = null
    onClosed.splice(0).forEach(resolve => resolve())
  }

  /**
   * The summary of a past day is stale (the tab stayed open overnight): reload it.
   *
   * @returns {Promise<void>}
   */
  async function refreshIfStale() {
    if (!summary.value || summary.value.today?.date !== today())
      await load(['summary'])
  }

  async function loadReminder() {
    reminder.value = await gamificationApi.streakReminder()
  }

  /** @param {ReminderSettings} settings */
  async function saveReminder(settings) {
    reminder.value = await gamificationApi.saveStreakReminder(settings)
  }

  return {
    summary,
    trophies,
    quest,
    errors,
    celebration,
    reminder,
    load,
    reset,
    celebrateStreak,
    closeCelebration,
    refreshIfStale,
    loadReminder,
    saveReminder
  }
})

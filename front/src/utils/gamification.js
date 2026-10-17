import { profImage } from '@/utils/prof/images'
import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { PUZZLE_STREAK_BADGES } from '@/utils/puzzleStreak'
import { STREAK_BADGES } from '@/utils/streak'
// `tr`: the trophy callbacks below name their trophy `t`.
import { intlLocale, t, t as tr } from '@/i18n'

/**
 * Display helpers of the gamification (docs/GAMIFICATION.md): level bar, trophies, weekly quest.
 * Pure, so that they can be tested alone. The API gives the figures; the names, icons and colours
 * live here.
 *
 * @typedef {{xp: number, level: number, xpInLevel: number, xpForNext: number}} ModuleLevel
 *
 * @typedef {object} Summary
 * @property {number} xp
 * @property {number} level
 * @property {number} xpInLevel
 * @property {number} xpForNext
 * @property {string} rank
 * @property {{rank: string, level: number}|null} nextRank
 * @property {Record<string, ModuleLevel>} modules by API module (puzzles, woodpecker, repertoire, free)
 * @property {{current: number, best: number, playedToday: boolean, week: boolean[], frozenWeek?: boolean[], protected?: boolean, freezeDays?: number, nextMilestone: number|null}} streak
 *   week: the active days of the local week, Monday first; frozenWeek: its days covered by a freeze;
 *   protected: alive thanks to the freezes in stock; freezeDays: the days they still cover (docs/SHOP.md)
 * @property {{date: string, exerciseXp: number, cap: number}} today date: the user's local day (Y-m-d)
 *
 * @typedef {object} Trophy
 * @property {string} key
 * @property {number} goal
 * @property {number} current
 * @property {boolean} unlocked
 * @property {string|null} unlockedAt
 * @property {number|null} ratio
 *
 * @typedef {object} Quest
 * @property {string} id
 * @property {'rated_puzzles'|'weak_theme'|'sessions'|'woodpecker'|'repertoire'|'active_days'} template
 * @property {string|null} theme
 * @property {string|null} module
 * @property {number} goal
 * @property {number} current
 * @property {number} reward
 * @property {boolean} completed
 * @property {string|null} completedAt
 * @property {string} weekStart
 * @property {string} weekEnd
 */

/** Numbers in the current language (docs/I18N.md). */
const number = { format: n => new Intl.NumberFormat(intlLocale()).format(n) }

/**
 * The name of a rank, sent by the API as a key ("tactician").
 *
 * @param {string} key
 */
export function rankLabel(key) {
  return t(`gamification.ranks.${key}`)
}

/**
 * The level bar: "Niveau 12 · Tacticien", "2 340 / 3 000 XP", what is left before the next rank.
 *
 * @param {Summary} summary
 */
export function levelBar(summary) {
  const left = Math.max(0, summary.xpForNext - summary.xpInLevel)
  return {
    level: summary.level,
    rank: rankLabel(summary.rank),
    percent:
      summary.xpForNext > 0
        ? Math.min(100, (summary.xpInLevel / summary.xpForNext) * 100)
        : 0,
    xpLabel: t('gamification.level.xp', {
      xp: number.format(summary.xpInLevel),
      next: number.format(summary.xpForNext)
    }),
    next: summary.nextRank
      ? t('gamification.level.nextRank', {
          left: number.format(left),
          level: summary.level + 1,
          rank: rankLabel(summary.nextRank.rank),
          rankLevel: summary.nextRank.level
        })
      : t('gamification.level.next', {
          left: number.format(left),
          level: summary.level + 1
        })
  }
}

/**
 * The level of a module of the catalogue (dashboard rows), null for one the API does not know.
 *
 * @param {Summary|null} summary
 * @param {string} catalogId e.g. "puzzles", "libre", "finales"
 * @returns {number|null}
 */
export function moduleLevel(summary, catalogId) {
  const apiModule = Object.entries(CATALOG_MODULES).find(
    ([, catalog]) => catalog === catalogId
  )?.[0]
  if (!apiModule || !summary) return null
  return summary.modules[apiModule]?.level ?? 1
}

/** The streak badges' look: the number of days in an orange disc (docs/GAMIFICATION.md § 4). */
const STREAK_TROPHIES = Object.fromEntries(
  STREAK_BADGES.map(badge => [
    badge.key,
    {
      icon: String(badge.goal),
      get name() {
        return badge.name
      },
      get desc() {
        return t('gamification.badges.streakDesc', badge.goal)
      },
      bg: '#FFEBDD',
      ink: '#B84A0E',
      streak: badge.goal
    }
  ])
)

/** The puzzle streak badges' look: the number of puzzles in a violet disc (docs/PUZZLE_STREAK.md). */
const PUZZLE_STREAK_TROPHIES = Object.fromEntries(
  PUZZLE_STREAK_BADGES.map(badge => [
    badge.key,
    {
      icon: String(badge.goal),
      get name() {
        return badge.name
      },
      get desc() {
        return t('gamification.badges.puzzleStreakDesc', badge.goal)
      },
      bg: '#EEE9FF',
      ink: '#4A2FE0',
      puzzles: badge.goal
    }
  ])
)

/**
 * The trophies' look (docs/GAMIFICATION.md § 4 bis): the trophies in the API's order, then the 12
 * streak badges (7 and 30 days are the older "En feu" and "Inarrêtable"), shortest first, then the
 * puzzle streak badges.
 */
export const TROPHIES = {
  woodpecker: {
    icon: '♝︎',
    get name() {
      return t('gamification.trophies.woodpecker.name')
    },
    get desc() {
      return t('gamification.trophies.woodpecker.desc')
    },
    bg: '#F0FBCF',
    ink: '#5A7A00'
  },
  golden_fork: {
    icon: '♞︎',
    get name() {
      return t('gamification.trophies.golden_fork.name')
    },
    get desc() {
      return t('gamification.trophies.golden_fork.desc')
    },
    bg: '#FFE6F1',
    ink: '#C02670'
  },
  iron_memory: {
    icon: '♜︎',
    get name() {
      return t('gamification.trophies.iron_memory.name')
    },
    get desc() {
      return t('gamification.trophies.iron_memory.desc')
    },
    bg: '#E2F2FF',
    ink: '#0F6BBA'
  },
  first_step: {
    icon: '♙︎',
    get name() {
      return t('gamification.trophies.first_step.name')
    },
    get desc() {
      return t('gamification.trophies.first_step.desc')
    },
    bg: '#EEE9FF',
    ink: '#4A2FE0'
  },
  steel_woodpecker: {
    icon: '♛︎',
    get name() {
      return t('gamification.trophies.steel_woodpecker.name')
    },
    get desc() {
      return t('gamification.trophies.steel_woodpecker.desc')
    },
    bg: '#F0FBCF',
    ink: '#5A7A00'
  },
  centurion: {
    icon: '♚︎',
    get name() {
      return t('gamification.trophies.centurion.name')
    },
    get desc() {
      return t('gamification.trophies.centurion.desc')
    },
    bg: '#FFE6F1',
    ink: '#C02670'
  },
  conductor: {
    icon: '♖︎',
    get name() {
      return t('gamification.trophies.conductor.name')
    },
    get desc() {
      return t('gamification.trophies.conductor.desc')
    },
    bg: '#EEE9FF',
    ink: '#4A2FE0'
  },
  marathon: {
    icon: '⏱︎',
    get name() {
      return t('gamification.trophies.marathon.name')
    },
    get desc() {
      return t('gamification.trophies.marathon.desc')
    },
    bg: '#E2F2FF',
    ink: '#0F6BBA'
  },
  ...STREAK_TROPHIES,
  ...PUZZLE_STREAK_TROPHIES
}

/**
 * Where a trophy goes in the grid: the others, then the streak badges by days, then the puzzle
 * streak badges by puzzles.
 *
 * @param {string} key
 */
function trophyRank(key) {
  const look = TROPHIES[key]
  if (look.puzzles) return 100_000 + look.puzzles
  return look.streak ?? 0
}

/**
 * The trophy cards: look, progress label, date of the feat. The streak badges come last, by length,
 * then the puzzle streak badges.
 *
 * @param {Trophy[]} trophies
 */
export function trophyCards(trophies) {
  return trophies
    .filter(t => TROPHIES[t.key])
    .sort((a, b) => trophyRank(a.key) - trophyRank(b.key))
    .map(t => ({
      ...TROPHIES[t.key],
      key: t.key,
      unlocked: t.unlocked,
      progress: t.unlocked
        ? tr('gamification.progress.unlockedOn', {
            date: new Intl.DateTimeFormat(intlLocale(), {
              dateStyle: 'medium'
            }).format(new Date(t.unlockedAt ?? ''))
          })
        : t.key === 'iron_memory' && t.ratio !== null
          ? tr('gamification.progress.tests', {
              current: t.current,
              goal: t.goal,
              pct: Math.round(t.ratio * 100)
            })
          : tr(
              TROPHIES[t.key].streak
                ? 'gamification.progress.days'
                : TROPHIES[t.key].puzzles
                  ? 'gamification.progress.puzzles'
                  : 'gamification.progress.plain',
              { current: number.format(t.current), goal: number.format(t.goal) }
            ),
      percent: t.goal > 0 ? Math.min(100, (t.current / t.goal) * 100) : 0
    }))
}

/**
 * The quest's sentence.
 *
 * @param {Quest} quest
 * @param {(key: string) => string} themeLabel
 */
export function questText(quest, themeLabel) {
  const n = quest.goal
  switch (quest.template) {
    case 'rated_puzzles':
    case 'sessions':
    case 'woodpecker':
    case 'repertoire':
      return t(`gamification.quests.${quest.template}`, { n }, n)
    case 'weak_theme':
      return t(
        'gamification.quests.weak_theme',
        { n, theme: themeLabel(quest.theme ?? '') },
        n
      )
    default:
      return t('gamification.quests.days', { n }, n)
  }
}

/**
 * The professor who gives the quest: the module's, Lizy for a quest across modules.
 *
 * @param {Quest} quest
 * @returns {{name: string, image: string}}
 */
export function questProf(quest) {
  const module = quest.module
    ? MODULES_BY_ID[CATALOG_MODULES[quest.module]]
    : null
  if (module?.prof) return { name: module.prof, image: module.image ?? '' }
  return { name: 'Lizy', image: profImage('lizy-think') }
}

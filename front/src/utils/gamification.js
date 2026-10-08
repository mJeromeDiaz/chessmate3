import { profImage } from '@/utils/prof/images'
import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { STREAK_BADGES } from '@/utils/streak'

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
 * @property {{current: number, best: number, playedToday: boolean, week: boolean[], nextMilestone: number|null}} streak
 *   week: the active days of the local week, Monday first
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

const number = new Intl.NumberFormat('fr-FR')

/**
 * The level bar: "Niveau 12 · Tacticien", "2 340 / 3 000 XP", what is left before the next rank.
 *
 * @param {Summary} summary
 */
export function levelBar(summary) {
  const left = Math.max(0, summary.xpForNext - summary.xpInLevel)
  return {
    level: summary.level,
    rank: summary.rank,
    percent:
      summary.xpForNext > 0
        ? Math.min(100, (summary.xpInLevel / summary.xpForNext) * 100)
        : 0,
    xpLabel: `${number.format(summary.xpInLevel)} / ${number.format(summary.xpForNext)} XP`,
    next: summary.nextRank
      ? `Encore ${number.format(left)} XP avant le niveau ${summary.level + 1} · « ${summary.nextRank.rank} » au niveau ${summary.nextRank.level}`
      : `Encore ${number.format(left)} XP avant le niveau ${summary.level + 1}`
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
      name: badge.name,
      desc: `${badge.goal} jours d’affilée`,
      bg: '#FFEBDD',
      ink: '#B84A0E',
      streak: badge.goal
    }
  ])
)

/**
 * The trophies' look (docs/GAMIFICATION.md § 4 bis): the trophies in the API's order, then the 12
 * streak badges (7 and 30 days are the older "En feu" and "Inarrêtable"), shortest first.
 */
export const TROPHIES = {
  woodpecker: {
    icon: '♝︎',
    name: 'Pivert',
    desc: 'Un cycle Woodpecker dans les temps',
    bg: '#F0FBCF',
    ink: '#5A7A00'
  },
  golden_fork: {
    icon: '♞︎',
    name: 'Fourchette d’or',
    desc: '100 fourchettes sans aide',
    bg: '#FFE6F1',
    ink: '#C02670'
  },
  iron_memory: {
    icon: '♜︎',
    name: 'Mémoire d’acier',
    desc: '95 % sur 50 tests de répertoire en 30 jours',
    bg: '#E2F2FF',
    ink: '#0F6BBA'
  },
  first_step: {
    icon: '♙︎',
    name: 'Premier pas',
    desc: 'Un premier exercice',
    bg: '#EEE9FF',
    ink: '#4A2FE0'
  },
  steel_woodpecker: {
    icon: '♛︎',
    name: 'Pic d’acier',
    desc: 'Un set Woodpecker terminé',
    bg: '#F0FBCF',
    ink: '#5A7A00'
  },
  centurion: {
    icon: '♚︎',
    name: 'Centurion',
    desc: '1 000 puzzles résolus',
    bg: '#FFE6F1',
    ink: '#C02670'
  },
  conductor: {
    icon: '♖︎',
    name: 'Chef d’orchestre',
    desc: '10 sessions menées au bout',
    bg: '#EEE9FF',
    ink: '#4A2FE0'
  },
  marathon: {
    icon: '⏱︎',
    name: 'Marathonien',
    desc: '50 h d’entraînement',
    bg: '#E2F2FF',
    ink: '#0F6BBA'
  },
  ...STREAK_TROPHIES
}

/**
 * The trophy cards: look, progress label, date of the feat. The streak badges come last, by length.
 *
 * @param {Trophy[]} trophies
 */
export function trophyCards(trophies) {
  return trophies
    .filter(t => TROPHIES[t.key])
    .sort(
      (a, b) => (TROPHIES[a.key].streak ?? 0) - (TROPHIES[b.key].streak ?? 0)
    )
    .map(t => ({
      ...TROPHIES[t.key],
      key: t.key,
      unlocked: t.unlocked,
      progress: t.unlocked
        ? `Le ${new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium' }).format(new Date(t.unlockedAt ?? ''))}`
        : t.key === 'iron_memory' && t.ratio !== null
          ? `${t.current}/${t.goal} tests · ${Math.round(t.ratio * 100)} %`
          : TROPHIES[t.key].streak
            ? `${number.format(t.current)}/${number.format(t.goal)} jours`
            : `${number.format(t.current)}/${number.format(t.goal)}`,
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
      return `Résous ${n} puzzles classés cette semaine.`
    case 'weak_theme':
      return `Résous ${n} puzzles « ${themeLabel(quest.theme ?? '')} » sans aide : c’est ton point faible du moment.`
    case 'sessions':
      return `Mène ${n} sessions au bout cette semaine.`
    case 'woodpecker':
      return `Joue ${n} puzzles de ton set Woodpecker.`
    case 'repertoire':
      return `Réussis ${n} tronçons de ton répertoire.`
    default:
      return `Entraîne-toi ${n} jours cette semaine.`
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

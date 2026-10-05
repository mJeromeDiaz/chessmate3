import { MODULES_BY_ID } from '@/utils/session/catalog'
import { moduleLevel } from '@/utils/gamification'

/**
 * The dashboard's "Progression par module" rows (design "Dashboard"): the catalogue's look, the
 * real figures of the modules that exist, "Bientôt" for the others. The bar shows a real ratio
 * (puzzle success rate, Woodpecker cycle progress, repertoire 30-day success rate) and the level
 * is the module's (docs/GAMIFICATION.md).
 *
 * @typedef {object} ModuleRow
 * @property {import('@/utils/session/catalog').Module} module
 * @property {string} stat
 * @property {number} ratio 0..1, the bar
 * @property {string} ratioLabel what the bar measures
 * @property {number|null} level the module's level, null for a module that does not exist yet
 * @property {string|null} to link, null when the module does not exist yet
 *
 * @typedef {object} ModuleSources
 * @property {Record<string, {count: number, successCount: number}>} totals activity totals by exercise type
 * @property {{rating: number, provisional: boolean}|null} puzzleRating
 * @property {import('@/stores/woodpecker').WoodpeckerSet[]} sets
 * @property {{cards: {total: number, due: number}, repertoires: {successRate30: number|null}[]}|null} repertoires
 * @property {import('@/utils/gamification').Summary|null} [gamification] module levels
 */

/** The design's order. */
export const DASHBOARD_MODULES = [
  'puzzles',
  'woodpecker',
  'finales',
  'repertoire',
  'evaluation',
  'analyse'
]

const number = new Intl.NumberFormat('fr-FR')

/**
 * @param {number} ratio
 * @returns {string}
 */
const percent = ratio => `${Math.round(ratio * 100)} %`

/**
 * @param {ModuleSources} sources
 * @returns {ModuleRow[]}
 */
export function buildModuleRows(sources) {
  return DASHBOARD_MODULES.map(id => {
    const module = MODULES_BY_ID[id]
    const row = {
      module,
      stat: 'Bientôt',
      ratio: 0,
      ratioLabel: '',
      level: moduleLevel(sources.gamification ?? null, id),
      to: null
    }
    if (id === 'puzzles') Object.assign(row, puzzles(sources))
    if (id === 'woodpecker') Object.assign(row, woodpecker(sources.sets))
    if (id === 'repertoire') Object.assign(row, repertoire(sources.repertoires))
    return row
  })
}

/**
 * @param {ModuleSources} sources
 * @returns {Partial<ModuleRow>}
 */
function puzzles({ totals, puzzleRating }) {
  const played = ['puzzle_rated', 'puzzle_unrated']
    .map(type => totals[type])
    .filter(Boolean)
  const count = played.reduce((sum, t) => sum + t.count, 0)
  const solved = played.reduce((sum, t) => sum + t.successCount, 0)
  const rating = puzzleRating
    ? ` · Elo ${puzzleRating.rating}${puzzleRating.provisional ? '?' : ''}`
    : ''
  return {
    to: '/puzzle',
    stat: count
      ? `${number.format(solved)} résolus${rating}`
      : 'Aucun puzzle joué',
    ratio: count ? solved / count : 0,
    ratioLabel: count ? `Réussite ${percent(solved / count)}` : ''
  }
}

/**
 * The set being worked on: an active one with a cycle in progress, else any active one.
 *
 * @param {import('@/stores/woodpecker').WoodpeckerSet[]} sets
 * @returns {Partial<ModuleRow>}
 */
function woodpecker(sets) {
  const active = sets.filter(s => s.status === 'active' && !s.archived)
  const set = active.find(s => s.current?.status === 'active') ?? active[0]
  if (!set) return { to: '/woodpecker', stat: 'Aucun set en cours' }
  const cycle = set.current
  if (!cycle) {
    return { to: `/woodpecker/${set.id}`, stat: set.name }
  }
  const ratio = set.puzzleCount
    ? Math.min(1, cycle.played / set.puzzleCount)
    : 0
  const round =
    set.mode === 'light'
      ? `Light · tour ${cycle.number}`
      : `Cycle ${cycle.number}${set.cycleCount ? `/${set.cycleCount}` : ''}`
  const accuracy =
    cycle.accuracy === null ? '' : ` · précision ${percent(cycle.accuracy)}`
  return {
    to: `/woodpecker/${set.id}`,
    stat: `${round} · ${cycle.played}/${set.puzzleCount}${accuracy}`,
    ratio,
    ratioLabel: `Cycle ${percent(ratio)}`
  }
}

/**
 * @param {ModuleSources['repertoires']} overview
 * @returns {Partial<ModuleRow>}
 */
function repertoire(overview) {
  if (!overview?.repertoires.length)
    return { to: '/repertoire', stat: 'Aucun répertoire' }
  const rates = overview.repertoires
    .map(r => r.successRate30)
    .filter(rate => rate !== null)
  const rate = rates.length
    ? rates.reduce((a, b) => a + b, 0) / rates.length
    : null
  const parts = [
    `${number.format(overview.cards.total)} coups`,
    overview.cards.due ? `${overview.cards.due} à revoir` : 'rien à revoir'
  ]
  if (rate !== null) parts.push(`${percent(rate)} sur 30 j`)
  return {
    to: '/repertoire',
    stat: parts.join(' · '),
    ratio: rate ?? 0,
    ratioLabel: rate === null ? '' : `Réussite 30 j ${percent(rate)}`
  }
}

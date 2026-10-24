import { MODULES_BY_ID } from '@/utils/session/catalog'
import { moduleLevel } from '@/utils/gamification'
import { intlLocale, t } from '@/i18n'

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

/** Numbers in the current language (docs/I18N.md). */
const number = { format: n => new Intl.NumberFormat(intlLocale()).format(n) }

/**
 * @param {number} ratio
 * @returns {string}
 */
const percent = ratio =>
  t('dashboard.modules.percent', { pct: Math.round(ratio * 100) })

/**
 * @param {ModuleSources} sources
 * @returns {ModuleRow[]}
 */
export function buildModuleRows(sources) {
  return DASHBOARD_MODULES.map(id => {
    const module = MODULES_BY_ID[id]
    const row = {
      module,
      stat: t('dashboard.modules.soon'),
      ratio: 0,
      ratioLabel: '',
      level: moduleLevel(sources.gamification ?? null, id),
      to: null
    }
    if (id === 'puzzles') Object.assign(row, puzzles(sources))
    if (id === 'woodpecker') Object.assign(row, woodpecker(sources.sets))
    if (id === 'repertoire') Object.assign(row, repertoire(sources.repertoires))
    if (id === 'evaluation') Object.assign(row, evaluation(sources))
    if (id === 'finales') Object.assign(row, endgames(sources))
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
    ? t('dashboard.modules.puzzles.elo', {
        rating: `${puzzleRating.rating}${puzzleRating.provisional ? '?' : ''}`
      })
    : ''
  return {
    to: '/puzzle',
    stat: count
      ? t('dashboard.modules.puzzles.solved', { n: number.format(solved) }) +
        rating
      : t('dashboard.modules.puzzles.none'),
    ratio: count ? solved / count : 0,
    ratioLabel: count
      ? t('dashboard.modules.puzzles.rate', { pct: percent(solved / count) })
      : ''
  }
}

/**
 * Endgame positions played out (docs/ENDGAMES.md): how many, and the share won or held.
 *
 * @param {ModuleSources} sources
 * @returns {Partial<ModuleRow>}
 */
function endgames({ totals }) {
  const played = totals.endgame
  if (!played?.count)
    return { to: '/finales', stat: t('dashboard.modules.endgames.none') }
  const ratio = played.successCount / played.count
  return {
    to: '/finales',
    stat: t('dashboard.modules.endgames.stat', {
      n: number.format(played.count),
      pct: percent(ratio)
    }),
    ratio,
    ratioLabel: t('dashboard.modules.endgames.rate', { pct: percent(ratio) })
  }
}

/**
 * Positions evaluated (docs/EVALUATION.md): how many, and the share judged exactly.
 *
 * @param {ModuleSources} sources
 * @returns {Partial<ModuleRow>}
 */
function evaluation({ totals }) {
  const played = totals.position_evaluation
  if (!played?.count)
    return { to: '/evaluation', stat: t('dashboard.modules.evaluation.none') }
  const ratio = played.successCount / played.count
  return {
    to: '/evaluation',
    stat: t('dashboard.modules.evaluation.stat', {
      n: number.format(played.count),
      pct: percent(ratio)
    }),
    ratio,
    ratioLabel: t('dashboard.modules.evaluation.rate', { pct: percent(ratio) })
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
  if (!set)
    return { to: '/woodpecker', stat: t('dashboard.modules.woodpecker.none') }
  const cycle = set.current
  if (!cycle) {
    return { to: `/woodpecker/${set.id}`, stat: set.name }
  }
  const ratio = set.puzzleCount
    ? Math.min(1, cycle.played / set.puzzleCount)
    : 0
  const round =
    set.mode === 'light'
      ? t('dashboard.modules.woodpecker.light', { n: cycle.number })
      : set.cycleCount
        ? t('dashboard.modules.woodpecker.cycleOf', {
            n: cycle.number,
            total: set.cycleCount
          })
        : t('dashboard.modules.woodpecker.cycle', { n: cycle.number })
  const accuracy =
    cycle.accuracy === null
      ? ''
      : t('dashboard.modules.woodpecker.accuracy', {
          pct: percent(cycle.accuracy)
        })
  return {
    to: `/woodpecker/${set.id}`,
    stat: `${round} · ${cycle.played}/${set.puzzleCount}${accuracy}`,
    ratio,
    ratioLabel: t('dashboard.modules.woodpecker.rate', { pct: percent(ratio) })
  }
}

/**
 * @param {ModuleSources['repertoires']} overview
 * @returns {Partial<ModuleRow>}
 */
function repertoire(overview) {
  if (!overview?.repertoires.length)
    return { to: '/repertoire', stat: t('dashboard.modules.repertoire.none') }
  const rates = overview.repertoires
    .map(r => r.successRate30)
    .filter(rate => rate !== null)
  const rate = rates.length
    ? rates.reduce((a, b) => a + b, 0) / rates.length
    : null
  const parts = [
    t('dashboard.modules.repertoire.moves', {
      n: number.format(overview.cards.total)
    }),
    overview.cards.due
      ? t('dashboard.modules.repertoire.due', { n: overview.cards.due })
      : t('dashboard.modules.repertoire.nothingDue')
  ]
  if (rate !== null)
    parts.push(t('dashboard.modules.repertoire.rate30', { pct: percent(rate) }))
  return {
    to: '/repertoire',
    stat: parts.join(' · '),
    ratio: rate ?? 0,
    ratioLabel:
      rate === null
        ? ''
        : t('dashboard.modules.repertoire.rateLabel', { pct: percent(rate) })
  }
}

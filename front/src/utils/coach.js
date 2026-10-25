/**
 * The tailored session's words and colours (design "Session perso", docs/COACH.md), from what the
 * API says of the user's games: the diagnosis (`GET /coach/builds/{id}/diagnosis`) and the
 * composition (`POST /coach/builds/{id}/session`). Pure, so that it can be tested alone; nothing is
 * claimed that the games do not say.
 *
 * @typedef {{family: string, games: number, wins: number, draws: number, losses: number}} Opening
 * @typedef {{type: string, games: number, wins: number, draws: number, losses: number, advantages: number, lost: number}} Endgame
 * @typedef {object} Diagnosis
 * @property {number} games
 * @property {number} analysed
 * @property {number} rating
 * @property {Opening[]} white
 * @property {Opening[]} black
 * @property {{games: number, advantages: number, lost: number, types: Endgame[]}} endgames
 * @property {Endgame|null} weakest
 * @typedef {{module: string, minutes: number, included: boolean, missing: string|null, facts: Record<string, any>}} CoachModule
 * @typedef {{planId: string, minutes: number, diagnosis: Diagnosis, repertoire: {status: string, repertoires: {id: string, name: string, color: string}[]}, modules: CoachModule[]}} Composition
 */

import { autoErrorText } from '@/utils/repertoireAuto'
import { intlLocale, t } from '@/i18n'

/** Games read, and their speeds (App\Coach\CoachRules). */
export const COACH_GAMES = 200
export const COACH_PERFS = ['blitz', 'rapid', 'classical']

/** The endgame kinds as the scan names them before the diagnosis (App\Enum\Chess\EndgameType). */
export const ENDGAME_LABELS = Object.defineProperties(
  {},
  Object.fromEntries(
    ['pawn', 'rook', 'minor', 'queen', 'rook_minor', 'mixed'].map(type => [
      type,
      { get: () => t(`coach.endgames.${type}`), enumerable: true }
    ])
  )
)

/**
 * An endgame kind inside a sentence ("tours"), from its type sent by the API.
 *
 * @param {{type: string}} endgame
 */
function endgameName(endgame) {
  return (ENDGAME_LABELS[endgame.type] ?? endgame.type).toLowerCase()
}

/** Why Woodpecker helps (the user's words, 2026-10-11). */
export const WOODPECKER_REASONS = [0, 1, 2].map(i => ({
  get title() {
    return t(`coach.reasons.${i}.title`)
  },
  get text() {
    return t(`coach.reasons.${i}.text`)
  }
}))

/**
 * The light method, as the session plays it (docs/WOODPECKER.md).
 *
 * @returns {string[]}
 */
export function woodpeckerMethod() {
  return [0, 1, 2].map(i => t(`coach.method.${i}`))
}

/**
 * The colour of each step, in the session's order: repertoire, evaluation, Woodpecker, Brillant ou
 * Blunder, endgames, blindfold.
 *
 * @param {CoachModule} module
 * @returns {string}
 */
export function moduleColor(module) {
  if (module.module === 'woodpecker') {
    return module.facts.color === 'black' ? '#FFE27A' : '#FFD43B'
  }
  return (
    {
      repertoire: '#FFB38A',
      evaluation: '#CBBEFF',
      brilliancy: '#7FE3D6',
      endgames: '#A8E6A1',
      blindfold: '#4FB2FF'
    }[module.module] ?? '#E9E6F3'
  )
}

/**
 * The endgames theme of the session's step: "Tours".
 *
 * @param {CoachModule} module
 */
const endgameTheme = module =>
  t(`endgame.families.${module.facts.settings?.themes?.[0] ?? 'rooks'}`)

/** @param {string} color 'white' or 'black' */
const side = color => t(`coach.sides.${color === 'black' ? 'black' : 'white'}`)

/**
 * A number of things: "12 parties" (coach.counts.<kind>).
 *
 * @param {number} n
 * @param {'slips'|'games'|'repertoires'|'openings'|'endgames'} kind
 */
const count = (n, kind) =>
  t(`coach.counts.${kind}`, { n: n.toLocaleString(intlLocale()) }, n)

/**
 * Share of the points: wins plus half the draws.
 *
 * @param {{games: number, wins: number, draws: number}} record
 * @returns {number|null} 0 to 100
 */
export function score(record) {
  return record.games
    ? Math.round(((record.wins + record.draws / 2) / record.games) * 100)
    : null
}

/**
 * "7 avantages laissés filer sur 9"
 *
 * @param {{advantages: number, lost: number}} endgame
 */
export function slipText(endgame) {
  return t('coach.slip', {
    slips: count(endgame.lost, 'slips'),
    n: endgame.advantages
  })
}

/**
 * Albert's words while the games are read, then once the diagnosis is in.
 *
 * @param {number} progress 0 to 1
 * @param {Diagnosis|null} diagnosis
 */
export function scanSays(progress, diagnosis) {
  if (diagnosis) {
    if (!diagnosis.games) return t('coach.scan.none')
    if (diagnosis.weakest)
      return t('coach.scan.weakest', {
        type: endgameName(diagnosis.weakest),
        slip: slipText(diagnosis.weakest)
      })
    if (diagnosis.endgames.games) return t('coach.scan.solid')
    return t('coach.scan.few')
  }
  if (progress < 0.3) return t('coach.scan.reading')
  if (progress < 0.5) return t('coach.scan.openings')
  return t('coach.scan.endgames')
}

/**
 * The need a module answers, as the composition screen reveals it.
 *
 * @param {CoachModule} module
 * @param {Composition} composition
 */
export function needText(module, composition) {
  const f = module.facts
  switch (module.module) {
    case 'woodpecker':
      if (!f.opening)
        return t('coach.need.noWoodpecker', { side: side(f.color) })
      return t('coach.need.woodpecker', {
        family: f.opening.family,
        side: side(f.color),
        games: count(f.opening.games, 'games')
      })
    case 'repertoire': {
      const status = module.missing ?? composition.repertoire.status
      const name = f.repertoires?.[0]?.name ?? ''
      if (status === 'existing')
        return f.opening
          ? t('coach.need.existingOpening', {
              name,
              family: f.opening.family,
              games: count(f.opening.games, 'games')
            })
          : t('coach.need.existing', { name })
      if (status === 'generated')
        return t('coach.need.generated', { name })
      return ['not_asked', 'no_opening', 'failed'].includes(status)
        ? t(`coach.need.${status}`)
        : t('coach.need.repertoire')
    }
    case 'brilliancy':
      return t('coach.need.brilliancy')
    case 'endgames':
      return f.weakest
        ? t('coach.need.endgamesWeak', {
            type: endgameName(f.weakest),
            slip: slipText(f.weakest)
          })
        : t('coach.need.endgames', { theme: endgameTheme(module) })
    case 'blindfold':
      return t('coach.need.blindfold')
    case 'evaluation':
      return f.endgames?.lost
        ? t('coach.need.evaluationLost', { slip: slipText(f.endgames) })
        : t('coach.need.evaluation')
    default:
      return ''
  }
}

/**
 * A module's card on the session screen.
 *
 * @param {CoachModule} module
 * @returns {{title: string, subtitle: string, text: string, glyph: string|null}}
 */
export function moduleCard(module) {
  const f = module.facts
  switch (module.module) {
    case 'woodpecker': {
      const glyph = f.color === 'black' ? '♚︎' : '♔︎'
      const k = 'coach.card.'
      if (!f.opening) {
        return {
          title: t(`${k}woodpecker`),
          subtitle: t(`${k}with`, { side: side(f.color) }),
          text: t(`${k}noGames`, { n: COACH_GAMES, side: side(f.color) }),
          glyph
        }
      }
      const rate = score(f.opening)
      const subtitle =
        t(`${k}first`, {
          side: side(f.color),
          games: count(f.opening.games, 'games')
        }) + (rate === null ? '' : t(`${k}points`, { n: rate }))
      const title = t(`${k}woodpeckerNamed`, { family: f.opening.family })
      if (!module.included) {
        return { title, subtitle, text: t(`${k}notEnough`), glyph }
      }
      return {
        title,
        subtitle,
        text: t(`${k}puzzles`, {
          n: f.puzzleCount,
          who: f.bothColors
            ? t(`${k}bothSides`)
            : t(`${k}you`, { side: side(f.color) })
        }),
        glyph
      }
    }
    case 'repertoire': {
      const names = (f.repertoires ?? []).map(r => r.name)
      const k = 'coach.card.'
      const missing = ['not_asked', 'no_opening', 'failed'].includes(
        module.missing ?? ''
      )
        ? module.missing
        : 'default'
      return {
        title: t(`${k}repertoire`),
        subtitle: module.included ? names[0] : t(`${k}missing`),
        text: module.included
          ? t(`${k}repertoireText`)
          : t(`${k}repertoireMissing.${missing}`),
        glyph: null
      }
    }
    case 'brilliancy':
      return {
        title: t('coach.card.brilliancy'),
        subtitle: t('coach.card.brilliancySub'),
        text: t('coach.card.brilliancyText'),
        glyph: '!!'
      }
    case 'endgames':
      return {
        title: t('coach.card.endgames', { theme: endgameTheme(module) }),
        subtitle: t('coach.card.endgamesSub'),
        text: f.weakest
          ? t('coach.card.endgamesWeak', {
              type: endgameName(f.weakest),
              slip: slipText(f.weakest),
              theme: endgameTheme(module)
            })
          : t('coach.card.endgamesText'),
        glyph: null
      }
    case 'blindfold':
      return {
        title: t('coach.card.blindfold'),
        subtitle: t('coach.card.blindfoldSub'),
        text: t('coach.card.blindfoldText'),
        glyph: null
      }
    case 'evaluation':
      return {
        title: t('coach.card.evaluation'),
        subtitle: t('coach.card.evaluationSub'),
        text: f.endgames?.lost
          ? t('coach.card.evaluationLost', {
              endgames: count(f.endgames.games, 'endgames'),
              advantages: f.endgames.advantages,
              lost: f.endgames.lost
            })
          : t('coach.card.evaluationText'),
        glyph: null
      }
    default:
      return { title: module.module, subtitle: '', text: '', glyph: null }
  }
}

/**
 * Albert's word on the session screen.
 *
 * @param {Composition} composition
 */
export function sessionSays(composition) {
  const repertoire = composition.modules.find(
    m => m.module === 'repertoire' && m.included
  )
  const start = repertoire
    ? t('coach.session.repertoire')
    : t('coach.session.none')
  const weakest = composition.diagnosis.weakest
  return weakest
    ? t('coach.session.weakest', { start, type: endgameName(weakest) })
    : t('coach.session.keep', { start })
}

/**
 * The included modules of a composition (what the session plays), in order.
 *
 * @param {Composition} composition
 * @returns {CoachModule[]}
 */
export function playedModules(composition) {
  return composition.modules.filter(m => m.included)
}

/**
 * An API refusal, in words.
 *
 * @param {any} error
 */
export function coachErrorText(error) {
  const detail = error?.response?.data?.detail
  if (detail === 'limit_plans') {
    return t('coach.errors.limit_plans')
  }
  return autoErrorText(error)
}

import {
  ORIENTATIONS,
  formatAnswerTime,
  missedSquares,
  validationText
} from '@/utils/coordinates'
import { levelBar } from '@/utils/gamification'
import { formatDuration, formatRatingDelta } from '@/utils/format'
import { profImage } from '@/utils/prof/images'
import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { unitWord } from '@/utils/repertoireTest'
import { FREE_FORMATS } from '@/utils/training'
import { MODULE_FAIL_MIN_ITEMS, MODULE_FAIL_RATE } from '@/utils/sounds'

/**
 * The end-of-run review (design "Fin de séance", docs/TRAINING.md § 5 quater): what the dialog
 * shows, computed from the closed run and its review. Pure, so that it can be tested alone.
 *
 * @typedef {'ok'|'hint'|'fail'} ItemStatus
 *
 * @typedef {object} ReviewItem
 * @property {number} index 1-based, in the order played
 * @property {'puzzle'|'woodpecker_puzzle'|'repertoire_unit'|'coordinate'|'blindfold_puzzle'} type
 * @property {ItemStatus} status
 * @property {number|null} durationMs
 * @property {Record<string, any>} data
 *
 * @typedef {object} RunReview
 * @property {string} id
 * @property {string} module
 * @property {ReviewItem[]} items
 *
 * @typedef {object} Stat
 * @property {string} value
 * @property {string} label
 * @property {string} sub
 * @property {'good'|'bad'|'muted'|'brand'} tone colour of `sub`
 */

/** Colours and labels of an item's status (grid, legend, list). */
export const STATUS = {
  ok: { label: 'Réussi', color: '#A6D61E' },
  hint: { label: 'Avec aide', color: '#FFD43B' },
  fail: { label: 'Raté', color: '#FF6FAE' }
}

/** The professor's picture at the end of a run, by professor: pleased, or thoughtful. */
const MOODS = {
  'albert-stein': { happy: 'albert-joy', sad: 'albert-think' },
  aaron: { happy: 'aaron-laugh', sad: 'aaron-wow' },
  lizy: { happy: 'lizy-joy', sad: 'lizy-think' }
}

/**
 * Whether the run counts as failed: the rule of its end sound (`moduleEndSound`).
 *
 * @param {{module: string, summary: {itemCount: number, successCount: number}|null}} run
 */
export function isFailedRun(run) {
  const s = run.summary
  return (
    run.module !== 'free' &&
    !!s &&
    s.itemCount >= MODULE_FAIL_MIN_ITEMS &&
    s.successCount < s.itemCount * MODULE_FAIL_RATE
  )
}

/**
 * The run's module in the session catalogue (its professor and colours), null for an unknown one.
 *
 * @param {string} module the API's module
 */
export function catalogModule(module) {
  return MODULES_BY_ID[CATALOG_MODULES[module]] ?? null
}

/**
 * The professor shown at the end: name, picture (pleased or thoughtful) and the module's colours.
 *
 * @param {string} module the API's module
 * @param {boolean} failed
 */
export function endProf(module, failed) {
  const m = catalogModule(module)
  const mood = m?.profSlug ? MOODS[m.profSlug] : null
  const picture = mood ? profImage(failed ? mood.sad : mood.happy) : ''
  return {
    name: m?.prof ?? 'Ton prof',
    image: picture || m?.image || '',
    glyph: m?.glyph ?? '♞︎',
    bg: m?.bg ?? '#C6F432',
    deep: m?.deep ?? '#8DBA0A',
    ink: m?.ink ?? '#1B1530',
    accentInk: m?.accentInk ?? '#5A7A00',
    title: m?.title ?? ''
  }
}

/**
 * @param {number} count
 * @param {string} module the API's module
 * @param {string} [unit] repertoire: segment or line
 */
function itemWord(count, module, unit) {
  if (module === 'repertoire') return unitWord(unit ?? 'segment', count)
  if (module === 'coordinates') return `${count} case${count > 1 ? 's' : ''}`
  return `${count} puzzle${count > 1 ? 's' : ''}`
}

/**
 * The hero: kicker, title and subtitle.
 *
 * @param {import('@/composables/training/useTimeboxedRun').TrainingRun} run
 * @param {string} name the player's name
 * @param {boolean} failed
 */
export function endHero(run, name, failed) {
  const s = run.summary
  const cycle = s?.metrics?.cycle?.number
  const orientation = ORIENTATIONS[s?.metrics?.orientation] ?? ''
  const kicker =
    run.module === 'woodpecker' && cycle
      ? `SÉANCE TERMINÉE · CYCLE ${cycle}`
      : run.module === 'coordinates'
        ? `${s?.metrics?.validated ? 'SÉRIE VALIDÉE' : 'SÉRIE TERMINÉE'} · ${orientation.toUpperCase()}`
        : 'SÉANCE TERMINÉE'
  const title = failed ? `Courage ${name} !` : `Bravo ${name} !`
  const duration = formatDuration(s?.durationMs)
  const subtitle =
    run.module === 'free'
      ? `${duration} d’étude`
      : `${itemWord(s?.itemCount ?? 0, run.module, s?.metrics?.unit)} en ${duration}`
  return { kicker, title, subtitle }
}

/**
 * What a missed item is about: a puzzle's first theme, a repertoire unit's opening and move.
 *
 * @param {ReviewItem} item
 * @param {(key: string) => string} themeLabel
 */
export function itemTitle(item, themeLabel) {
  if (item.type === 'repertoire_unit') {
    const label = item.data.label ?? {}
    const opening = label.opening?.name ?? item.data.repertoireName ?? ''
    return label.move ? `${opening} · ${label.move}` : opening
  }
  const theme = item.data.puzzle?.themes?.[0]
  return theme ? themeLabel(theme) : 'Puzzle'
}

/**
 * The number shown on an item: its place in the Woodpecker cycle, its order in the run otherwise.
 *
 * @param {ReviewItem} item
 */
export function itemNumber(item) {
  return item.data.number ?? item.index
}

/**
 * The items to review again (failed, or solved with help), as listed under "À revoir".
 *
 * @param {ReviewItem[]} items
 * @param {(key: string) => string} themeLabel
 */
export function missedItems(items, themeLabel) {
  return items
    .filter(item => item.status !== 'ok')
    .map(item => ({
      item,
      number: itemNumber(item),
      title: itemTitle(item, themeLabel),
      meta: `${STATUS[item.status].label} · ${formatDuration(item.durationMs)}`,
      color: STATUS[item.status].color,
      replayable: item.type !== 'repertoire_unit' || !!item.data.startFen
    }))
}

/**
 * The weak point: the theme (puzzles) or opening (repertoire) missed most often.
 *
 * @param {ReviewItem[]} items
 * @param {(key: string) => string} themeLabel
 * @returns {{label: string, count: number}|null}
 */
export function weakPoint(items, themeLabel) {
  /** @type {Map<string, number>} */
  const counts = new Map()
  for (const item of items) {
    if (item.status === 'ok') continue
    const keys =
      item.type === 'repertoire_unit'
        ? [itemTitle(item, themeLabel)]
        : (item.data.puzzle?.themes ?? []).map(themeLabel)
    for (const key of keys) counts.set(key, (counts.get(key) ?? 0) + 1)
  }
  let best = null
  for (const [label, count] of counts) {
    if (!best || count > best.count) best = { label, count }
  }
  return best
}

/**
 * The professor's message, from the figures.
 *
 * @param {import('@/composables/training/useTimeboxedRun').TrainingRun} run
 * @param {ReviewItem[]} items
 * @param {string} name
 * @param {(key: string) => string} themeLabel
 */
export function endMessage(run, items, name, themeLabel) {
  const s = run.summary
  if (run.module === 'free') {
    const format = FREE_FORMATS[s?.metrics?.format] ?? 'Étude'
    return `${formatDuration(s?.durationMs)} d’étude (${format.toLowerCase()}). Bien joué ${name} : la régularité, c’est ce qui paie.`
  }
  if (!s || s.itemCount === 0) {
    return 'Rien de terminé cette fois. Pas grave : on fait mieux au prochain module.'
  }
  const pct = Math.round((s.successCount / s.itemCount) * 100)
  if (run.module === 'coordinates') {
    const missed = missedSquares(items, 3)
    const squares = missed.length
      ? ` Cases à retravailler : ${missed.map(m => m.square).join(', ')}.`
      : ' Aucune erreur !'
    return `${validationText(run)}${squares}`
  }
  const opener =
    pct >= 85
      ? `Quelle séance, ${name} !`
      : pct >= 70
        ? `Belle séance, ${name} !`
        : isFailedRun(run)
          ? 'Séance difficile, mais chaque erreur t’apprend quelque chose.'
          : 'Tu t’accroches, et ça paie.'
  const weak = weakPoint(items, themeLabel)
  if (!weak) return `${opener} ${pct} % de réussite, aucune erreur à revoir.`
  const what =
    run.module === 'repertoire' ? 'Ligne à reprendre' : 'Point à reprendre'
  return `${opener} ${pct} % de réussite. ${what} : ${weak.label} (${weak.count} à revoir). On les rejoue maintenant ?`
}

/**
 * The four figures under the message, by module; the last one is the XP gained in the run
 * (docs/GAMIFICATION.md) and the level reached.
 *
 * @param {import('@/composables/training/useTimeboxedRun').TrainingRun} run
 * @param {ReviewItem[]} items
 * @param {{xp: number|null, summary: import('@/utils/gamification').Summary|null}} [gain] null
 *   xp: not counted yet
 * @returns {Stat[]}
 */
export function endStats(run, items, gain = { xp: null, summary: null }) {
  const s = run.summary
  const m = s?.metrics ?? {}
  const xp = {
    value: gain.xp === null ? '…' : `+${gain.xp}`,
    label: 'XP gagnés',
    sub: gain.summary
      ? `Niveau ${gain.summary.level} · ${Math.round(levelBar(gain.summary).percent)} %`
      : '',
    tone: /** @type {const} */ ('brand')
  }
  if (run.module === 'free') {
    return [
      {
        value: formatDuration(s?.durationMs),
        label: 'Temps d’étude',
        sub: FREE_FORMATS[m.format] ?? '',
        tone: 'muted'
      },
      xp
    ]
  }
  const count = s?.itemCount ?? 0
  const pct =
    count > 0 ? Math.round(((s?.successCount ?? 0) / count) * 100) : null
  if (run.module === 'coordinates') {
    return [
      {
        value: pct === null ? '—' : `${pct} %`,
        label: 'Réussite',
        sub: `${s?.successCount ?? 0} sur ${count}`,
        tone: isFailedRun(run) ? 'bad' : 'good'
      },
      {
        value: formatAnswerTime(m.averageMs ?? null),
        label: 'Moyenne / case',
        sub: `${formatDuration(s?.durationMs)} au total`,
        tone: 'muted'
      },
      {
        value: m.validated ? 'Validée' : 'Non validée',
        label: 'Validation',
        sub: `${ORIENTATIONS[m.orientation] ?? ''} en bas`,
        tone: m.validated ? 'good' : 'muted'
      },
      xp
    ]
  }
  const timed = items.filter(i => i.durationMs !== null)
  const average =
    m.averageMs ??
    (timed.length
      ? timed.reduce((sum, i) => sum + (i.durationMs ?? 0), 0) / timed.length
      : null)
  /** @type {Stat[]} */
  const stats = [
    {
      value: pct === null ? '—' : `${pct} %`,
      label: 'Réussite',
      sub: `${s?.successCount ?? 0} sur ${count}`,
      tone: isFailedRun(run) ? 'bad' : 'good'
    },
    {
      value: formatDuration(average),
      label:
        run.module === 'repertoire' ? 'Moyenne / unité' : 'Moyenne / puzzle',
      sub: `${formatDuration(s?.durationMs)} au total`,
      tone: 'muted'
    }
  ]
  if (run.module === 'blindfold') {
    stats.push({
      value: String(m.helped ?? 0),
      label: 'Avec coup d’œil',
      sub: `${m.failed ?? 0} raté${(m.failed ?? 0) > 1 ? 's' : ''}`,
      tone: 'muted'
    })
  } else if (run.module === 'puzzles') {
    stats.push({
      value: m.ratingAfter == null ? '—' : String(m.ratingAfter),
      label: 'Classement',
      sub:
        m.ratingDelta == null ? '' : `${formatRatingDelta(m.ratingDelta)} pts`,
      tone: (m.ratingDelta ?? 0) >= 0 ? 'good' : 'bad'
    })
  } else if (run.module === 'woodpecker') {
    const played = m.cycle?.played
    stats.push({
      value:
        played == null
          ? String(m.puzzleCount ?? '—')
          : `${played}/${m.puzzleCount}`,
      label: 'Puzzles du set',
      sub:
        played == null
          ? m.added
            ? `+${m.added} ajoutés`
            : ''
          : `${Math.max(0, (m.puzzleCount ?? 0) - played)} restants`,
      tone: 'muted'
    })
  } else {
    stats.push({
      value: String(m.positionsGraded ?? 0),
      label: 'Positions notées',
      sub: m.recovered
        ? `${m.recovered} rattrapé${m.recovered > 1 ? 's' : ''}`
        : '',
      tone: 'good'
    })
  }
  stats.push(xp)
  return stats
}

/**
 * Title of the grid of items.
 *
 * @param {import('@/composables/training/useTimeboxedRun').TrainingRun} run
 */
export function gridTitle(run) {
  if (run.module === 'coordinates') return 'Cases de la série'
  if (run.module !== 'repertoire') return 'Puzzles de la séance'
  return run.summary?.metrics?.unit === 'line'
    ? 'Lignes de la séance'
    : 'Tronçons de la séance'
}

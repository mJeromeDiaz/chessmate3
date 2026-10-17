import {
  ORIENTATIONS,
  formatAnswerTime,
  missedSquares,
  validationText
} from '@/utils/coordinates'
import { engineLabel, tagLabel } from '@/utils/evaluation'
import { levelBar } from '@/utils/gamification'
import { formatDuration, formatRatingDelta } from '@/utils/format'
import { moodImage } from '@/utils/prof/profs'
import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { unitWord } from '@/utils/repertoireTest'
import { FREE_FORMATS } from '@/utils/training'
import { MODULE_FAIL_MIN_ITEMS, MODULE_FAIL_RATE } from '@/utils/sounds'
import { t } from '@/i18n'

/**
 * The end-of-run review (design "Fin de séance", docs/TRAINING.md § 5 quater): what the dialog
 * shows, computed from the closed run and its review. Pure, so that it can be tested alone.
 *
 * @typedef {'ok'|'hint'|'fail'} ItemStatus
 *
 * @typedef {object} ReviewItem
 * @property {number} index 1-based, in the order played
 * @property {'puzzle'|'woodpecker_puzzle'|'repertoire_unit'|'coordinate'|'blindfold_puzzle'|'streak_puzzle'} type
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
  ok: status('ok', '#A6D61E'),
  hint: status('hint', '#FFD43B'),
  fail: status('fail', '#FF6FAE')
}

/**
 * @param {ItemStatus} key
 * @param {string} color
 * @returns {{label: string, color: string}}
 */
function status(key, color) {
  return {
    get label() {
      return t(`training.end.status.${key}`)
    },
    color
  }
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
    run.module !== 'puzzle_streak' &&
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
  return moduleProf(module, failed ? 'sad' : 'happy')
}

/**
 * A module's professor in a mood: name, picture (the bust when the mood has none) and the module's
 * colours.
 *
 * @param {string} module the API's module
 * @param {'neutral'|'happy'|'sad'} mood
 */
export function moduleProf(module, mood) {
  const m = catalogModule(module)
  const picture = moodImage(m?.profSlug ?? null, mood)
  return {
    name: m?.prof ?? t('training.result.yourProf'),
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
  const words =
    module === 'coordinates' || module === 'evaluation' ? module : 'puzzles'
  return t(`training.end.words.${words}`, { n: count }, count)
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
      ? t('training.end.kicker.cycle', { n: cycle })
      : run.module === 'coordinates'
        ? t(
            s?.metrics?.validated
              ? 'training.end.kicker.seriesValidated'
              : 'training.end.kicker.seriesDone',
            { orientation: orientation.toUpperCase() }
          )
        : t('training.end.kicker.done')
  const title = t(failed ? 'training.end.courage' : 'training.end.bravo', {
    name
  })
  const duration = formatDuration(s?.durationMs)
  const subtitle =
    run.module === 'free'
      ? t('training.end.study', { duration })
      : t('training.end.itemsIn', {
          items: itemWord(s?.itemCount ?? 0, run.module, s?.metrics?.unit),
          duration
        })
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
  if (item.type === 'evaluation_position')
    return t('training.end.engine', {
      tag: item.data.position?.tag
        ? tagLabel(item.data.position.tag)
        : t('training.end.position'),
      engine:
        typeof item.data.evalCp === 'number'
          ? engineLabel(item.data.evalCp)
          : ''
    })
  const theme = item.data.puzzle?.themes?.[0]
  return theme ? themeLabel(theme) : t('training.end.puzzle')
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
      meta: t('training.end.meta', {
        status: STATUS[item.status].label,
        duration: formatDuration(item.durationMs)
      }),
      color: STATUS[item.status].color,
      // A position to evaluate has no line to replay.
      replayable:
        item.type !== 'evaluation_position' &&
        (item.type !== 'repertoire_unit' || !!item.data.startFen)
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
    const format =
      FREE_FORMATS[s?.metrics?.format] ?? t('training.end.message.study')
    return t('training.end.message.free', {
      duration: formatDuration(s?.durationMs),
      format: format.toLowerCase(),
      name
    })
  }
  if (!s || s.itemCount === 0) {
    return t('training.end.message.nothing')
  }
  const pct = Math.round((s.successCount / s.itemCount) * 100)
  if (run.module === 'coordinates') {
    const missed = missedSquares(items, 3)
    const squares = missed.length
      ? t('training.end.message.squares', {
          squares: missed.map(m => m.square).join(', ')
        })
      : t('training.end.message.noMistake')
    return `${validationText(run)} ${squares}`
  }
  const opener =
    pct >= 85
      ? t('training.end.message.great', { name })
      : pct >= 70
        ? t('training.end.message.good', { name })
        : isFailedRun(run)
          ? t('training.end.message.hard')
          : t('training.end.message.hanging')
  const weak = weakPoint(items, themeLabel)
  if (!weak) return t('training.end.message.clean', { opener, pct })
  const what = t(
    run.module === 'repertoire'
      ? 'training.end.message.line'
      : 'training.end.message.point'
  )
  return t('training.end.message.weak', {
    opener,
    pct,
    what,
    label: weak.label,
    count: weak.count
  })
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
    label: t('training.end.stats.xp'),
    sub: gain.summary
      ? t('training.end.stats.level', {
          level: gain.summary.level,
          pct: Math.round(levelBar(gain.summary).percent)
        })
      : '',
    tone: /** @type {const} */ ('brand')
  }
  if (run.module === 'free') {
    return [
      {
        value: formatDuration(s?.durationMs),
        label: t('training.end.stats.studyTime'),
        sub: FREE_FORMATS[m.format] ?? '',
        tone: 'muted'
      },
      xp
    ]
  }
  const count = s?.itemCount ?? 0
  const pct =
    count > 0 ? Math.round(((s?.successCount ?? 0) / count) * 100) : null
  const success = {
    value: pct === null ? '—' : t('training.end.stats.percent', { n: pct }),
    label: t('training.end.stats.success'),
    sub: t('training.end.stats.outOf', {
      done: s?.successCount ?? 0,
      total: count
    })
  }
  const total = t('training.end.stats.total', {
    duration: formatDuration(s?.durationMs)
  })
  if (run.module === 'coordinates') {
    return [
      {
        ...success,
        tone: isFailedRun(run) ? 'bad' : 'good'
      },
      {
        value: formatAnswerTime(m.averageMs ?? null),
        label: t('training.end.stats.perSquare'),
        sub: total,
        tone: 'muted'
      },
      {
        value: t(
          m.validated
            ? 'training.end.stats.validated'
            : 'training.end.stats.notValidated'
        ),
        label: t('training.end.stats.validation'),
        sub: t('training.end.stats.bottom', {
          orientation: ORIENTATIONS[m.orientation] ?? ''
        }),
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
      ...success,
      tone: isFailedRun(run) ? 'bad' : 'good'
    },
    {
      value: formatDuration(average),
      label: t(
        run.module === 'repertoire'
          ? 'training.end.stats.perUnit'
          : 'training.end.stats.perPuzzle'
      ),
      sub: total,
      tone: 'muted'
    }
  ]
  if (run.module === 'blindfold') {
    stats.push({
      value: String(m.helped ?? 0),
      label: t('training.end.stats.peek'),
      sub: t('training.end.stats.failed', { n: m.failed ?? 0 }, m.failed ?? 0),
      tone: 'muted'
    })
  } else if (run.module === 'puzzles') {
    stats.push({
      value: m.ratingAfter == null ? '—' : String(m.ratingAfter),
      label: t('training.end.stats.rating'),
      sub:
        m.ratingDelta == null
          ? ''
          : t('training.end.stats.points', {
              delta: formatRatingDelta(m.ratingDelta)
            }),
      tone: (m.ratingDelta ?? 0) >= 0 ? 'good' : 'bad'
    })
  } else if (run.module === 'woodpecker') {
    const played = m.cycle?.played
    stats.push({
      value:
        played == null
          ? String(m.puzzleCount ?? '—')
          : `${played}/${m.puzzleCount}`,
      label: t('training.end.stats.setPuzzles'),
      sub:
        played == null
          ? m.added
            ? t('training.end.stats.added', { n: m.added })
            : ''
          : t('training.end.stats.left', {
              n: Math.max(0, (m.puzzleCount ?? 0) - played)
            }),
      tone: 'muted'
    })
  } else {
    stats.push({
      value: String(m.positionsGraded ?? 0),
      label: t('training.end.stats.graded'),
      sub: m.recovered
        ? t('training.end.stats.recovered', { n: m.recovered }, m.recovered)
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
  if (run.module === 'coordinates') return t('training.end.grid.coordinates')
  if (run.module !== 'repertoire') return t('training.end.grid.puzzles')
  return t(
    run.summary?.metrics?.unit === 'line'
      ? 'training.end.grid.lines'
      : 'training.end.grid.segments'
  )
}

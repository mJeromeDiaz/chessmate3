import { engineLabel, tagLabel } from '@/utils/evaluation'
import { formatDuration } from '@/utils/format'
import { moodImage } from '@/utils/prof/profs'
import { CATALOG_MODULES, MODULES_BY_ID } from '@/utils/session/catalog'
import { t } from '@/i18n'

/**
 * The end-of-run review (docs/TRAINING.md § 5 quater): the missed items the result screen lists
 * and replays, and the module's professor. Pure, so that it can be tested alone.
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
 * The run's module in the session catalogue (its professor and colours), null for an unknown one.
 *
 * @param {string} module the API's module
 */
export function catalogModule(module) {
  return MODULES_BY_ID[CATALOG_MODULES[module]] ?? null
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

import { intlLocale, t } from '@/i18n'

/**
 * The crowns of a completed session (design "Coffre", docs/SHOP.md, § 4 bis): the chest's stages,
 * coloured by the amount on the first one and titled by the tier on the last one, and the store's
 * "how to earn". Pure.
 *
 * @typedef {object} SessionReward the `reward` of a session (API)
 * @property {string} id
 * @property {number} crowns
 * @property {number} paidMinutes after the daily cap
 * @property {'perfect'|'near'|'missed'} tier
 * @property {boolean} capped the daily cap cut its paid time
 * @property {boolean} opened its chest was seen
 *
 * @typedef {object} DailyReward a daily chest (API, docs/SHOP.md, § 4 ter)
 * @property {string} id
 * @property {string} localDate
 * @property {number} streak the streak reached that day
 * @property {number|null} badgeDays the streak badge won that day
 * @property {number} dailyCrowns the daily draw (6 to 12)
 * @property {number} badgeCrowns the badge's bonus (0 without one)
 * @property {number} crowns both together
 * @property {boolean} opened
 *
 * @typedef {object} ChestWords what another chest says instead of a session's
 * @property {string} [kicker] the closed chest's kicker
 * @property {[string, string]} [openTitle] the open chest's title
 *
 * @typedef {object} ChestStage
 * @property {string} bg
 * @property {string} glow
 * @property {string} ink
 * @property {string} kicker
 * @property {[string, string]} title
 * @property {string} accent the title's second line
 * @property {string} hint
 */

/** Taps that open the chest. */
export const CHEST_HITS = 3

/** The defaults of the API's rule, while the store is not loaded. */
const DEFAULT_EARNING = {
  crownsPerHour: 120,
  perfectPercent: 150,
  nearPercent: 100,
  missedPercent: 50,
  dailyMinutes: 60
}

/**
 * The most a day can earn: its paid minutes, perfect.
 *
 * @param {Partial<import('@/utils/shop').ShopEarning>|null|undefined} earning
 */
export function dailyMax(earning) {
  const e = { ...DEFAULT_EARNING, ...earning }
  return Math.round(
    (e.crownsPerHour * e.perfectPercent * e.dailyMinutes) / (100 * 60)
  )
}

/**
 * How big a reward is, against the day's maximum: under a quarter small, from 60 % big.
 *
 * @param {number} crowns
 * @param {Partial<import('@/utils/shop').ShopEarning>|null|undefined} earning
 * @returns {'small'|'medium'|'big'}
 */
export function chestSize(crowns, earning) {
  const max = dailyMax(earning)
  const ratio = max > 0 ? crowns / max : 1
  if (ratio >= 0.6) return 'big'
  return ratio < 0.25 ? 'small' : 'medium'
}

/** The first stage's background by size (its promise: shop.chest.closed.<size>). */
const CLOSED = {
  small: {
    bg: '#1E2A4A',
    glow: 'rgba(79,178,255,.35)'
  },
  medium: {
    bg: '#2B2250',
    glow: 'rgba(139,107,255,.35)'
  },
  big: {
    bg: '#4A1550',
    glow: 'rgba(255,212,59,.45)'
  }
}

/** Tiers with a title of their own on the last stage (shop.chest.open). */
const TIERS = ['perfect', 'near', 'missed']

/**
 * A two-line title from messages.
 *
 * @param {string} key
 * @returns {[string, string]}
 */
const title = key => [t(`${key}.0`), t(`${key}.1`)]

/**
 * The four stages of the chest: closed (by size), two hits, open (by tier).
 *
 * @param {SessionReward} reward
 * @param {Partial<import('@/utils/shop').ShopEarning>|null|undefined} earning
 * @param {ChestWords} [words] another chest's words (the daily chest's)
 * @returns {ChestStage[]}
 */
export function chestStages(reward, earning, words = {}) {
  const size = chestSize(reward.crowns, earning)
  const closed = CLOSED[size]
  const tier = TIERS.includes(reward.tier) ? reward.tier : 'near'
  return [
    {
      bg: closed.bg,
      glow: closed.glow,
      ink: '#FFFFFF',
      kicker: words.kicker ?? t('shop.chest.stages.closed.kicker'),
      title: title(`shop.chest.closed.${size}`),
      accent: '#C6F432',
      hint: t('shop.chest.stages.closed.hint')
    },
    {
      bg: '#5A3BE0',
      glow: 'rgba(255,111,174,.45)',
      ink: '#FFFFFF',
      kicker: t('shop.chest.stages.hit1.kicker'),
      title: title('shop.chest.stages.hit1.title'),
      accent: '#FFD43B',
      hint: t('shop.chest.stages.hit1.hint')
    },
    {
      bg: '#E03C86',
      glow: 'rgba(201,184,255,.65)',
      ink: '#FFFFFF',
      kicker: t('shop.chest.stages.hit2.kicker'),
      title: title('shop.chest.stages.hit2.title'),
      accent: '#FFD43B',
      hint: t('shop.chest.stages.hit2.hint')
    },
    {
      bg: '#C9B8FF',
      glow: 'rgba(255,255,255,.9)',
      ink: '#1B1530',
      kicker: t('shop.chest.stages.open.kicker'),
      title: words.openTitle ?? title(`shop.chest.open.${tier}`),
      accent: '#5A3BE0',
      hint: ''
    }
  ]
}

/**
 * "20 min payées · Session parfaite", the cap said when it cut the time.
 *
 * @param {SessionReward} reward
 */
export function rewardNote(reward) {
  const parts = [
    t(
      'shop.chest.paid',
      { n: reward.paidMinutes },
      reward.paidMinutes > 1 ? 2 : 1
    ),
    TIERS.includes(reward.tier) ? t(`shop.chest.tiers.${reward.tier}`) : ''
  ]
  if (reward.capped) parts.push(t('shop.chest.capped'))
  return parts.filter(Boolean).join(' · ')
}

/**
 * A completed session's line in its recap: "+9 cristaux", or why none.
 *
 * @param {SessionReward|null} reward
 * @returns {string} '' without a reward
 */
export function rewardLine(reward) {
  if (!reward) return ''
  if (reward.crowns === 0) return t('shop.chest.cappedLine')
  return t('shop.chest.line', {
    n: new Intl.NumberFormat(intlLocale()).format(reward.crowns),
    note: rewardNote(reward)
  })
}

/**
 * Whether the chest of a session is still to open.
 *
 * @param {{status: string, reward?: SessionReward|null}|null|undefined} session
 * @param {Set<string>} [seen] chests seen in this tab (the server may not know yet)
 */
export function chestToOpen(session, seen = new Set()) {
  return (
    !!session &&
    session.status === 'completed' &&
    !!session.reward &&
    !session.reward.opened &&
    session.reward.crowns > 0 &&
    !seen.has(session.reward.id)
  )
}

/**
 * The store's "how to earn" (the admin's rule): what an hour pays by tier, the day's limit.
 *
 * @param {import('@/utils/shop').ShopEarning} earning
 */
export function earningRows(earning) {
  const hour = pct => Math.round((earning.crownsPerHour * pct) / 100)
  return [
    { label: t('shop.earning.perfect'), value: hour(earning.perfectPercent) },
    { label: t('shop.earning.near'), value: hour(earning.nearPercent) },
    { label: t('shop.earning.missed'), value: hour(earning.missedPercent) }
  ]
}

/**
 * A daily chest as the treasure chest screen takes a reward (`TreasureChest`): its crystals, the
 * best tier (sized by its amount like a session's).
 *
 * @param {DailyReward} daily
 * @returns {SessionReward}
 */
export function dailyChestReward(daily) {
  return {
    id: daily.id,
    crowns: daily.crowns,
    paidMinutes: 0,
    tier: 'perfect',
    capped: false,
    opened: daily.opened
  }
}

/**
 * The daily chest's words: "COFFRE DU JOUR", or the badge's days on a badge day.
 *
 * @param {DailyReward} daily
 * @returns {Required<ChestWords>}
 */
export function dailyChestWords(daily) {
  if (daily.badgeDays)
    return {
      kicker: t('shop.daily.badgeKicker', { n: daily.badgeDays }),
      openTitle: [
        t('shop.daily.badgeTitle.0', { n: daily.badgeDays }),
        t('shop.daily.badgeTitle.1')
      ]
    }
  return {
    kicker: t('shop.daily.kicker'),
    openTitle: title('shop.daily.title')
  }
}

/**
 * The line under the amount: the streak, or the draw and the badge's bonus.
 *
 * @param {DailyReward} daily
 */
export function dailyChestNote(daily) {
  if (daily.badgeDays)
    return t('shop.daily.badgeNote', {
      daily: daily.dailyCrowns,
      bonus: new Intl.NumberFormat(intlLocale()).format(daily.badgeCrowns),
      n: daily.badgeDays
    })
  return t('shop.daily.note', { n: daily.streak }, daily.streak > 1 ? 2 : 1)
}

import { intlLocale } from '@/i18n'

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

/** The first stage's background and promise by size. */
const CLOSED = {
  small: {
    bg: '#1E2A4A',
    glow: 'rgba(79,178,255,.35)',
    title: ['Un petit trésor', 'pour toi !']
  },
  medium: {
    bg: '#2B2250',
    glow: 'rgba(139,107,255,.35)',
    title: ['Un trésor', 'pour toi !']
  },
  big: {
    bg: '#4A1550',
    glow: 'rgba(255,212,59,.45)',
    title: ['Un gros trésor', 'pour toi !']
  }
}

/** The last stage's title by tier. */
const OPEN_TITLES = {
  perfect: ['Quel', 'jackpot !'],
  near: ['Beau', 'trésor !'],
  missed: ['Joli', 'butin !']
}

/**
 * The four stages of the chest: closed (by size), two hits, open (by tier).
 *
 * @param {SessionReward} reward
 * @param {Partial<import('@/utils/shop').ShopEarning>|null|undefined} earning
 * @returns {ChestStage[]}
 */
export function chestStages(reward, earning) {
  const closed = CLOSED[chestSize(reward.crowns, earning)]
  return [
    {
      bg: closed.bg,
      glow: closed.glow,
      ink: '#FFFFFF',
      kicker: 'RÉCOMPENSE DE SESSION',
      title: /** @type {[string, string]} */ (closed.title),
      accent: '#C6F432',
      hint: 'Touche le coffre'
    },
    {
      bg: '#5A3BE0',
      glow: 'rgba(255,111,174,.45)',
      ink: '#FFFFFF',
      kicker: 'ÇA BOUGE…',
      title: ['Il résiste', 'encore !'],
      accent: '#FFD43B',
      hint: 'Encore un coup !'
    },
    {
      bg: '#E03C86',
      glow: 'rgba(255,212,59,.6)',
      ink: '#FFFFFF',
      kicker: 'PRESQUE…',
      title: ['Allez,', 'le dernier !'],
      accent: '#FFD43B',
      hint: 'Il va céder…'
    },
    {
      bg: '#FFD43B',
      glow: 'rgba(255,255,255,.9)',
      ink: '#1B1530',
      kicker: 'TRÉSOR OUVERT',
      title: /** @type {[string, string]} */ (
        OPEN_TITLES[reward.tier] ?? OPEN_TITLES.near
      ),
      accent: '#C02670',
      hint: ''
    }
  ]
}

/** What a tier means, under the amount. */
const TIER_NOTES = {
  perfect: 'Session parfaite',
  near: 'Presque parfaite',
  missed: 'Session terminée'
}

/**
 * "20 min payées · Session parfaite", the cap said when it cut the time.
 *
 * @param {SessionReward} reward
 */
export function rewardNote(reward) {
  const parts = [
    `${reward.paidMinutes} min payée${reward.paidMinutes > 1 ? 's' : ''}`,
    TIER_NOTES[reward.tier] ?? ''
  ]
  if (reward.capped) parts.push('plafond du jour atteint')
  return parts.filter(Boolean).join(' · ')
}

/**
 * A completed session's line in its recap: "+9 couronnes", or why none.
 *
 * @param {SessionReward|null} reward
 * @returns {string} '' without a reward
 */
export function rewardLine(reward) {
  if (!reward) return ''
  if (reward.crowns === 0)
    return 'Plafond du jour atteint : pas de couronnes pour cette session.'
  return `+${new Intl.NumberFormat(intlLocale()).format(reward.crowns)} couronnes · ${rewardNote(reward)}`
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
    { label: 'Session parfaite (100 %)', value: hour(earning.perfectPercent) },
    { label: 'Presque parfaite (80 à 99 %)', value: hour(earning.nearPercent) },
    { label: 'En dessous de 80 %', value: hour(earning.missedPercent) }
  ]
}

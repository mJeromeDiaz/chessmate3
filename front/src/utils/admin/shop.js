import { ITEMS } from '@/utils/shop'
import { labelMap, t } from '@/i18n'

/**
 * The store's administration (docs/SHOP.md): the offer and slide forms, statuses and labels.
 * Pure.
 *
 * @typedef {import('@/utils/shop').ShopOffer & {position: number, startsAt: string|null, suspended: boolean, listed: boolean, onSale: boolean, sales: number, crowns: number}} AdminOffer
 * @typedef {{id: string, offerId: string, offerName: string, tag: string, image: string, position: number, startsAt: string|null, endsAt: string|null, suspended: boolean, shown: boolean}} AdminSlide
 *
 * @typedef {object} OfferForm
 * @property {string} slug
 * @property {string} section
 * @property {string} name
 * @property {string} subtitle
 * @property {string} description
 * @property {{item: string, quantity: number}[]} contents
 * @property {number|null} price
 * @property {number|null} wasPrice
 * @property {number} position
 * @property {string} startsAt datetime-local value, '' for none
 * @property {string} endsAt
 * @property {boolean} suspended
 * @property {boolean} comingSoon
 */

export const SECTION_OPTIONS = [
  'freeze',
  'puzzle_streak',
  'aids',
  'packs',
  'cosmetics'
].map(value => ({
  value,
  get label() {
    return t(`admin.shop.sections.${value}`)
  }
}))

/** The coded items an offer can hold. */
export const ITEM_OPTIONS = Object.entries(ITEMS).map(([value, look]) => ({
  value,
  get label() {
    return look.label
  }
}))

/** Professors' pictures for a slide (src/assets/profs). */
export const SLIDE_IMAGES = [
  'albert-cool',
  'albert-joy',
  'albert-v2-pointer',
  'albert-v2-cheer',
  'aaron-wink',
  'aaron-laugh',
  'lizy-cool',
  'lizy-joy',
  'lizy-wow'
]

export const LEDGER_KINDS = labelMap('admin.shop.ledger', [
  'purchase',
  'grant',
  'revoke',
  'reward'
])

/**
 * The reward rule's fields (docs/SHOP.md, § 4 bis): label, bounds, unit.
 *
 * @type {{key: 'crownsPerHour'|'perfectPercent'|'nearPercent'|'missedPercent'|'dailyMinutes', label: string, max: number, suffix: string, hint: string}[]}
 */
export const REWARD_RULE_FIELDS = /** @type {const} */ ([
  ['crownsPerHour', 10000, 'perHour'],
  ['perfectPercent', 1000, '%'],
  ['nearPercent', 1000, '%'],
  ['missedPercent', 1000, '%'],
  ['dailyMinutes', 1440, 'minutes']
]).map(([key, max, unit]) => ({
  key,
  max,
  get label() {
    return t(`admin.shop.rule.${key}.label`)
  },
  get suffix() {
    return unit === '%' ? '%' : t(`admin.shop.rule.${unit}`)
  },
  get hint() {
    return t(`admin.shop.rule.${key}.hint`)
  }
}))

/** The gift wheel's prizes, whose weights the admin sets (docs/SHOP.md, § 4 ter). */
export const WHEEL_PRIZES = /** @type {const} */ ([
  'c5',
  'c30',
  'c300',
  'gift',
  'lose'
])

/**
 * Each prize's chance (rounded %) under the weights; 0 everywhere when they are all 0.
 *
 * @param {Record<string, number>} weights
 * @returns {Record<string, number>}
 */
export function wheelChances(weights) {
  const total = WHEEL_PRIZES.reduce(
    (sum, p) => sum + Math.max(0, Number(weights[p]) || 0),
    0
  )
  return Object.fromEntries(
    WHEEL_PRIZES.map(p => [
      p,
      total
        ? Math.round((Math.max(0, Number(weights[p]) || 0) * 1000) / total) / 10
        : 0
    ])
  )
}

/**
 * The crystals a spin gives on average under the weights (gifts and the skull: none).
 *
 * @param {Record<string, number>} weights
 */
export function wheelAverage(weights) {
  const chances = wheelChances(weights)
  return Math.round(
    (chances.c5 * 5 + chances.c30 * 30 + chances.c300 * 300) / 10
  ) / 10
}

/**
 * What a session earns under a rule, for the admin's preview: crowns for `minutes` paid minutes
 * at each tier.
 *
 * @param {{crownsPerHour: number, perfectPercent: number, nearPercent: number, missedPercent: number}} rule
 * @param {number} minutes
 */
export function rewardPreview(rule, minutes) {
  const at = pct =>
    Math.floor((minutes * 60 * rule.crownsPerHour * pct + 180000) / 360000)
  return {
    perfect: at(rule.perfectPercent),
    near: at(rule.nearPercent),
    missed: at(rule.missedPercent)
  }
}

/**
 * An instant as a datetime-local value, in the browser's time zone ('' for none).
 *
 * @param {string|null} iso
 */
export function toLocalInput(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/**
 * A datetime-local value as an instant (ISO 8601, UTC), null when empty.
 *
 * @param {string} local
 */
export function fromLocalInput(local) {
  return local ? new Date(local).toISOString() : null
}

/**
 * The form of an offer, or of a new one.
 *
 * @param {AdminOffer|null} offer
 * @returns {OfferForm}
 */
export function offerForm(offer) {
  if (!offer)
    return {
      slug: '',
      section: 'packs',
      name: '',
      subtitle: '',
      description: '',
      contents: [{ item: 'puzzle_pardon', quantity: 1 }],
      price: null,
      wasPrice: null,
      position: 0,
      startsAt: '',
      endsAt: '',
      suspended: false,
      comingSoon: false
    }
  return {
    slug: offer.slug,
    section: offer.section,
    name: offer.name,
    subtitle: offer.subtitle ?? '',
    description: offer.description,
    contents: offer.items.map(i => ({ ...i })),
    price: offer.price,
    wasPrice: offer.wasPrice,
    position: offer.position,
    startsAt: toLocalInput(offer.startsAt),
    endsAt: toLocalInput(offer.endsAt),
    suspended: offer.suspended,
    comingSoon: offer.comingSoon
  }
}

/**
 * What the API receives.
 *
 * @param {OfferForm} form
 */
export function offerPayload(form) {
  return {
    slug: form.slug.trim(),
    section: form.section,
    name: form.name,
    subtitle: form.subtitle || null,
    description: form.description,
    contents: form.contents.map(c => ({
      item: c.item,
      quantity: Number(c.quantity)
    })),
    price: form.price === null || form.price === '' ? null : Number(form.price),
    wasPrice:
      form.wasPrice === null || form.wasPrice === ''
        ? null
        : Number(form.wasPrice),
    position: Number(form.position) || 0,
    startsAt: fromLocalInput(form.startsAt),
    endsAt: fromLocalInput(form.endsAt),
    suspended: form.suspended,
    comingSoon: form.comingSoon
  }
}

/**
 * Where an offer stands now.
 *
 * @param {Pick<AdminOffer, 'suspended'|'comingSoon'|'listed'|'startsAt'>} offer
 * @param {Date} [now]
 * @returns {{label: string, tone: 'active'|'used'|'danger'|''}}
 */
export function offerStatus(offer, now = new Date()) {
  const s = 'admin.shop.status.'
  if (offer.suspended) return { label: t(`${s}suspended`), tone: 'danger' }
  if (!offer.listed)
    return offer.startsAt && new Date(offer.startsAt) > now
      ? { label: t(`${s}scheduled`), tone: 'active' }
      : { label: t(`${s}ended`), tone: '' }
  if (offer.comingSoon) return { label: t(`${s}soon`), tone: '' }
  return { label: t(`${s}onSale`), tone: 'used' }
}

/**
 * A field's message among a 422's violations.
 *
 * @param {{propertyPath: string, title: string}[]} violations
 * @param {string} path
 */
export function violationOf(violations, path) {
  return violations
    .filter(v => v.propertyPath === path)
    .map(v => v.title)
    .join(' ')
}

/**
 * The violations of a 422 answer ([] otherwise).
 *
 * @param {unknown} error an axios error
 * @returns {{propertyPath: string, title: string}[]}
 */
export function violationsOf(error) {
  const response = /** @type {any} */ (error)?.response
  return response?.status === 422 && Array.isArray(response.data?.violations)
    ? response.data.violations
    : []
}

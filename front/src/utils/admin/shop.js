import { ITEMS } from '@/utils/shop'

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
  { value: 'freeze', label: 'Gel de série' },
  { value: 'puzzle_streak', label: 'Puzzle Streak' },
  { value: 'aids', label: 'Aides de module' },
  { value: 'packs', label: 'Packs' },
  { value: 'cosmetics', label: 'Cosmétiques' }
]

/** The coded items an offer can hold. */
export const ITEM_OPTIONS = Object.entries(ITEMS).map(([value, look]) => ({
  value,
  label: look.label
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

export const LEDGER_KINDS = {
  purchase: 'Achat',
  grant: 'Ajout',
  revoke: 'Retrait',
  reward: 'Session'
}

/**
 * The reward rule's fields (docs/SHOP.md, § 4 bis): label, bounds, unit.
 *
 * @type {{key: 'crownsPerHour'|'perfectPercent'|'nearPercent'|'missedPercent'|'dailyMinutes', label: string, max: number, suffix: string, hint: string}[]}
 */
export const REWARD_RULE_FIELDS = [
  {
    key: 'crownsPerHour',
    label: 'Couronnes par heure payée',
    max: 10000,
    suffix: '/ h',
    hint: 'Au palier « presque » (coefficient 100 %).'
  },
  {
    key: 'perfectPercent',
    label: 'Session parfaite (100 %)',
    max: 1000,
    suffix: '%',
    hint: 'Coefficient du palier.'
  },
  {
    key: 'nearPercent',
    label: 'Presque parfaite (80 à 99 %)',
    max: 1000,
    suffix: '%',
    hint: 'Coefficient du palier.'
  },
  {
    key: 'missedPercent',
    label: 'En dessous de 80 %',
    max: 1000,
    suffix: '%',
    hint: 'Coefficient du palier.'
  },
  {
    key: 'dailyMinutes',
    label: 'Minutes payées par jour',
    max: 1440,
    suffix: 'min',
    hint: 'Par jour local du joueur.'
  }
]

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
  if (offer.suspended) return { label: 'Suspendue', tone: 'danger' }
  if (!offer.listed)
    return offer.startsAt && new Date(offer.startsAt) > now
      ? { label: 'Programmée', tone: 'active' }
      : { label: 'Terminée', tone: '' }
  if (offer.comingSoon) return { label: 'Bientôt', tone: '' }
  return { label: 'En vente', tone: 'used' }
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

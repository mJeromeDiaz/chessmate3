import { intlLocale, t } from '@/i18n'

/**
 * The store (design "Boutique", docs/SHOP.md): what each coded item looks like and how it works,
 * the sections and their order, prices and savings. Pure. The offers, the balance and the
 * inventory come from the API (GET /shop).
 *
 * @typedef {object} ShopOffer
 * @property {string} id
 * @property {string} slug
 * @property {'freeze'|'puzzle_streak'|'aids'|'packs'|'wheel'|'cosmetics'} section
 * @property {string} name
 * @property {string|null} subtitle
 * @property {string} description
 * @property {{item: string, quantity: number}[]} items
 * @property {string|null} module puzzles, blindfold or coordinates (a module aid)
 * @property {number} price
 * @property {number|null} wasPrice
 * @property {boolean} comingSoon
 * @property {string|null} endsAt
 *
 * @typedef {object} ShopSlide
 * @property {string} id
 * @property {string} offerId
 * @property {string} tag
 * @property {string} image a professor's picture (src/assets/profs)
 *
 * @typedef {object} ShopFreeze
 * @property {string} id
 * @property {string} item
 * @property {number} days
 * @property {number} daysLeft
 * @property {string} acquiredAt
 *
 * @typedef {object} ShopEarning what a completed session earns (docs/SHOP.md, § 4 bis)
 * @property {number} crownsPerHour at the "near" tier
 * @property {number} perfectPercent
 * @property {number} nearPercent
 * @property {number} missedPercent
 * @property {number} dailyMinutes paid minutes a local day
 * @property {number} minutesLeftToday
 *
 * @typedef {object} ShopState
 * @property {number} balance
 * @property {ShopOffer[]} offers
 * @property {ShopSlide[]} slides
 * @property {Record<string, number>} inventory
 * @property {ShopFreeze[]} freezes
 * @property {ShopEarning} earning
 * @property {string|null} [dailyWheel] today's gift wheel still to spin (its daily reward's id)
 *
 * @typedef {object} ItemLook
 * @property {string} glyph
 * @property {string} tile tile background (saturated: readable in both themes)
 * @property {string} tileInk
 * @property {string} accent readable on white
 * @property {string} soft pale tint
 * @property {string} kicker
 * @property {string} label short name, in a pack's contents
 * @property {string[]} how three steps
 */

/** The store's sections, in order (the tabs scroll to them); words in shop.sections.<id>. */
export const SECTIONS = [
  'freeze',
  'puzzle_streak',
  'aids',
  'packs',
  'wheel',
  'cosmetics'
].map(id => ({
  id,
  get tab() {
    return t(`shop.sections.${id}.tab`)
  },
  get title() {
    return t(`shop.sections.${id}.title`)
  },
  get lead() {
    return t(`shop.sections.${id}.lead`)
  }
}))

/** The module aids' tabs. */
export const MODULE_TABS = [
  { id: 'puzzles', glyph: '♞︎' },
  { id: 'blindfold', glyph: '♘︎' },
  { id: 'coordinates', glyph: '⌗' }
].map(tab => ({
  ...tab,
  get label() {
    return t(`shop.moduleTabs.${tab.id}`)
  }
}))

const ICE = {
  glyph: '❄︎',
  tile: '#4FB2FF',
  tileInk: '#1B1530',
  accent: '#0F6BBA',
  soft: '#E2F2FF'
}

/**
 * An item's look with its words, read when shown (shop.items.<id>): kicker, label and the steps
 * of how it works, three by default (a freeze: shop.freezeHow, with how long it lasts).
 *
 * @param {string} id
 * @param {Pick<ItemLook, 'glyph'|'tile'|'tileInk'|'accent'|'soft'>} colours
 * @param {number} [steps] its lines in shop.items.<id>.how
 * @returns {ItemLook}
 */
function item(id, colours, steps = 3) {
  const freeze = id.startsWith('streak_freeze_')
  return {
    ...colours,
    get kicker() {
      return t(`shop.items.${id}.kicker`)
    },
    get label() {
      return t(`shop.items.${id}.label`)
    },
    get how() {
      return Array.from({ length: steps }, (_, i) =>
        freeze
          ? t(`shop.freezeHow.${i}`, { until: t(`shop.items.${id}.until`) })
          : t(`shop.items.${id}.how.${i}`)
      )
    }
  }
}

/** @type {Record<string, ItemLook>} */
export const ITEMS = {
  streak_freeze_1: item('streak_freeze_1', ICE),
  streak_freeze_2: item('streak_freeze_2', ICE),
  streak_freeze_7: item('streak_freeze_7', ICE),
  streak_freeze_14: item('streak_freeze_14', ICE),
  puzzle_streak_pass: item('puzzle_streak_pass', {
    glyph: '⇥',
    tile: '#FF6FAE',
    tileInk: '#1B1530',
    accent: '#C02670',
    soft: '#FFE6F1'
  }),
  puzzle_streak_help: item('puzzle_streak_help', {
    glyph: '♞︎',
    tile: '#FFB3D6',
    tileInk: '#1B1530',
    accent: '#C02670',
    soft: '#FFE6F1'
  }),
  puzzle_pardon: item('puzzle_pardon', {
    glyph: '♥︎',
    tile: '#FF6FAE',
    tileInk: '#1B1530',
    accent: '#C02670',
    soft: '#FFE6F1'
  }),
  blindfold_pardon: item('blindfold_pardon', {
    glyph: '♥︎',
    tile: '#5B4BA6',
    tileInk: '#FFFFFF',
    accent: '#4A3D86',
    soft: '#E7E4F2'
  }),
  coordinates_pardon: item('coordinates_pardon', {
    glyph: '♥︎',
    tile: '#8B6BFF',
    tileInk: '#FFFFFF',
    accent: '#5A3BE0',
    soft: '#EEE9FF'
  }),
  // One descriptive line rather than steps.
  wheel_ticket: item(
    'wheel_ticket',
    {
      glyph: '☸︎',
      tile: '#C6F432',
      tileInk: '#1B1530',
      accent: '#4D6B00',
      soft: '#F2FBD6'
    },
    1
  )
}

/** Numbers in the current language (docs/I18N.md). */
const number = { format: n => new Intl.NumberFormat(intlLocale()).format(n) }

/**
 * "1 250"
 *
 * @param {number} n
 */
export function crowns(n) {
  return number.format(n)
}

/**
 * The saving of a struck price: "−17 %", '' without one.
 *
 * @param {Pick<ShopOffer, 'price'|'wasPrice'>} offer
 */
export function savingOf(offer) {
  if (!offer.wasPrice || offer.wasPrice <= offer.price) return ''
  return t('shop.saving', {
    n: Math.round((1 - offer.price / offer.wasPrice) * 100)
  })
}

/**
 * An offer's look: its only item's, or the first item's for a pack (a cosmetic: a neutral one).
 *
 * @param {ShopOffer} offer
 * @returns {ItemLook}
 */
export function lookOf(offer) {
  const look = ITEMS[offer.items[0]?.item]
  if (!look)
    return {
      glyph: '✦',
      tile: '#1B1530',
      tileInk: '#C6F432',
      accent: '#5A3BE0',
      soft: '#EEE9FF',
      kicker: t('shop.cosmeticKicker'),
      label: offer.name,
      how: []
    }
  if (offer.items.length > 1)
    return {
      glyph: look.glyph,
      tile: look.tile,
      tileInk: look.tileInk,
      accent: look.accent,
      soft: look.soft,
      kicker: t('shop.packKicker'),
      label: look.label,
      how: look.how
    }
  return look
}

/**
 * A pack's contents, as chips: "3 × Pass Puzzle Streak".
 *
 * @param {ShopOffer} offer
 */
export function partsOf(offer) {
  return offer.items.map(({ item, quantity }) => {
    const look = ITEMS[item]
    return {
      item,
      glyph: look?.glyph ?? '✦',
      color: look?.accent ?? '#1B1530',
      tile: look?.tile ?? '#C6F432',
      label:
        quantity > 1
          ? t('shop.part', { n: quantity, label: look?.label ?? item })
          : (look?.label ?? item)
    }
  })
}

/**
 * How an offer works, three numbered steps (a pack: what is inside).
 *
 * @param {ShopOffer} offer
 * @returns {string[]}
 */
export function howOf(offer) {
  if (offer.items.length === 1) return lookOf(offer).how
  return [
    ...partsOf(offer).map(p => t('shop.partLine', { label: p.label })),
    t('shop.packArrives')
  ]
}

/**
 * How many of an offer's item the player holds (a pack: nothing to show).
 *
 * @param {ShopOffer} offer
 * @param {Record<string, number>} inventory
 */
export function stockOf(offer, inventory) {
  return offer.items.length === 1 ? (inventory[offer.items[0].item] ?? 0) : 0
}

/**
 * The offer to buy one more of an item in place (a puzzle streak's help or pass, out of stock):
 * the cheapest on sale holding that item alone, or null.
 *
 * @param {ShopOffer[]} offers
 * @param {string} item
 * @returns {ShopOffer|null}
 */
export function aidOffer(offers, item) {
  return (
    offers
      .filter(
        o => !o.comingSoon && o.items.length === 1 && o.items[0].item === item
      )
      .sort(
        (a, b) =>
          a.price / a.items[0].quantity - b.price / b.items[0].quantity ||
          a.price - b.price
      )[0] ?? null
  )
}

/**
 * The offers of a section (the module aids: of one module).
 *
 * @param {ShopOffer[]} offers
 * @param {string} section
 * @param {string|null} [module]
 */
export function offersIn(offers, section, module = null) {
  return offers.filter(
    o => o.section === section && (!module || o.module === module)
  )
}

/**
 * The purchase sheet's button and line: what it costs, or what is missing.
 *
 * @param {number} price
 * @param {number} quantity
 * @param {number} balance
 */
export function checkout(price, quantity, balance) {
  const total = price * quantity
  const missing = Math.max(0, total - balance)
  return missing
    ? {
        ok: false,
        total,
        label: t('shop.checkout.missing'),
        amount: missing,
        note: t('shop.checkout.missingNote')
      }
    : {
        ok: true,
        total,
        label: t('shop.checkout.buy'),
        amount: total,
        note: t(
          'shop.checkout.left',
          { n: crowns(balance - total) },
          balance - total > 1 ? 2 : 1
        )
      }
}

/**
 * Days of the player's freezes, all together (the freeze section's line).
 *
 * @param {ShopFreeze[]} freezes
 */
export function frozenDays(freezes) {
  return freezes.reduce((sum, f) => sum + f.daysLeft, 0)
}

/**
 * A count tweened from `from` to `to` (ease-out cubic), at progress `p` in [0, 1].
 *
 * @param {number} from
 * @param {number} to
 * @param {number} p
 */
export function tweenAt(from, to, p) {
  const k = 1 - Math.pow(1 - Math.min(1, Math.max(0, p)), 3)
  return Math.round(from + (to - from) * k)
}

/**
 * The carousel's slides with their offer (a slide whose offer is no longer listed is left out).
 *
 * @param {ShopState|null|undefined} state
 * @param {(image: string) => string} picture a professor's picture by its name
 * @returns {{id: string, tag: string, picture: string, offer: ShopOffer}[]}
 */
export function carouselSlides(state, picture) {
  return (state?.slides ?? []).flatMap(slide => {
    const offer = state?.offers.find(o => o.id === slide.offerId)
    return offer
      ? [{ id: slide.id, tag: slide.tag, picture: picture(slide.image), offer }]
      : []
  })
}

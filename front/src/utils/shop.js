/**
 * The store (design "Boutique", docs/SHOP.md): what each coded item looks like and how it works,
 * the sections and their order, prices and savings. Pure. The offers, the balance and the
 * inventory come from the API (GET /shop).
 *
 * @typedef {object} ShopOffer
 * @property {string} id
 * @property {string} slug
 * @property {'freeze'|'puzzle_streak'|'aids'|'packs'|'cosmetics'} section
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

/** The store's sections, in order (the tabs scroll to them). */
export const SECTIONS = [
  {
    id: 'freeze',
    tab: 'Série',
    title: 'Gel de série',
    lead: 'Un jour sans entraînement ? Ta série reste intacte.'
  },
  {
    id: 'puzzle_streak',
    tab: 'Puzzle Streak',
    title: 'Puzzle Streak',
    lead: 'Bloqué sur un puzzle ? Ton streak survit.'
  },
  {
    id: 'aids',
    tab: 'Aides',
    title: 'Aides de module',
    lead: 'Une erreur qui ne compte pas, quand ça compte.'
  },
  {
    id: 'packs',
    tab: 'Packs',
    title: 'Packs',
    lead: 'Plusieurs objets, une seule ponction.'
  },
  {
    id: 'cosmetics',
    tab: 'Cosmétiques',
    title: 'Cosmétiques',
    lead: 'Pour gagner avec style. Garde tes couronnes au chaud.'
  }
]

/** The module aids' tabs. */
export const MODULE_TABS = [
  { id: 'puzzles', label: 'Puzzles', glyph: '♞︎' },
  { id: 'blindfold', label: 'Aveugle', glyph: '♘︎' },
  { id: 'coordinates', label: 'Coordonnées', glyph: '⌗' }
]

const ICE = {
  glyph: '❄︎',
  tile: '#4FB2FF',
  tileInk: '#1B1530',
  accent: '#0F6BBA',
  soft: '#E2F2FF',
  kicker: 'GEL DE SÉRIE'
}

/**
 * @param {string} until
 * @returns {string[]}
 */
const freezeHow = until => [
  `Un jour manqué ? Le gel s’active tout seul, jusqu’à ${until}.`,
  'Une boule de gel remplace ta flamme ces jours-là ; ta série ne casse pas.',
  'Les jours qu’il n’a pas servis restent pour la prochaine fois.'
]

/** @type {Record<string, ItemLook>} */
export const ITEMS = {
  streak_freeze_1: { ...ICE, label: 'Gel 1 jour', how: freezeHow('1 jour') },
  streak_freeze_2: {
    ...ICE,
    label: 'Gel 2 jours',
    how: freezeHow('2 jours d’affilée')
  },
  streak_freeze_7: {
    ...ICE,
    label: 'Gel 1 semaine',
    how: freezeHow('7 jours d’affilée')
  },
  streak_freeze_14: {
    ...ICE,
    label: 'Gel 2 semaines',
    how: freezeHow('14 jours d’affilée')
  },
  puzzle_streak_pass: {
    glyph: '⇥',
    tile: '#FF6FAE',
    tileInk: '#1B1530',
    accent: '#C02670',
    soft: '#FFE6F1',
    kicker: 'PUZZLE STREAK',
    label: 'Pass Puzzle Streak',
    how: [
      'Tu sèches ? Avant de jouer, utilise le pass : la solution s’affiche.',
      'Ton streak n’est pas cassé.',
      'Tu enchaînes sur un puzzle équivalent, même thème, même niveau.'
    ]
  },
  puzzle_streak_help: {
    glyph: '♞︎',
    tile: '#FFB3D6',
    tileInk: '#1B1530',
    accent: '#C02670',
    soft: '#FFE6F1',
    kicker: 'PUZZLE STREAK',
    label: 'Aide Puzzle Streak',
    how: [
      'Touche « Aide » pendant ton streak.',
      'La pièce à jouer s’illumine.',
      'Tu trouves le coup : le streak continue normalement.'
    ]
  },
  puzzle_pardon: {
    glyph: '♥︎',
    tile: '#FF6FAE',
    tileInk: '#1B1530',
    accent: '#C02670',
    soft: '#FFE6F1',
    kicker: 'AIDE · PUZZLES',
    label: 'Erreur pardonnée · Puzzles',
    how: [
      'Tu rates un puzzle classé.',
      'Sur la fiche d’échec, touche « Pardonner » : aucun point perdu.',
      'Le puzzle reste raté, ton classement revient comme avant.'
    ]
  },
  blindfold_pardon: {
    glyph: '♥︎',
    tile: '#5B4BA6',
    tileInk: '#FFFFFF',
    accent: '#4A3D86',
    soft: '#E7E4F2',
    kicker: 'AIDE · AVEUGLE',
    label: 'Erreur pardonnée · Aveugle',
    how: [
      'Tu joues un coup faux, ou tu rates le puzzle, les yeux fermés.',
      'Touche « Pardonner » : tes erreurs sont effacées, ton coup d’œil revient.',
      'Le puzzle compte comme résolu, avec toute son XP.'
    ]
  },
  coordinates_pardon: {
    glyph: '♥︎',
    tile: '#8B6BFF',
    tileInk: '#FFFFFF',
    accent: '#5A3BE0',
    soft: '#EEE9FF',
    kicker: 'AIDE · COORDONNÉES',
    label: 'Erreur pardonnée · Coordonnées',
    how: [
      'Active « Pardon » en lançant ta série.',
      'Ta première mauvaise case ne compte pas.',
      'Pas d’erreur : ton pardon reste en stock.'
    ]
  }
}

const number = new Intl.NumberFormat('fr-FR')

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
  return `−${Math.round((1 - offer.price / offer.wasPrice) * 100)} %`
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
      kicker: 'COSMÉTIQUE',
      label: offer.name,
      how: []
    }
  if (offer.items.length > 1) return { ...look, kicker: 'PACK' }
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
      label: `${quantity > 1 ? `${quantity} × ` : ''}${look?.label ?? item}`
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
    ...partsOf(offer).map(p => `${p.label}.`),
    'Tout arrive dans ton inventaire.'
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
        o =>
          !o.comingSoon && o.items.length === 1 && o.items[0].item === item
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
        label: 'Il te manque',
        amount: missing,
        note: 'Encore un peu d’entraînement, et reviens me voir.'
      }
    : {
        ok: true,
        total,
        label: 'Acheter ·',
        amount: total,
        note: `Il te restera ${crowns(balance - total)} couronne${balance - total > 1 ? 's' : ''}`
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

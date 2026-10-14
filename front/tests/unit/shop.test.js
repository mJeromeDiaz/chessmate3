import { describe, expect, it } from 'vitest'
import {
  ITEMS,
  aidOffer,
  SECTIONS,
  checkout,
  crowns,
  frozenDays,
  howOf,
  lookOf,
  offersIn,
  partsOf,
  savingOf,
  stockOf,
  tweenAt
} from '@/utils/shop'

/** @returns {import('@/utils/shop').ShopOffer} */
const offer = (overrides = {}) => ({
  id: 'o1',
  slug: 'freeze_7',
  section: 'freeze',
  name: 'Gel 1 semaine',
  subtitle: 'Les vacances',
  description: '',
  items: [{ item: 'streak_freeze_7', quantity: 1 }],
  module: null,
  price: 350,
  wasPrice: 420,
  comingSoon: false,
  endsAt: null,
  ...overrides
})

describe('store', () => {
  it('knows every coded item and the sections in order', () => {
    expect(Object.keys(ITEMS)).toEqual([
      'streak_freeze_1',
      'streak_freeze_2',
      'streak_freeze_7',
      'streak_freeze_14',
      'puzzle_streak_pass',
      'puzzle_streak_help',
      'puzzle_pardon',
      'blindfold_pardon',
      'coordinates_pardon'
    ])
    for (const look of Object.values(ITEMS)) expect(look.how).toHaveLength(3)
    expect(SECTIONS.map(s => s.id)).toEqual([
      'freeze',
      'puzzle_streak',
      'aids',
      'packs',
      'cosmetics'
    ])
  })

  it('says the saving of a struck price', () => {
    expect(savingOf(offer())).toBe('−17 %')
    expect(savingOf(offer({ wasPrice: null }))).toBe('')
    expect(savingOf(offer({ wasPrice: 300 }))).toBe('')
    expect(crowns(1250)).toBe('1 250')
  })

  it('looks like its item, a pack like its first item', () => {
    expect(lookOf(offer()).kicker).toBe('GEL DE SÉRIE')
    const pack = offer({
      slug: 'pack_holidays',
      section: 'packs',
      items: [
        { item: 'streak_freeze_7', quantity: 1 },
        { item: 'puzzle_streak_pass', quantity: 3 }
      ]
    })
    expect(lookOf(pack)).toMatchObject({ kicker: 'PACK', glyph: '❄︎' })
    expect(partsOf(pack).map(p => p.label)).toEqual([
      'Gel 1 semaine',
      '3 × Pass Puzzle Streak'
    ])
    expect(howOf(pack)).toEqual([
      'Gel 1 semaine.',
      '3 × Pass Puzzle Streak.',
      'Tout arrive dans ton inventaire.'
    ])
    expect(howOf(offer())).toBe(ITEMS.streak_freeze_7.how)
    expect(lookOf(offer({ items: [], section: 'cosmetics' })).kicker).toBe(
      'COSMÉTIQUE'
    )
  })

  it('counts the stock of a single item, and filters the module aids', () => {
    expect(stockOf(offer(), { streak_freeze_7: 2 })).toBe(2)
    expect(stockOf(offer(), {})).toBe(0)
    const aids = [
      offer({ id: 'a', section: 'aids', module: 'puzzles' }),
      offer({ id: 'b', section: 'aids', module: 'blindfold' }),
      offer({ id: 'c' })
    ]
    expect(offersIn(aids, 'aids', 'blindfold').map(o => o.id)).toEqual(['b'])
    expect(offersIn(aids, 'aids').map(o => o.id)).toEqual(['a', 'b'])
  })

  it('prices the purchase, or says what is missing', () => {
    expect(checkout(60, 2, 200)).toEqual({
      ok: true,
      total: 120,
      label: 'Acheter ·',
      amount: 120,
      note: 'Il te restera 80 couronnes'
    })
    expect(checkout(60, 1, 61).note).toBe('Il te restera 1 couronne')
    expect(checkout(350, 1, 100)).toMatchObject({
      ok: false,
      label: 'Il te manque',
      amount: 250
    })
  })

  it('adds the freeze days left and tweens a count', () => {
    expect(
      frozenDays([
        {
          id: 'a',
          item: 'streak_freeze_7',
          days: 7,
          daysLeft: 2,
          acquiredAt: ''
        },
        {
          id: 'b',
          item: 'streak_freeze_1',
          days: 1,
          daysLeft: 1,
          acquiredAt: ''
        }
      ])
    ).toBe(3)
    expect(tweenAt(500, 50, 0)).toBe(500)
    expect(tweenAt(500, 50, 1)).toBe(50)
    expect(tweenAt(500, 50, 2)).toBe(50)
  })
})

describe('aidOffer', () => {
  const offer = (id, item, quantity, price, more = {}) => ({
    id,
    items: [{ item, quantity }],
    price,
    comingSoon: false,
    ...more
  })

  it('sells the cheapest single-item offer of the aid, in place', () => {
    const offers = [
      offer('pack', 'puzzle_streak_pass', 1, 10, {
        items: [
          { item: 'puzzle_streak_pass', quantity: 1 },
          { item: 'puzzle_streak_help', quantity: 1 }
        ]
      }),
      offer('soon', 'puzzle_streak_pass', 1, 5, { comingSoon: true }),
      offer('one', 'puzzle_streak_pass', 1, 80),
      offer('three', 'puzzle_streak_pass', 3, 210),
      offer('help', 'puzzle_streak_help', 1, 30)
    ]
    expect(aidOffer(offers, 'puzzle_streak_pass')?.id).toBe('three')
    expect(aidOffer(offers, 'puzzle_streak_help')?.id).toBe('help')
    expect(aidOffer(offers, 'puzzle_pardon')).toBeNull()
  })
})

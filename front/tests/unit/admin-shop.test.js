import { describe, expect, it } from 'vitest'
import {
  ITEM_OPTIONS,
  LEDGER_KINDS,
  REWARD_RULE_FIELDS,
  rewardPreview,
  fromLocalInput,
  offerForm,
  offerPayload,
  offerStatus,
  toLocalInput,
  violationOf,
  violationsOf,
  wheelAverage,
  wheelChances,
  MAX_TICKETS,
  ticketGiftValid
} from '@/utils/admin/shop'

const offer = {
  id: 'o1',
  slug: 'pack_holidays',
  section: 'packs',
  name: 'Pack Vacances',
  subtitle: null,
  description: 'Gel et pass.',
  items: [
    { item: 'streak_freeze_7', quantity: 1 },
    { item: 'puzzle_streak_pass', quantity: 3 }
  ],
  module: null,
  price: 450,
  wasPrice: 590,
  comingSoon: false,
  endsAt: null,
  position: 1,
  startsAt: null,
  suspended: false,
  listed: true,
  onSale: true,
  sales: 2,
  crowns: 900
}

describe('store administration', () => {
  it('offers every coded item', () => {
    expect(ITEM_OPTIONS.map(o => o.value)).toContain('coordinates_pardon')
    expect(ITEM_OPTIONS.find(o => o.value === 'streak_freeze_2')?.label).toBe(
      'Gel 2 jours'
    )
  })

  it('turns an offer into a form and back', () => {
    const form = offerForm(offer)
    expect(form).toMatchObject({
      slug: 'pack_holidays',
      subtitle: '',
      startsAt: ''
    })
    form.contents[1].quantity = /** @type {any} */ ('4')
    expect(offerPayload(form)).toEqual({
      slug: 'pack_holidays',
      section: 'packs',
      name: 'Pack Vacances',
      subtitle: null,
      description: 'Gel et pass.',
      contents: [
        { item: 'streak_freeze_7', quantity: 1 },
        { item: 'puzzle_streak_pass', quantity: 4 }
      ],
      price: 450,
      wasPrice: 590,
      position: 1,
      startsAt: null,
      endsAt: null,
      suspended: false,
      comingSoon: false
    })
    const blank = offerForm(null)
    expect(blank).toMatchObject({ slug: '', section: 'packs', price: null })
    expect(offerPayload(blank).price).toBeNull()
  })

  it('reads a window in the browser time zone', () => {
    const iso = '2026-12-24T18:30:00.000Z'
    expect(fromLocalInput(toLocalInput(iso))).toBe(iso)
    expect(toLocalInput(null)).toBe('')
    expect(fromLocalInput('')).toBeNull()
  })

  it('says where an offer stands', () => {
    const now = new Date('2026-10-13T12:00:00Z')
    expect(offerStatus(offer, now)).toEqual({ label: 'En vente', tone: 'used' })
    expect(offerStatus({ ...offer, suspended: true }, now).label).toBe(
      'Suspendue'
    )
    expect(
      offerStatus(
        { ...offer, listed: false, startsAt: '2026-11-01T00:00:00Z' },
        now
      ).label
    ).toBe('Programmée')
    expect(offerStatus({ ...offer, listed: false }, now).label).toBe('Terminée')
    expect(offerStatus({ ...offer, comingSoon: true }, now).label).toBe(
      'Bientôt'
    )
  })

  it('finds a field’s message among the violations', () => {
    const error = {
      response: {
        status: 422,
        data: {
          violations: [
            { propertyPath: 'slug', title: 'Pris.' },
            { propertyPath: 'slug', title: 'Trop long.' },
            { propertyPath: 'price', title: 'Obligatoire.' }
          ]
        }
      }
    }
    const violations = violationsOf(error)
    expect(violationOf(violations, 'slug')).toBe('Pris. Trop long.')
    expect(violationOf(violations, 'name')).toBe('')
    expect(violationsOf({ response: { status: 409, data: {} } })).toEqual([])
  })
})

describe('the reward rule', () => {
  it('previews what 20 paid minutes earn at each tier', () => {
    const rule = {
      crownsPerHour: 120,
      perfectPercent: 150,
      nearPercent: 100,
      missedPercent: 50
    }
    expect(rewardPreview(rule, 20)).toEqual({
      perfect: 60,
      near: 40,
      missed: 20
    })
    expect(rewardPreview({ ...rule, crownsPerHour: 0 }, 20).perfect).toBe(0)
    expect(REWARD_RULE_FIELDS.map(f => f.key)).toEqual([
      'crownsPerHour',
      'perfectPercent',
      'nearPercent',
      'missedPercent',
      'dailyMinutes'
    ])
    expect(LEDGER_KINDS.reward).toBe('Session')
  })
})

describe('the gift wheel odds (docs/SHOP.md, § 4 ter)', () => {
  it('shows each prize chance and the crystals of a spin on average', () => {
    const defaults = { c5: 75, c30: 12, c300: 1, gift: 7, lose: 5 }
    expect(wheelChances(defaults)).toEqual({
      c5: 75,
      c30: 12,
      c300: 1,
      gift: 7,
      lose: 5
    })
    // 3.75 + 3.6 + 3
    expect(wheelAverage(defaults)).toBe(10.4)
    expect(wheelChances({ c5: 1, c30: 2, c300: 0, gift: 0, lose: 0 })).toEqual({
      c5: 33.3,
      c30: 66.7,
      c300: 0,
      gift: 0,
      lose: 0
    })
    expect(wheelChances({})).toEqual({ c5: 0, c30: 0, c300: 0, gift: 0, lose: 0 })
  })
})

describe('gift wheel tickets given (docs/SHOP.md, § 4 quater)', () => {
  it('needs 1 to 100 tickets and a message for the players', () => {
    expect(ticketGiftValid(2, 'Joyeux Noël !')).toBe(true)
    expect(ticketGiftValid('3', 'x')).toBe(true)
    expect(ticketGiftValid(MAX_TICKETS, 'x')).toBe(true)
    expect(ticketGiftValid(0, 'x')).toBe(false)
    expect(ticketGiftValid(MAX_TICKETS + 1, 'x')).toBe(false)
    expect(ticketGiftValid(1.5, 'x')).toBe(false)
    expect(ticketGiftValid(null, 'x')).toBe(false)
    expect(ticketGiftValid(2, '   ')).toBe(false)
    expect(ticketGiftValid(2, 'x'.repeat(256))).toBe(false)
  })
})

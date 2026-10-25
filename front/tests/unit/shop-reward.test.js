import { describe, expect, it } from 'vitest'
import {
  CHEST_HITS,
  chestSize,
  chestStages,
  chestToOpen,
  dailyChestNote,
  dailyChestReward,
  dailyChestWords,
  dailyMax,
  earningRows,
  rewardLine,
  rewardNote
} from '@/utils/shopReward'

const EARNING = {
  crownsPerHour: 120,
  perfectPercent: 150,
  nearPercent: 100,
  missedPercent: 50,
  dailyMinutes: 60,
  minutesLeftToday: 60
}

/** @param {Partial<import('@/utils/shopReward').SessionReward>} over */
const reward = over => ({
  id: 'r1',
  crowns: 60,
  paidMinutes: 20,
  tier: /** @type {'perfect'} */ ('perfect'),
  capped: false,
  opened: false,
  ...over
})

describe('the chest (design "Coffre")', () => {
  it('measures a reward against the day at its best', () => {
    expect(dailyMax(EARNING)).toBe(180)
    expect(dailyMax(null)).toBe(180)
    expect(dailyMax({ ...EARNING, dailyMinutes: 30 })).toBe(90)
    expect([10, 44, 45, 107, 108, 180].map(c => chestSize(c, EARNING))).toEqual(
      ['small', 'small', 'medium', 'medium', 'big', 'big']
    )
  })

  it('colours the closed chest by amount and titles the open one by tier', () => {
    const small = chestStages(reward({ crowns: 9 }), EARNING)
    const big = chestStages(reward({ crowns: 150 }), EARNING)
    expect(small).toHaveLength(CHEST_HITS + 1)
    expect([
      small[0].bg,
      chestStages(reward({}), EARNING)[0].bg,
      big[0].bg
    ]).toEqual(['#1E2A4A', '#2B2250', '#4A1550'])
    expect(big[0].title).toEqual(['Un gros trésor', 'pour toi !'])
    expect(small[1]).toEqual(big[1])
    expect(
      ['perfect', 'near', 'missed'].map(
        tier => chestStages(reward({ tier }), EARNING)[3].title
      )
    ).toEqual([
      ['Quel', 'jackpot !'],
      ['Beau', 'trésor !'],
      ['Joli', 'butin !']
    ])
    const words = big.flatMap(s => [...s.title, s.kicker]).join(' ')
    expect(words).not.toMatch(/champion/i)
    expect(big[0].kicker).toBe('RÉCOMPENSE DE SESSION')
  })

  it('opens only for a completed session with crowns not seen yet', () => {
    expect(chestToOpen({ status: 'completed', reward: reward({}) })).toBe(true)
    expect(
      chestToOpen({ status: 'completed', reward: reward({ opened: true }) })
    ).toBe(false)
    expect(
      chestToOpen({ status: 'completed', reward: reward({ crowns: 0 }) })
    ).toBe(false)
    expect(chestToOpen({ status: 'abandoned', reward: null })).toBe(false)
    expect(chestToOpen(null)).toBe(false)
    // Seen in this tab while the server was still marking it.
    expect(
      chestToOpen({ status: 'completed', reward: reward({}) }, new Set(['r1']))
    ).toBe(false)
  })
})

describe('the reward in words', () => {
  it('says the paid time, the tier and the cap', () => {
    expect(rewardNote(reward({}))).toBe('20 min payées · Session parfaite')
    expect(
      rewardNote(reward({ paidMinutes: 1, tier: 'missed', capped: true }))
    ).toBe('1 min payée · Session terminée · plafond du jour atteint')
    expect(rewardLine(reward({ crowns: 1250 }))).toBe(
      '+1 250 cristaux · 20 min payées · Session parfaite'
    )
    expect(rewardLine(reward({ crowns: 0, capped: true }))).toMatch(
      /^Plafond du jour atteint/
    )
    expect(rewardLine(null)).toBe('')
  })

  it('lists what an hour pays by tier in the store', () => {
    expect(earningRows(EARNING).map(r => r.value)).toEqual([180, 120, 60])
  })
})

/** @param {Partial<import('@/utils/shopReward').DailyReward>} over */
const daily = over => ({
  id: 'd1',
  localDate: '2026-10-21',
  streak: 5,
  badgeDays: null,
  dailyCrowns: 9,
  badgeCrowns: 0,
  crowns: 9,
  opened: false,
  ...over
})

describe('the daily chest (docs/SHOP.md, § 4 ter)', () => {
  it('says the day, or the streak badge and its bonus', () => {
    const plain = daily({})
    expect(dailyChestWords(plain)).toEqual({
      kicker: 'COFFRE DU JOUR',
      openTitle: ['Coffre', 'du jour !']
    })
    expect(dailyChestNote(plain)).toBe('Série de 5 jours')
    expect(dailyChestNote(daily({ streak: 1 }))).toBe('Série de 1 jour')

    const badge = daily({
      streak: 7,
      badgeDays: 7,
      badgeCrowns: 70,
      crowns: 79
    })
    expect(dailyChestWords(badge)).toEqual({
      kicker: 'COFFRE DE SÉRIE · 7 JOURS',
      openTitle: ['7 jours', 'de série !']
    })
    expect(dailyChestNote(badge)).toBe('9 du jour + 70 pour le badge 7 jours')
    // Since the gift wheel: the badge's bonus alone.
    expect(
      dailyChestNote(
        daily({ badgeDays: 7, dailyCrowns: 0, badgeCrowns: 70, crowns: 70 })
      )
    ).toBe('Le bonus du badge 7 jours')
  })

  it('opens on the chest screen with its own words, sized by its amount', () => {
    const reward = dailyChestReward(daily({ crowns: 79, opened: true }))
    expect(reward).toEqual({
      id: 'd1',
      crowns: 79,
      paidMinutes: 0,
      tier: 'perfect',
      capped: false,
      opened: true
    })
    const stages = chestStages(
      reward,
      EARNING,
      dailyChestWords(daily({ badgeDays: 7 }))
    )
    expect([stages[0].kicker, stages[0].bg]).toEqual([
      'COFFRE DE SÉRIE · 7 JOURS',
      '#2B2250'
    ])
    expect(stages[3].title).toEqual(['7 jours', 'de série !'])
    // The hits in between stay the session's.
    expect(stages[1].kicker).toBe('ÇA BOUGE…')
  })
})

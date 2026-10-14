import { describe, expect, it } from 'vitest'
import {
  CHEST_HITS,
  chestSize,
  chestStages,
  chestToOpen,
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
    expect([small[0].bg, chestStages(reward({}), EARNING)[0].bg, big[0].bg]).toEqual(
      ['#1E2A4A', '#2B2250', '#4A1550']
    )
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
    expect(rewardNote(reward({ paidMinutes: 1, tier: 'missed', capped: true }))).toBe(
      '1 min payée · Session terminée · plafond du jour atteint'
    )
    expect(rewardLine(reward({ crowns: 1250 }))).toBe(
      '+1 250 couronnes · 20 min payées · Session parfaite'
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

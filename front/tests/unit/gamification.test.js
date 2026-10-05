import { describe, expect, it } from 'vitest'
import {
  TROPHIES,
  levelBar,
  moduleLevel,
  questProf,
  questText,
  trophyCards
} from '@/utils/gamification'
import { MODULES_BY_ID } from '@/utils/session/catalog'

/**
 * @param {Partial<import('@/utils/gamification').Summary>} [overrides]
 * @returns {import('@/utils/gamification').Summary}
 */
function summary(overrides = {}) {
  return {
    xp: 30_340,
    level: 12,
    xpInLevel: 2340,
    xpForNext: 3000,
    rank: 'Tacticien',
    nextRank: { rank: 'Stratège', level: 15 },
    modules: {
      puzzles: { xp: 2100, level: 6, xpInLevel: 0, xpForNext: 600 },
      free: { xp: 0, level: 1, xpInLevel: 0, xpForNext: 100 }
    },
    streak: { current: 3, best: 21, playedToday: true },
    today: { exerciseXp: 40, cap: 500 },
    ...overrides
  }
}

/**
 * @param {Partial<import('@/utils/gamification').Quest>} overrides
 * @returns {import('@/utils/gamification').Quest}
 */
function quest(overrides) {
  return {
    id: 'q1',
    template: 'rated_puzzles',
    theme: null,
    module: null,
    goal: 40,
    current: 12,
    reward: 150,
    completed: false,
    completedAt: null,
    weekStart: '2026-10-05',
    weekEnd: '2026-10-11',
    ...overrides
  }
}

describe('levelBar', () => {
  it('gives the level, the XP in the level and what is left before the next rank', () => {
    const bar = levelBar(summary())
    expect(bar).toMatchObject({ level: 12, rank: 'Tacticien', percent: 78 })
    expect(bar.xpLabel).toBe('2 340 / 3 000 XP')
    expect(bar.next).toBe(
      'Encore 660 XP avant le niveau 13 · « Stratège » au niveau 15'
    )
  })

  it('stops at the last rank and never overflows the bar', () => {
    const bar = levelBar(
      summary({ nextRank: null, xpInLevel: 9000, xpForNext: 7500 })
    )
    expect(bar.percent).toBe(100)
    expect(bar.next).toBe('Encore 0 XP avant le niveau 13')
  })
})

describe('moduleLevel', () => {
  it('maps a catalogue module to its API level, 1 for a module never played', () => {
    expect(moduleLevel(summary(), 'puzzles')).toBe(6)
    expect(moduleLevel(summary(), 'libre')).toBe(1)
    expect(moduleLevel(summary(), 'woodpecker')).toBe(1)
  })

  it('has no level for a module the API does not know, nor before the summary', () => {
    expect(moduleLevel(summary(), 'finales')).toBeNull()
    expect(moduleLevel(null, 'puzzles')).toBeNull()
  })
})

describe('trophyCards', () => {
  it('dates a won trophy and gives the progress of a locked one', () => {
    const cards = trophyCards([
      {
        key: 'first_step',
        goal: 1,
        current: 1,
        unlocked: true,
        unlockedAt: '2026-09-14T08:00:00+00:00',
        ratio: null
      },
      {
        key: 'centurion',
        goal: 1000,
        current: 250,
        unlocked: false,
        unlockedAt: null,
        ratio: null
      },
      {
        key: 'iron_memory',
        goal: 50,
        current: 30,
        unlocked: false,
        unlockedAt: null,
        ratio: 0.913
      },
      {
        key: 'unknown',
        goal: 1,
        current: 0,
        unlocked: false,
        unlockedAt: null,
        ratio: null
      }
    ])
    expect(cards.map(c => c.key)).toEqual([
      'first_step',
      'centurion',
      'iron_memory'
    ])
    expect(cards[0]).toMatchObject({
      name: TROPHIES.first_step.name,
      unlocked: true,
      progress: 'Le 14 sept. 2026'
    })
    expect(cards[1]).toMatchObject({ progress: '250/1 000', percent: 25 })
    expect(cards[2]).toMatchObject({
      progress: '30/50 tests · 91 %',
      percent: 60
    })
  })
})

describe('questText', () => {
  const themeLabel = (/** @type {string} */ key) =>
    ({ fork: 'Fourchette' })[key] ?? key

  it('says each template with its goal', () => {
    expect(questText(quest({}), themeLabel)).toBe(
      'Résous 40 puzzles classés cette semaine.'
    )
    expect(
      questText(
        quest({ template: 'weak_theme', theme: 'fork', goal: 10 }),
        themeLabel
      )
    ).toBe(
      'Résous 10 puzzles « Fourchette » sans aide : c’est ton point faible du moment.'
    )
    expect(questText(quest({ template: 'sessions', goal: 3 }), themeLabel)).toBe(
      'Mène 3 sessions au bout cette semaine.'
    )
    expect(
      questText(quest({ template: 'woodpecker', goal: 60 }), themeLabel)
    ).toBe('Joue 60 puzzles de ton set Woodpecker.')
    expect(
      questText(quest({ template: 'repertoire', goal: 20 }), themeLabel)
    ).toBe('Réussis 20 tronçons de ton répertoire.')
    expect(
      questText(quest({ template: 'active_days', goal: 5 }), themeLabel)
    ).toBe('Entraîne-toi 5 jours cette semaine.')
  })
})

describe('questProf', () => {
  it('gives the module’s professor, Lizy for a quest across modules', () => {
    expect(questProf(quest({ module: 'woodpecker' })).name).toBe(
      MODULES_BY_ID.woodpecker.prof
    )
    expect(questProf(quest({ module: null })).name).toBe('Lizy')
  })
})

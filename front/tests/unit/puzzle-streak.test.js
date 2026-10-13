import { describe, expect, it } from 'vitest'
import {
  filterTopics,
  levelAt,
  rampText,
  recordOf,
  streakText,
  topicLabel,
  topicOptions,
  wonBadges
} from '@/utils/puzzleStreak'

const RULES = {
  ladder: { start: 600, step: 30, max: 3000 },
  tiers: { start: 600, width: 200, size: 5, last: 2600 },
  topics: [
    { key: 'rookEndgame', category: 'endgame' },
    { key: 'mateIn2', category: 'mate' },
    { key: 'fork', category: 'motif' },
    { key: 'newTheme', category: 'motif' }
  ],
  minOpeningPuzzles: 30,
  singleBudgetSeconds: 3600,
  recordMin: 5
}

const OPENINGS = {
  mine: [
    {
      topic: 'opening:Sicilian_Defense:b',
      tag: 'Sicilian_Defense',
      name: 'Sicilian Defense',
      color: 'black',
      sources: ['games'],
      puzzles: 120
    }
  ],
  all: [
    { tag: 'Italian_Game', name: 'Italian Game', white: 40, black: 12 },
    { tag: 'Sicilian_Defense', name: 'Sicilian Defense', white: 35, black: 120 }
  ]
}

describe('puzzle streak', () => {
  it('names the topics, openings with their side', () => {
    expect(topicLabel('general')).toBe('Général')
    expect(topicLabel('theme:rookEndgame')).toBe('Finales de tours')
    expect(topicLabel('theme:newTheme')).toBe('newTheme')
    expect(topicLabel('opening:Italian_Game:w', OPENINGS)).toBe(
      'Italian Game (Blancs)'
    )
    // Without the openings, the tag reads as words.
    expect(topicLabel('opening:Queens_Pawn_Game:b')).toBe(
      'Queens Pawn Game (Noirs)'
    )
  })

  it('offers general, the themes by category, my openings, then every side with enough puzzles', () => {
    const options = topicOptions(RULES, OPENINGS)
    expect(options.map(o => [o.group, o.value])).toEqual([
      ['Général', 'general'],
      ['Finales', 'theme:rookEndgame'],
      ['Mats', 'theme:mateIn2'],
      ['Motifs tactiques', 'theme:fork'],
      ['Motifs tactiques', 'theme:newTheme'],
      ['Mes ouvertures', 'opening:Sicilian_Defense:b'],
      ['Toutes les ouvertures', 'opening:Italian_Game:w'],
      ['Toutes les ouvertures', 'opening:Sicilian_Defense:w'],
      ['Toutes les ouvertures', 'opening:Sicilian_Defense:b']
    ])
    expect(topicOptions(RULES).map(o => o.value)).toHaveLength(5)
  })

  it('searches the topics, accents and case ignored', () => {
    const options = topicOptions(RULES, OPENINGS)
    expect(filterTopics(options, 'sicil').map(o => o.value)).toEqual([
      'opening:Sicilian_Defense:b',
      'opening:Sicilian_Defense:w',
      'opening:Sicilian_Defense:b'
    ])
    expect(filterTopics(options, 'general').map(o => o.value)).toEqual([
      'general'
    ])
    expect(filterTopics(options, '  ')).toHaveLength(options.length)
  })

  it('tells the difficulty a streak is at', () => {
    expect(levelAt('ladder', 0, RULES)).toBe('≈ 600')
    expect(levelAt('ladder', 10, RULES)).toBe('≈ 900')
    expect(levelAt('ladder', 500, RULES)).toBe('≈ 3000')
    expect(levelAt('tiers', 4, RULES)).toBe('Palier 1 · 600–800')
    expect(levelAt('tiers', 12, RULES)).toBe('Palier 3 · 1000–1200')
    expect(levelAt('tiers', 60, RULES)).toBe('Palier 13 · 2600+')
    expect(rampText('ladder', RULES)).toBe(
      'Dès 600, +30 points par puzzle réussi'
    )
    expect(rampText('tiers', RULES)).toBe(
      '5 puzzles par palier de 200 points, dès 600'
    )
  })

  it('finds the record of a ramp and topic', () => {
    const records = [
      { ramp: 'ladder', topic: 'general', best: 12, streaks: 4, at: null }
    ]
    expect(recordOf(records, 'ladder', 'general')).toBe(12)
    expect(recordOf(records, 'tiers', 'general')).toBe(0)
    expect(streakText(1)).toBe('1 puzzle d’affilée')
    expect(streakText(12)).toBe('12 puzzles d’affilée')
  })

  it('names the badges a run won, shortest first', () => {
    expect(
      wonBadges({ badges: ['streak_puzzles_30', 'streak_puzzles_20'] })
    ).toEqual([
      { key: 'streak_puzzles_20', goal: 20, name: 'Rafale' },
      { key: 'streak_puzzles_30', goal: 30, name: 'Déferlante' }
    ])
    expect(wonBadges({ badges: [] })).toEqual([])
    expect(wonBadges({})).toEqual([])
    expect(wonBadges(null)).toEqual([])
  })
})

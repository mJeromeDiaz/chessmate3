import { describe, expect, it } from 'vitest'
import {
  badgeCrystals,
  badgeRow,
  dealContents,
  dealEnds,
  dealsOf,
  monthCounts,
  monthGrid,
  pathPercent,
  pathTrack,
  proposedPath
} from '@/utils/dashboard/home'

/** A step of a path. */
const step = (key, status = 'locked', kind = 'exercise') => ({
  key,
  number: key,
  kind,
  status,
  passes: 0,
  required: 2,
  crystals: kind === 'exercise' ? 0 : 100,
  xp: 0,
  character: kind === 'exercise' ? null : 'jongleur',
  badges: [],
  skippable: false,
  chest: null
})

/** A path of one chapter: its steps in one section, then its boss. */
const path = (capacity, steps, boss, lastPlayedAt = null) => ({
  capacity,
  lastPlayedAt,
  badges: [],
  unopenedChests: [],
  chapters: [
    {
      key: 'c1',
      number: 1,
      status: 'open',
      sections: [
        {
          key: '1',
          number: null,
          status: 'open',
          steps,
          miniBosses: [],
          hidden: []
        }
      ],
      boss
    }
  ]
})

describe('the proposed path', () => {
  const fresh = name =>
    path(
      name,
      [step('1-1', 'open'), step('1-2')],
      step('1-boss', 'open', 'boss')
    )
  const started = (name, at) =>
    path(
      name,
      [step('1-1', 'done'), step('1-2', 'open')],
      step('1-boss', 'open', 'boss'),
      at
    )
  const finished = name =>
    path(
      name,
      [step('1-1', 'done'), step('1-2', 'done')],
      step('1-boss', 'done', 'boss'),
      '2026-10-20T10:00:00+00:00'
    )

  it('is the one played last among those started and not finished', () => {
    const calculation = started('calculation', '2026-10-20T10:00:00+00:00')
    const visualisation = started('visualisation', '2026-10-24T10:00:00+00:00')
    expect(proposedPath([calculation, visualisation])).toBe(visualisation)
    expect(
      proposedPath([finished('visualisation'), calculation])?.capacity
    ).toBe('calculation')
  })

  it('invites to the first one when none is started, none when all are finished', () => {
    expect(
      proposedPath([fresh('visualisation'), fresh('calculation')])?.capacity
    ).toBe('visualisation')
    expect(proposedPath([finished('visualisation')])).toBeNull()
  })
})

describe('the path track', () => {
  it('keeps the step before, the current one, the next one and the boss', () => {
    const p = path(
      'calculation',
      [
        step('1-1', 'done'),
        step('1-2', 'skipped'),
        step('1-chest', 'locked', 'chest'),
        step('1-3', 'open'),
        step('1-4'),
        step('1-5')
      ],
      step('1-boss', 'open', 'boss')
    )
    const track = pathTrack(p)
    expect([
      track?.previous?.key,
      track?.current.key,
      track?.next?.key,
      track?.boss?.key
    ]).toEqual(['1-2', '1-3', '1-4', '1-boss'])
    expect(pathPercent(p)).toBe(33)
  })

  it('stands on the boss: no next, the boss is the current step', () => {
    const p = path(
      'calculation',
      [step('1-1', 'done'), step('1-2', 'done')],
      step('1-boss', 'open', 'boss')
    )
    // The boss is open from the start: the first open step is the boss once the exercises are done.
    const track = pathTrack(p)
    expect([
      track?.previous?.key,
      track?.current.key,
      track?.next,
      track?.boss?.key
    ]).toEqual(['1-2', '1-boss', null, '1-boss'])
  })

  it('skips the mini-bosses for the chapter boss, unless the player stands on one', () => {
    const p = path(
      'calculation',
      [step('1-1', 'done'), step('1-2', 'open'), step('1-3')],
      step('1-boss', 'locked', 'boss')
    )
    p.chapters[0].sections[0].miniBosses = [
      step('1-mini', 'locked', 'mini_boss')
    ]
    expect(pathTrack(p)?.boss?.key).toBe('1-boss')
    p.chapters[0].sections[0].steps[1].status = 'done'
    p.chapters[0].sections[0].steps[2].status = 'done'
    p.chapters[0].sections[0].miniBosses[0].status = 'open'
    const track = pathTrack(p)
    expect([track?.current.key, track?.next, track?.boss?.key]).toEqual([
      '1-mini',
      null,
      '1-boss'
    ])
  })

  it('does not repeat the boss as the next step', () => {
    const p = path(
      'calculation',
      [step('1-1', 'done'), step('1-2', 'open')],
      step('1-boss', 'locked', 'boss')
    )
    expect(pathTrack(p)?.next).toBeNull()
    expect(
      pathTrack(
        path('x', [step('1-1', 'done')], step('1-boss', 'done', 'boss'))
      )
    ).toBeNull()
  })
})

describe('the store deals', () => {
  const now = new Date('2026-10-25T10:00:00Z')
  const offer = (id, extra = {}) => ({
    id,
    slug: id,
    section: 'packs',
    name: id,
    subtitle: null,
    description: '',
    items: [{ item: 'streak_freeze_7', quantity: 1 }],
    module: null,
    price: 100,
    wasPrice: null,
    comingSoon: false,
    endsAt: null,
    ...extra
  })

  it('keeps the offers on promotion, the soonest to end first, three at most', () => {
    const deals = dealsOf(
      [
        offer('plain'),
        offer('struck', { wasPrice: 150 }),
        offer('soon', { endsAt: '2026-10-26T10:00:00Z' }),
        offer('later', { endsAt: '2026-10-30T10:00:00Z', wasPrice: 120 }),
        offer('ended', { endsAt: '2026-10-24T10:00:00Z' }),
        offer('coming', { wasPrice: 200, comingSoon: true }),
        offer('struck2', { wasPrice: 300 })
      ],
      now
    )
    expect(deals.map(d => d.id)).toEqual(['soon', 'later', 'struck2'])
  })

  it('says what a deal holds and when it ends', () => {
    expect(
      dealContents(
        offer('pack', {
          items: [
            { item: 'streak_freeze_7', quantity: 1 },
            { item: 'puzzle_streak_pass', quantity: 3 }
          ]
        })
      )
    ).toMatch(/\+ 3 ×/)
    expect(dealEnds('2026-10-27T12:00:00Z', now)).toBe('Fin dans 2 j')
    expect(dealEnds('2026-10-25T15:30:00Z', now)).toBe('Fin dans 5 h')
    expect(dealEnds('2026-10-25T10:30:00Z', now)).toBe('Dernière heure')
    expect(dealEnds(null, now)).toBe('')
  })
})

describe('the streak sheet', () => {
  it('lays the month out from Monday, today marked', () => {
    // October 2026 starts on a Thursday.
    const month = {
      first: '2026-10-01',
      days: ['played', 'frozen', 'missed', ...Array(28).fill('future')]
    }
    const grid = monthGrid(month, '2026-10-03')
    expect(grid.slice(0, 3).every(c => c.day === null)).toBe(true)
    expect(grid[3]).toEqual({ day: 1, state: 'played', today: false })
    expect(grid[5]).toEqual({ day: 3, state: 'missed', today: true })
    expect(grid).toHaveLength(3 + 31)
    expect(monthCounts(month)).toEqual({ played: 1, frozen: 1 })
    expect(monthGrid(undefined, '2026-10-03')).toEqual([])
  })

  it('shows four badges around the streak and the next badge chest', () => {
    expect(badgeRow(12).map(b => [b.goal, b.state])).toEqual([
      [3, 'reached'],
      [7, 'reached'],
      [14, 'next'],
      [30, 'later']
    ])
    expect(badgeRow(0).map(b => [b.goal, b.state])).toEqual([
      [3, 'next'],
      [7, 'later'],
      [14, 'later'],
      [30, 'later']
    ])
    expect(badgeRow(40).map(b => b.goal)).toEqual([14, 30, 50, 100])
    expect(badgeRow(5000).map(b => b.state)).toEqual(Array(4).fill('reached'))
    expect(badgeRow(5000).map(b => b.goal)).toEqual([365, 450, 500, 1000])
    expect([badgeCrystals(7), badgeCrystals(100), badgeCrystals(500)]).toEqual([
      70, 1000, 1000
    ])
  })
})

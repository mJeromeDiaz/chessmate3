import { describe, expect, it } from 'vitest'
import {
  barPath,
  durationScale,
  formatWait,
  funnelRows,
  layoutColumns,
  niceScale,
  plural,
  ratingColumns,
  signupColumns,
  weeklyTimeColumns
} from '@/utils/admin/charts'

describe('niceScale', () => {
  it('rounds the top to a step of 1, 2 or 5 × 10^n', () => {
    expect(niceScale(7)).toEqual({ max: 8, ticks: [0, 2, 4, 6, 8] })
    expect(niceScale(37)).toEqual({ max: 40, ticks: [0, 10, 20, 30, 40] })
    expect(niceScale(130).max).toBe(150)
  })

  it('never steps by less than one (counts are whole)', () => {
    expect(niceScale(1)).toEqual({ max: 1, ticks: [0, 1] })
    expect(niceScale(3)).toEqual({ max: 3, ticks: [0, 1, 2, 3] })
  })

  it('draws an empty axis for no data', () => {
    expect(niceScale(0)).toEqual({ max: 4, ticks: [0, 1, 2, 3, 4] })
  })
})

describe('durationScale', () => {
  it('rounds to 15 min, 30 min, 1 h, then whole hours', () => {
    expect(durationScale(10 * 60_000).max).toBe(15 * 60_000)
    expect(durationScale(50 * 60_000).max).toBe(60 * 60_000)
    expect(durationScale(130 * 60_000)).toEqual({
      max: 3 * 3_600_000,
      ticks: [0, 1.5 * 3_600_000, 3 * 3_600_000]
    })
  })
})

describe('layoutColumns', () => {
  const series = [{ id: 'a' }, { id: 'b' }]

  it('stacks the segments bottom first, with a gap, keeping the column’s full height', () => {
    const [col] = layoutColumns(
      [{ key: 'd1', label: 'd1', values: { a: 2, b: 2 } }],
      series,
      { width: 100, height: 100, max: 4 }
    )

    expect(col.width).toBe(24)
    expect(col.x).toBe(38)
    expect(col.segments).toEqual([
      { id: 'a', y: 50, height: 50, top: false },
      { id: 'b', y: 0, height: 48, top: true }
    ])
  })

  it('leaves empty series out, the last drawn one being the rounded end', () => {
    const [col] = layoutColumns(
      [{ key: 'd1', label: 'd1', values: { a: 1, b: 0 } }],
      series,
      { width: 10, height: 100, max: 4 }
    )

    expect(col.segments).toEqual([{ id: 'a', y: 75, height: 25, top: true }])
  })

  it('drops the gap when the slots are too thin to afford it', () => {
    const cols = Array.from({ length: 100 }, (_, i) => ({
      key: String(i),
      label: '',
      values: { a: 1 }
    }))

    const placed = layoutColumns(cols, series, {
      width: 300,
      height: 100,
      max: 1
    })

    expect(placed[0].width).toBe(3)
    expect(placed[1].x).toBe(3)
  })

  it('lays nothing out without room or scale', () => {
    expect(
      layoutColumns([], series, { width: 100, height: 100, max: 1 })
    ).toEqual([])
    expect(
      layoutColumns([{ key: 'k', label: '', values: {} }], series, {
        width: 0,
        height: 100,
        max: 1
      })
    ).toEqual([])
  })
})

describe('barPath', () => {
  it('rounds the data end only', () => {
    expect(barPath(0, 10, 20, 30, false)).toBe('M0,40 V10 H20 V40 Z')
    expect(barPath(0, 10, 20, 30, true)).toBe(
      'M0,40 V14 Q0,10 4,10 H16 Q20,10 20,14 V40 Z'
    )
  })
})

describe('columns from the API', () => {
  it('turns days, weeks and bands into columns', () => {
    expect(
      signupColumns([
        {
          date: '2026-10-05',
          total: 2,
          byMethod: { password: 1, google: 1, lichess: 0, other: 0 }
        }
      ])
    ).toEqual([
      {
        key: '2026-10-05',
        label: '5 oct.',
        values: { password: 1, google: 1, lichess: 0, other: 0 }
      }
    ])
    expect(
      weeklyTimeColumns([
        {
          start: '2026-09-28',
          activePlayers: 0,
          durationMs: 0,
          averageMs: null
        }
      ])[0].values
    ).toEqual({ value: 0 })
    expect(ratingColumns([{ from: 1700, count: 3 }], 100)).toEqual([
      { key: '1700', label: '1700–1799', values: { value: 3 } }
    ])
  })
})

describe('funnelRows', () => {
  it('measures every row against the invitations created', () => {
    const rows = funnelRows({
      created: 4,
      pending: 1,
      used: 2,
      expired: 1,
      revoked: 0,
      emailFailed: 0
    })

    expect(rows.map(r => [r.id, r.count, r.share])).toEqual([
      ['created', 4, 1],
      ['used', 2, 0.5],
      ['pending', 1, 0.25],
      ['expired', 1, 0.25],
      ['revoked', 0, 0],
      ['emailFailed', 0, 0]
    ])
  })

  it('has no share without invitations', () => {
    expect(
      funnelRows({
        created: 0,
        pending: 0,
        used: 0,
        expired: 0,
        revoked: 0,
        emailFailed: 0
      })[0].share
    ).toBe(0)
  })
})

describe('formatting', () => {
  it('writes waits in the largest sensible unit', () => {
    expect(formatWait(null)).toBe('—')
    expect(formatWait(20)).toBe('1 min')
    expect(formatWait(45 * 60)).toBe('45 min')
    expect(formatWait(5 * 3600)).toBe('5 h')
    expect(formatWait(3 * 86_400)).toBe('3 j')
  })

  it('agrees nouns with their count', () => {
    expect(plural(0, 'inactif', 'inactifs')).toBe('0 inactif')
    expect(plural(1, 'inactif', 'inactifs')).toBe('1 inactif')
    expect(plural(3, 'inactif', 'inactifs')).toBe('3 inactifs')
  })
})

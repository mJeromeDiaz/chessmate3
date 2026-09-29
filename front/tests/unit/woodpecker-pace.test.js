import { describe, expect, it } from 'vitest'
import { daysLeft, localDate, pace } from '@/utils/woodpeckerPace'

const PARIS = 'Europe/Paris'
// End of 2026-10-28 in Paris (UTC+1 after the DST change of the 25th).
const DEADLINE = new Date('2026-10-28T23:00:00Z')

describe('woodpecker pace', () => {
  it('reads the local day in the user timezone, not the browser one', () => {
    expect(localDate(new Date('2026-07-14T22:30:00Z'), PARIS)).toBe(
      '2026-07-15'
    )
    expect(
      localDate(new Date('2026-07-14T22:30:00Z'), 'America/New_York')
    ).toBe('2026-07-14')
  })

  it('counts local days left, today included, like the server', () => {
    expect(daysLeft(new Date('2026-10-20T06:00:00Z'), DEADLINE, PARIS)).toBe(9)
    // 23:30 UTC on the 19th is already the 20th in Paris.
    expect(daysLeft(new Date('2026-10-19T22:30:00Z'), DEADLINE, PARIS)).toBe(9)
    expect(daysLeft(new Date('2026-10-28T22:59:00Z'), DEADLINE, PARIS)).toBe(1)
    expect(daysLeft(DEADLINE, DEADLINE, PARIS)).toBe(0)
  })

  it('gives the puzzles per day needed', () => {
    const p = pace({
      remaining: 176,
      deadlineAt: DEADLINE.toISOString(),
      timeZone: PARIS,
      now: new Date('2026-10-20T06:00:00Z')
    })

    expect(p).toMatchObject({ daysLeft: 9, perDay: 20, overdue: false })
    expect(p.text).toBe(
      'Il reste 176 puzzles et 9 jours : environ 20 par jour.'
    )
  })

  it('handles the last day, a finished cycle and a passed deadline', () => {
    const lastDay = new Date('2026-10-28T10:00:00Z')
    expect(
      pace({
        remaining: 12,
        deadlineAt: DEADLINE,
        timeZone: PARIS,
        now: lastDay
      }).text
    ).toBe('Dernier jour : encore 12 puzzles aujourd’hui.')
    expect(
      pace({
        remaining: 0,
        deadlineAt: DEADLINE,
        timeZone: PARIS,
        now: lastDay
      }).text
    ).toBe('Cycle terminé.')
    const late = pace({
      remaining: 1,
      deadlineAt: DEADLINE,
      timeZone: PARIS,
      now: new Date('2026-10-29T10:00:00Z')
    })
    expect(late).toMatchObject({ overdue: true, daysLeft: 0 })
    expect(late.text).toBe('Échéance dépassée : il restait 1 puzzle.')
  })
})

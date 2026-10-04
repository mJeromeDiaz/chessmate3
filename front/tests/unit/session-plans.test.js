import { describe, expect, it } from 'vitest'
import {
  defaultSettings,
  nextText,
  normalizeSettings,
  repetitionText,
  settingsIssue,
  settingsPayload
} from '@/utils/session/plans'
import { fromStep, toStep } from '@/utils/session/catalog'

describe('saved session settings', () => {
  it('reads the repetition', () => {
    const at = (repetition, weekdays) => ({
      repetition,
      time: '18:30',
      weekdays
    })
    expect(repetitionText(at('on_demand', []))).toBe('À la demande')
    expect(repetitionText(at('daily', [1, 2, 3, 4, 5, 6, 7]))).toBe(
      'Tous les jours à 18:30'
    )
    expect(repetitionText(at('daily', [5, 4, 3, 2, 1]))).toBe(
      'En semaine à 18:30'
    )
    expect(repetitionText(at('daily', [1, 3, 5]))).toBe(
      'Lun., mer., ven. à 18:30'
    )
    expect(repetitionText(at('weekly', [2]))).toBe('Chaque mardi à 18:30')
  })

  it('sends a consistent schedule', () => {
    const s = {
      ...defaultSettings(),
      reminderEnabled: true,
      calendarEnabled: true
    }
    expect(settingsPayload(s)).toMatchObject({
      repetition: 'on_demand',
      time: null,
      weekdays: [],
      reminderEnabled: false,
      calendarEnabled: false
    })
    expect(
      settingsPayload({ ...s, repetition: 'weekly', weekdays: [3, 5] })
    ).toMatchObject({ time: '18:30', weekdays: [3], reminderEnabled: true })
  })

  it('tells what prevents saving', () => {
    const s = defaultSettings()
    expect(settingsIssue(s)).toBeNull()
    expect(settingsIssue({ ...s, repetition: 'daily', weekdays: [] })).toMatch(
      'jour'
    )
    expect(
      settingsIssue({
        ...s,
        repetition: 'daily',
        reminderEnabled: true,
        reminderChannels: []
      })
    ).toMatch('rappelé')
  })

  it('repairs stored settings', () => {
    expect(
      normalizeSettings({
        repetition: 'monthly',
        time: '25:00',
        weekdays: [9, 2, 2],
        reminderChannels: ['sms', 'push'],
        reminderMinutes: 45
      })
    ).toEqual({
      ...defaultSettings(),
      weekdays: [2],
      reminderChannels: ['push']
    })
  })

  it('shows the next occurrence only when there is one', () => {
    expect(nextText({ nextAt: null })).toBe('')
    expect(nextText({ nextAt: '2026-09-29T16:30:00Z' })).toMatch(
      /^Prochaine : /
    )
  })

  it('turns steps back into program items', () => {
    for (const step of [
      {
        module: 'free',
        minutes: 20,
        notes: 'Livre 3',
        settings: { format: 'podcast' }
      },
      {
        module: 'puzzles',
        minutes: 15,
        notes: '',
        settings: { themes: ['fork', 'pin'] }
      },
      {
        module: 'repertoire',
        minutes: 10,
        notes: '',
        settings: { repertoireIds: ['r1'] }
      },
      { module: 'woodpecker', minutes: 5, notes: '', settings: {} }
    ]) {
      const item = fromStep(step)
      expect(item).not.toBeNull()
      expect(toStep({ uid: 1, ...item })).toEqual(step)
    }
    expect(
      fromStep({ module: 'chess960', minutes: 5, notes: '', settings: {} })
    ).toBeNull()
  })
})

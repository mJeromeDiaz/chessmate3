import { describe, expect, it } from 'vitest'
import {
  blockedText,
  nextErrorText,
  sessionProgressText,
  sessionStatusText,
  stepModule,
  stepStatusText
} from '@/utils/session/steps'

const summary = (durationMs, itemCount = 0, successCount = 0) => ({
  durationMs,
  itemCount,
  successCount
})

describe('session steps', () => {
  it('finds the catalogue module of an API step', () => {
    expect(stepModule({ module: 'free' })?.title).toBe('Libre')
    expect(stepModule({ module: 'puzzles' })?.title).toBe('Puzzles')
    expect(stepModule({ module: 'chess960' })).toBeNull()
  })

  it('tells what each step became', () => {
    expect(stepStatusText({ status: 'pending' })).toBe('À venir')
    expect(stepStatusText({ status: 'unplayed' })).toBe('Non joué')
    expect(
      stepStatusText({
        status: 'done',
        module: 'puzzles',
        summary: summary(300000, 12, 9)
      })
    ).toBe('Fait · 12 terminés, 9 réussis')
    expect(
      stepStatusText({
        status: 'done',
        module: 'free',
        summary: summary(120000)
      })
    ).toMatch(/^Fait · /)
  })

  it('summarises a session', () => {
    const session = {
      status: 'expired',
      steps: [{ status: 'done' }, { status: 'skipped' }, { status: 'unplayed' }]
    }
    expect(sessionProgressText(session)).toBe('1 / 3 modules')
    expect(sessionStatusText(session)).toBe('Non terminée')
  })

  it('explains why a step cannot start', () => {
    expect(blockedText('light_set_paused')).toContain('en pause')
    expect(blockedText('nothing_to_test')).toContain('Rien à réviser')
    expect(blockedText('whatever')).toContain('ne peut pas démarrer')
  })

  it('translates a refused next step', () => {
    const conflict = detail => ({ response: { status: 409, data: { detail } } })
    expect(
      nextErrorText(conflict('Another training run is in progress.'))
    ).toContain('Une autre séance')
    expect(nextErrorText(conflict('The session is over.'))).toBe(
      'Cette session est terminée.'
    )
    expect(
      nextErrorText(conflict('The light set is paused. (light_set_paused)'))
    ).toContain('ne peut pas démarrer')
    expect(nextErrorText({ response: { status: 500 } })).toBe('')
  })
})

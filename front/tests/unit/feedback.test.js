import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import {
  blindfoldCopy,
  blindfoldKind,
  feedbackProf,
  keyMove,
  motifTheme,
  puzzleCopy,
  puzzleKind,
  repertoireCopy,
  xpText
} from '@/utils/feedback'
import { useFeedbackTimeline } from '@/composables/feedback/useFeedbackTimeline'

// utils/runEnd → utils/sounds reads the signed-in user's store.
vi.mock('@/services/api', () => ({ authApi: {}, profileApi: {} }))

/** Italian: 3.Bc4 is the opponent's move, 3…Nf6 the player's. */
const PUZZLE = {
  fen: 'r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq - 2 3',
  moves: ['f1c4', 'g8f6']
}

describe('verdicts', () => {
  it('a hint makes a success "with help", a mistake or the solution a miss', () => {
    const clean = { mistaken: false, solutionShown: false, hinted: false }
    expect(puzzleKind(clean)).toBe('win')
    expect(puzzleKind({ ...clean, hinted: true })).toBe('help')
    expect(puzzleKind({ ...clean, mistaken: true })).toBe('miss')
    expect(puzzleKind({ ...clean, hinted: true, solutionShown: true })).toBe(
      'miss'
    )
  })

  it('reads the blindfold status of the server', () => {
    expect(blindfoldKind('solved')).toBe('win')
    expect(blindfoldKind('helped')).toBe('help')
    expect(blindfoldKind('failed')).toBe('miss')
    expect(blindfoldKind(undefined)).toBe('miss')
  })
})

describe('puzzle facts', () => {
  it('numbers the player’s key move from the position', () => {
    expect(keyMove(PUZZLE)).toBe('3…Nf6')
    expect(keyMove({ fen: PUZZLE.fen, moves: ['a1a8', 'g8f6'] })).toBe('')
  })

  it('takes the first theme that is a motif', () => {
    expect(motifTheme(['short', 'middlegame', 'fork', 'pin'])).toBe('fork')
    expect(motifTheme(['long', 'rookEndgame', 'crushing'])).toBeNull()
    expect(motifTheme(undefined)).toBeNull()
  })
})

describe('copy', () => {
  it('a puzzle: the motif as title, the key move, the time and the rating change', () => {
    expect(
      puzzleCopy('win', {
        move: '3…Nf6',
        motif: 'Fourchette',
        durationMs: 38_000,
        ratingDelta: 8.4
      })
    ).toMatchObject({
      title: 'Fourchette !',
      sub: '3…Nf6 trouvé seul · 0:38 · Elo +8',
      kicker: 'BRAVO'
    })
    expect(puzzleCopy('win', { move: '' }).title).toBe('Bravo !')
    expect(
      puzzleCopy('help', { move: '3…Nf6', durationMs: 72_000 })
    ).toMatchObject({
      title: 'Bien joué !',
      sub: 'Trouvé avec un indice · 1:12',
      kicker: 'PAS MAL'
    })
    const miss = puzzleCopy('miss', { move: '3…Nf6', motif: 'Fourchette' })
    expect(miss).toMatchObject({
      title: 'Raté !',
      sub: 'La solution : 3…Nf6 !',
      kicker: 'CORRECTION'
    })
    expect(miss.bubble).toContain('« fourchette »')
  })

  it('a blindfold puzzle mentions the peek', () => {
    expect(blindfoldCopy('help', { move: '3…Nf6', durationMs: 5000 }).sub).toBe(
      'Trouvé avec un coup d’œil · 0:05'
    )
    expect(blindfoldCopy('win', { move: '3…Nf6' }).sub).toBe(
      '3…Nf6 trouvé à l’aveugle'
    )
  })

  it('a repertoire unit: its moves, or the prepared move missed and its return', () => {
    expect(repertoireCopy('win', { unit: 'line', moves: 4 })).toMatchObject({
      title: 'Ligne réussie !',
      sub: '4 coups préparés sans faute'
    })
    const miss = repertoireCopy('miss', {
      unit: 'segment',
      moves: 2,
      expected: '5.Bc4',
      retry: true
    })
    expect(miss.sub).toBe('Le coup préparé : 5.Bc4')
    expect(miss.bubble).toContain('Il revient plus tard')
  })

  it('the XP chip waits for the server and hides when nothing is announced', () => {
    expect(xpText(undefined)).toBe('…')
    expect(xpText(null)).toBe('')
    expect(xpText(0)).toBe('+0 XP')
    expect(xpText(12)).toBe('+12 XP')
  })
})

describe('feedbackProf', () => {
  it('is the module’s professor, the module’s colours', () => {
    expect(feedbackProf('puzzles', null)).toMatchObject({
      name: 'Albert Stein',
      bg: '#FF6FAE'
    })
    expect(feedbackProf('woodpecker', 'win').bg).toBe('#C6F432')
    expect(feedbackProf('repertoire', 'miss').name).toBe('Aaron')
    // No card: drawn from the module's piece.
    expect(feedbackProf('blindfold', 'win')).toMatchObject({
      name: 'Noctis',
      image: ''
    })
  })
})

describe('useFeedbackTimeline', () => {
  /** @type {import('vue').EffectScope} */
  let scope

  beforeEach(() => {
    vi.useFakeTimers()
    scope = effectScope()
  })

  afterEach(() => {
    scope.stop()
    vi.useRealTimers()
  })

  it('walks a success through its four beats, a miss through six', () => {
    const timeline = scope.run(() => useFeedbackTimeline())
    if (!timeline) throw new Error('no timeline')

    timeline.play('win')
    expect([
      timeline.kind.value,
      timeline.step.value,
      timeline.run.value
    ]).toEqual(['win', 0, 1])
    vi.advanceTimersByTime(30)
    expect(timeline.step.value).toBe(1)
    vi.advanceTimersByTime(1000)
    expect(timeline.step.value).toBe(4)

    timeline.play('miss')
    expect([timeline.step.value, timeline.run.value]).toEqual([0, 2])
    vi.advanceTimersByTime(900)
    expect(timeline.step.value).toBe(6)

    timeline.reset()
    vi.advanceTimersByTime(1000)
    expect([timeline.kind.value, timeline.step.value]).toEqual([null, 0])
  })

  it('a new verdict cancels the beats of the previous one', () => {
    const timeline = scope.run(() => useFeedbackTimeline())
    if (!timeline) throw new Error('no timeline')

    timeline.play('miss')
    vi.advanceTimersByTime(500)
    timeline.play('win')
    vi.advanceTimersByTime(800)
    expect(timeline.step.value).toBe(3)
  })
})

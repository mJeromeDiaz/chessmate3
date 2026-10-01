import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import {
  DEVIATION_DELAY_MS,
  useRepertoireDrill
} from '@/composables/repertoire/useRepertoireDrill'
import { normalizeFen } from '@/utils/chess/normalizeFen'
import { labelText, numberedMoves } from '@/utils/repertoireTest'

/** Normalized FEN after SAN moves from the initial position. */
function fenAfter(...sans) {
  const chess = new Chess()
  for (const san of sans) chess.move(san)
  return normalizeFen(chess.fen())
}

const START = {
  unit: 'segment',
  orientation: 'white',
  context: [{ uci: 'e2e4', san: 'e4' }],
  deviation: true,
  label: { opening: { eco: 'B20', name: 'Sicilian Defense' }, move: '1…c5' },
  rank: 1,
  round: 1,
  retry: false,
  newRound: false
}
/** The 1…c5 segment: 2.Nf3 d6 3.d4. */
const FIRST = {
  id: 'u1:0',
  data: {
    unitId: 'u1',
    repertoireId: 'r',
    segmentId: 's',
    index: 0,
    total: 2,
    play: [{ uci: 'c7c5', san: 'c5' }],
    fen: fenAfter('e4', 'c5'),
    ply: 2,
    unit: 'segment',
    orientation: 'white',
    label: START.label,
    start: START
  }
}
const SECOND = {
  id: 'u1:1',
  data: {
    ...FIRST.data,
    index: 1,
    play: [{ uci: 'd7d6', san: 'd6' }],
    fen: fenAfter('e4', 'c5', 'Nf3', 'd6'),
    ply: 4,
    start: null
  }
}
const answered = (data = {}) => ({
  data: {
    status: 'answered',
    correct: true,
    expected: { uci: 'g1f3', san: 'Nf3' },
    comment: null,
    rating: 'good',
    unitDone: false,
    unitSuccess: null,
    retry: false,
    ...data
  }
})

function drill() {
  let clock = 1000
  const waits = []
  const d = useRepertoireDrill({
    now: () => clock,
    wait: ms => {
      waits.push(ms)
      return Promise.resolve()
    }
  })
  return { d, waits, advance: ms => (clock += ms) }
}

describe('useRepertoireDrill', () => {
  it('shows the context without asking, then plays the deviation', async () => {
    const { d, waits } = drill()
    await d.present(FIRST)

    expect(d.moves.value.map(m => [m.san, m.kind])).toEqual([
      ['e4', 'context'],
      ['c5', 'opponent']
    ])
    expect(waits[0]).toBe(DEVIATION_DELAY_MS)
    expect(d.deviation.value).toEqual({ uci: 'c7c5', san: 'c5' })
    expect(d.highlights.value.every(h => h.type === 'hint')).toBe(true)
    expect(normalizeFen(d.fen.value)).toBe(FIRST.data.fen)
    expect([d.phase.value, d.movableColor.value]).toEqual(['playing', 'white'])
  })

  it('measures the think time from the moment the board can be played', async () => {
    const { d, advance } = drill()
    await d.present(FIRST)
    advance(1800)

    expect(d.attempt('g1f3')).toEqual({
      moves: ['g1f3'],
      hintLevel: 0,
      solutionShown: false,
      thinkMs: 1800
    })
    expect(d.attempt('b1c3')).toBeNull()
    expect(d.phase.value).toBe('submitting')
  })

  it('goes on after a right move, playing the reply before the next question', async () => {
    const { d } = drill()
    await d.present(FIRST)
    d.attempt('g1f3')
    expect(d.resolve(answered())).toBe('next')

    await d.present(SECOND)
    expect(d.moves.value.map(m => m.san)).toEqual(['e4', 'c5', 'Nf3', 'd6'])
    expect(d.start.value?.label.move).toBe('1…c5')
    expect(d.phase.value).toBe('playing')
  })

  it('takes a wrong move back and accepts only the right one', async () => {
    const { d } = drill()
    await d.present(FIRST)
    d.attempt('b1c3')
    const verdict = d.resolve(
      answered({ correct: false, comment: 'Open Sicilian', rating: 'again' })
    )

    expect(verdict).toBe('correct')
    expect(normalizeFen(d.fen.value)).toBe(FIRST.data.fen)
    expect(d.arrows.value).toEqual([{ from: 'g1', to: 'f3', type: 'solution' }])
    expect([d.comment.value, d.mistakes.value, d.movableColor.value]).toEqual([
      'Open Sicilian',
      1,
      'white'
    ])
    expect(d.correct('b1c3')).toBe(false)
    expect(d.correct('g1f3')).toBe('next')
    expect(d.moves.value.at(-1)).toMatchObject({
      san: 'Nf3',
      kind: 'corrected'
    })
    expect(d.arrows.value).toEqual([])
  })

  it('ends a unit: succeeded at once, failed once the right move is played', async () => {
    const { d } = drill()
    await d.present(SECOND)
    d.attempt('d2d4')
    expect(d.resolve(answered({ unitDone: true, unitSuccess: true }))).toBe(
      'unitSucceeded'
    )
    expect(d.outcome.value).toEqual({ success: true, retry: false })

    await d.present(FIRST)
    d.attempt('g1f3')
    d.resolve(answered())
    await d.present(SECOND)
    d.attempt('c2c4')
    d.resolve(
      answered({
        correct: false,
        expected: { uci: 'd2d4', san: 'd4' },
        unitDone: true,
        unitSuccess: false,
        retry: true
      })
    )
    expect(d.correct('d2d4')).toBe('unitFailed')
    expect(d.outcome.value).toEqual({ success: false, retry: true })
    expect([d.succeeded.value, d.failed.value]).toEqual([1, 1])
    expect(d.movableColor.value).toBeNull()
  })

  it('starts a unit without context from the initial position', async () => {
    const { d } = drill()
    await d.present(SECOND)
    d.attempt('d2d4')
    d.resolve(answered({ unitDone: true, unitSuccess: true }))

    await d.present({
      id: 'u2:0',
      data: {
        ...FIRST.data,
        unitId: 'u2',
        play: [],
        fen: fenAfter(),
        start: { ...START, context: [], deviation: false }
      }
    })
    expect(normalizeFen(d.fen.value)).toBe(fenAfter())
    expect([d.moves.value, d.highlights.value, d.deviation.value]).toEqual([
      [],
      [],
      null
    ])
  })

  it('resumes in the middle of a unit on the question position', async () => {
    const { d } = drill()
    await d.present(SECOND)

    expect(normalizeFen(d.fen.value)).toBe(SECOND.data.fen)
    expect(d.phase.value).toBe('playing')
  })

  it('resumes a unit of Black with its orientation, label and move numbers', async () => {
    const { d } = drill()
    const label = { opening: null, move: '2.Nf3' }
    await d.present({
      id: 'u2:1',
      data: {
        ...SECOND.data,
        unitId: 'u2',
        play: [{ uci: 'g1f3', san: 'Nf3' }],
        fen: fenAfter('e4', 'c5', 'Nf3'),
        ply: 3,
        orientation: 'black',
        label
      }
    })

    expect([d.orientation.value, d.movableColor.value]).toEqual([
      'black',
      'black'
    ])
    expect([d.start.value?.label, d.firstPly.value, d.moves.value]).toEqual([
      label,
      3,
      []
    ])
    d.attempt('d7d6')
    expect(d.moves.value.map(m => m.san)).toEqual(['d6'])
  })

  it('drops a unit whose repertoire changed', async () => {
    const { d } = drill()
    await d.present(FIRST)
    d.attempt('g1f3')

    expect(
      d.resolve({
        data: { ...answered().data, status: 'stale', correct: null }
      })
    ).toBe('next')
    expect([d.stale.value, normalizeFen(d.fen.value)]).toEqual([
      true,
      FIRST.data.fen
    ])
    expect(d.resolve(null)).toBe('refused')
  })

  it('keeps the next item playable when the answer was refused', async () => {
    const { d } = drill()
    await d.present(FIRST)
    d.attempt('g1f3')
    // Item closed (409): the runner brought the next unit before answering null.
    await d.present({
      id: 'u3:0',
      data: {
        ...FIRST.data,
        unitId: 'u3',
        play: [],
        fen: fenAfter(),
        ply: 0,
        start: { ...START, context: [], deviation: false }
      }
    })

    expect(d.resolve(null)).toBe('refused')
    expect(d.phase.value).toBe('playing')
  })

  it('replays the moves without counting the time', async () => {
    const { d, advance } = drill()
    await d.present(FIRST)
    const replaying = d.replay()
    expect(d.movableColor.value).toBeNull()
    advance(500)
    await replaying
    advance(1000)

    expect(d.phase.value).toBe('playing')
    expect(d.attempt('g1f3')?.thinkMs).toBe(1000)
  })

  it('replays from the question position after a reload', async () => {
    const { d } = drill()
    await d.present(SECOND)
    d.attempt('f1b5')
    d.resolve(answered({ unitDone: true, unitSuccess: true }))

    await d.replay()

    expect(d.phase.value).toBe('unitDone')
    expect(normalizeFen(d.fen.value)).toBe(
      fenAfter('e4', 'c5', 'Nf3', 'd6', 'Bb5+')
    )
  })
})

describe('repertoire test wording', () => {
  it('names a unit by its opening and deviation', () => {
    expect(labelText(START.label)).toBe('Sicilian Defense · 1…c5')
    expect(
      labelText({
        opening: { eco: 'B00', name: "King's Pawn Game" },
        move: null
      })
    ).toBe("Tronc commun · King's Pawn Game")
    expect(labelText(null)).toBe('Tronc commun')
  })

  it('numbers moves from their ply', () => {
    expect(numberedMoves(['e4', 'e5', 'Nf3'])).toBe('1.e4 e5 2.Nf3')
    expect(numberedMoves(['c5', 'Nf3'], 1)).toBe('1…c5 2.Nf3')
  })
})

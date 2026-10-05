import { describe, expect, it } from 'vitest'
import { Chess } from 'chess.js'
import { useLineReplay } from '@/composables/repertoire/useLineReplay'
import { normalizeFen } from '@/utils/chess/normalizeFen'

/** No pause before the opponent's moves. */
const replay = () => useLineReplay({ wait: () => Promise.resolve() })
/** Lets the opponent's moves be played. */
const flush = () => new Promise(resolve => setTimeout(resolve, 0))

const INITIAL = new Chess().fen()

/** Normalized FEN after SAN moves from the initial position. */
function fenAfter(...sans) {
  const chess = new Chess()
  for (const san of sans) chess.move(san)
  return normalizeFen(chess.fen())
}

describe('useLineReplay', () => {
  it('waits for the user’s move, then plays the opponent’s reply', async () => {
    const r = replay()
    expect(
      await r.load({
        startFen: INITIAL,
        moves: ['e4', 'e5', 'Nf3', 'Nc6'],
        orientation: 'white'
      })
    ).toBe(true)
    expect(r.phase.value).toBe('playing')
    expect(r.movableColor.value).toBe('white')
    expect(r.total.value).toBe(4)

    expect(r.play('e2e4')).toBe('correct')
    await flush()
    expect(r.cursor.value).toBe(2)
    expect(normalizeFen(r.fen.value)).toBe(fenAfter('e4', 'e5'))
    expect(r.phase.value).toBe('playing')
  })

  it('plays the opponent’s first moves alone from a normalized start position', async () => {
    const r = replay()
    await r.load({
      startFen: fenAfter('e4'),
      moves: ['c5', 'Nf3', 'd6'],
      orientation: 'white'
    })
    expect(r.phase.value).toBe('playing')
    expect(normalizeFen(r.fen.value)).toBe(fenAfter('e4', 'c5'))
    expect(r.highlights.value).toEqual([
      { square: 'c7', type: 'lastMove' },
      { square: 'c5', type: 'lastMove' }
    ])
  })

  it('shows the right move after a wrong one and accepts only it', async () => {
    const r = replay()
    await r.load({
      startFen: INITIAL,
      moves: ['e4', 'c5', 'Nf3'],
      orientation: 'black'
    })
    expect(r.phase.value).toBe('playing')
    expect(r.movableColor.value).toBe('black')

    expect(r.play('e7e5')).toBe('wrong')
    expect(r.phase.value).toBe('correcting')
    expect(r.mistakes.value).toBe(1)
    expect(r.arrows.value).toEqual([{ from: 'c7', to: 'c5', type: 'solution' }])
    expect(normalizeFen(r.fen.value)).toBe(fenAfter('e4'))

    // Another wrong move while correcting is not a new mistake.
    expect(r.play('d7d5')).toBe('wrong')
    expect(r.mistakes.value).toBe(1)

    expect(r.play('c7c5')).toBe('correct')
    expect(r.arrows.value).toEqual([])
    await flush()
    // The line ends on the opponent's move.
    expect(r.phase.value).toBe('done')
    expect(normalizeFen(r.fen.value)).toBe(fenAfter('e4', 'c5', 'Nf3'))
  })

  it('ends on the user’s last move', async () => {
    const r = replay()
    await r.load({ startFen: INITIAL, moves: ['d4'], orientation: 'white' })
    expect(r.play('d2d4')).toBe('done')
    expect(r.phase.value).toBe('done')
    expect(r.movableColor.value).toBe(null)
    expect(r.play('e7e5')).toBe('ignored')
  })

  it('refuses a line that cannot be played from its start position', async () => {
    const r = replay()
    expect(
      await r.load({
        startFen: INITIAL,
        moves: ['e4', 'Nf6', 'Ke3'],
        orientation: 'white'
      })
    ).toBe(false)
    expect(r.phase.value).toBe('invalid')
    expect(r.play('e2e4')).toBe('ignored')
  })

  it('stops the opponent’s moves once disposed', async () => {
    /** @type {() => void} */
    let release = () => {}
    const r = useLineReplay({
      wait: () =>
        new Promise(resolve => {
          release = () => resolve()
        })
    })
    const loading = r.load({
      startFen: INITIAL,
      moves: ['e4', 'e5'],
      orientation: 'black'
    })
    expect(r.phase.value).toBe('showing')
    r.dispose()
    release()
    await loading
    expect(r.phase.value).toBe('idle')
    expect(r.cursor.value).toBe(0)
  })
})

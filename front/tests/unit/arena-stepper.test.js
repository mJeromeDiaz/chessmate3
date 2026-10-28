import { describe, expect, it } from 'vitest'
import { nextTick, ref } from 'vue'
import { Chess } from 'chess.js'
import { useMoveStepper } from '@/composables/arena/useMoveStepper'

const START = new Chess().fen()

describe('useMoveStepper', () => {
  it('shows the live position until the player steps back', () => {
    const moves = ref(['e2e4', 'e7e5', 'g1f3'])
    const stepper = useMoveStepper(moves, ref('g1'))
    expect(stepper.browsing.value).toBe(false)
    expect(stepper.cursor.value).toBe(3)
    expect(stepper.shown.value.uci).toBe('g1f3')

    stepper.go(stepper.cursor.value - 1)
    expect(stepper.browsing.value).toBe(true)
    expect(stepper.shown.value.uci).toBe('e7e5')
    stepper.go(0)
    expect(stepper.shown.value.fen).toBe(START)
    stepper.go(-5)
    expect(stepper.cursor.value).toBe(0)
  })

  it('stays on the position looked at when the boss plays, and tells it', () => {
    const moves = ref(['e2e4', 'e7e5', 'g1f3'])
    const stepper = useMoveStepper(moves, ref('g1'))
    stepper.go(1)
    expect(stepper.moved.value).toBe(false)
    moves.value = [...moves.value, 'b8c6']
    expect(stepper.cursor.value).toBe(1)
    expect(stepper.shown.value.uci).toBe('e2e4')
    expect(stepper.moved.value).toBe(true)
  })

  it('goes back to the game past the last move, with live(), or in a new game', async () => {
    const moves = ref(['e2e4', 'e7e5'])
    const gameId = ref('g1')
    const stepper = useMoveStepper(moves, gameId)
    stepper.go(1)
    stepper.go(2)
    expect(stepper.browsing.value).toBe(false)

    stepper.go(0)
    stepper.live()
    expect(stepper.browsing.value).toBe(false)

    stepper.go(1)
    gameId.value = 'g2'
    moves.value = []
    await nextTick()
    expect(stepper.browsing.value).toBe(false)
    expect(stepper.shown.value.fen).toBe(START)
  })

  it('cannot step back before the first move', () => {
    const stepper = useMoveStepper(ref([]), ref('g1'))
    stepper.go(0)
    expect(stepper.browsing.value).toBe(false)
  })
})

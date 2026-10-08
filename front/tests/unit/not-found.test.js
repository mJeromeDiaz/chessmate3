import { describe, expect, it } from 'vitest'
import { LAST_STEP, STEPS_MS, notFoundFrame } from '@/utils/notFound'

describe('404 animation', () => {
  it('plays its steps in order', () => {
    expect(STEPS_MS).toEqual([...STEPS_MS].sort((a, b) => a - b))
    expect(LAST_STEP).toBe(6)
  })

  it('starts with the knight on its square and nothing written', () => {
    const f = notFoundFrame(0)
    expect(f).toMatchObject({ kLeft: '0%', kTop: '25%', pageOp: 1 })
    expect(f.letters.map(l => l.ch).join('')).toBe('BLUNDER!')
    expect(f.letters.every(l => l.op === 0)).toBe(true)
    expect(f.endOp).toBe(0)
  })

  it('captures the page, then shows everything', () => {
    expect(notFoundFrame(3)).toMatchObject({
      kLeft: '40%',
      kTop: '50%',
      pageOp: 0,
      badgeTf: 'scale(1) rotate(-10deg)'
    })
    const last = notFoundFrame(LAST_STEP)
    expect(last.letters.every(l => l.op === 1 && l.tf === 'none')).toBe(true)
    expect(last.letters.filter(l => l.bang).map(l => l.ch)).toEqual(['!'])
    expect(last).toMatchObject({ kickOp: 1, subOp: 1, endOp: 1 })
  })
})

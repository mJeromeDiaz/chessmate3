import { describe, expect, it } from 'vitest'
import {
  CLOSE_RATE,
  MAX_CARDS,
  countAt,
  fallingLetters,
  fixCards,
  fixLabel,
  fixWords,
  fixableItems,
  resultButtons,
  resultKind,
  resultProf,
  resultScore
} from '@/utils/runResult'
import { MODULE_FAIL_RATE } from '@/utils/sounds'

const run = (/** @type {string} */ module, itemCount, successCount, more = {}) => ({
  module,
  summary: { itemCount, successCount, durationMs: 0, ...more }
})

describe('run result', () => {
  it('picks the screen by the share solved: 100 %, 80 % or more, below', () => {
    expect(CLOSE_RATE).toBe(MODULE_FAIL_RATE)
    expect(resultKind(run('puzzles', 10, 10))).toBe('perfect')
    expect(resultKind(run('puzzles', 10, 9))).toBe('close')
    expect(resultKind(run('puzzles', 10, 8))).toBe('close')
    expect(resultKind(run('puzzles', 10, 7))).toBe('fail')
    expect(resultKind(run('puzzles', 1, 0))).toBe('fail')
  })

  it('shows no screen when nothing was played, a done one for free study', () => {
    expect(resultKind(run('puzzles', 0, 0))).toBeNull()
    expect(resultKind({ module: 'puzzles', summary: null })).toBeNull()
    expect(resultKind(run('free', 0, 0))).toBe('done')
  })

  it('scores in whole percent rounded down, free study in minutes', () => {
    expect(resultScore(run('puzzles', 3, 1))).toBe(33)
    expect(resultScore(run('puzzles', 200, 199))).toBe(99)
    expect(resultScore(run('puzzles', 10, 8))).toBe(80)
    expect(resultScore(run('free', 0, 0, { durationMs: 754_000 }))).toBe(12)
  })

  it('gives each module its professor in the right pose, a glyph without a card', () => {
    const albert = resultProf('puzzles', 'fail')
    expect(albert.name).toBe('Albert Stein')
    expect(albert.image).toContain('albert-v2-angry')
    expect(resultProf('woodpecker', 'pointer').image).toContain(
      'albert-v2-pointer'
    )
    expect(resultProf('evaluation', 'close').image).toContain('aaron-wink')
    expect(resultProf('repertoire', 'perfect').image).toContain('aaron-laugh')
    const noctis = resultProf('coordinates', 'perfect')
    expect(noctis.image).toBe('')
    expect(noctis.glyph).toBeTruthy()
    // Free study's "done" wears the perfect pose.
    expect(resultProf('puzzles', 'done').image).toContain('albert-v2-wow')
  })

  it('offers the mistakes only when some can be played again', () => {
    expect(resultButtons('perfect', 0)).toEqual({
      primary: { label: 'Continuer', action: 'close' },
      secondary: { label: 'Leçon suivante →', action: 'next' }
    })
    expect(resultButtons('close', 2).secondary).toEqual({
      label: 'Revoir mes erreurs',
      action: 'fix'
    })
    expect(resultButtons('close', 0).secondary.action).toBe('next')
    expect(resultButtons('fail', 3)).toEqual({
      primary: { label: 'Recommencer la leçon', action: 'fix' },
      secondary: { label: 'Plus tard', action: 'close' }
    })
    expect(resultButtons('fail', 0)).toEqual(resultButtons('perfect', 0))
    expect(resultButtons('done', 0)).toEqual(resultButtons('perfect', 0))
  })

  it('counts up to the score, "Presque parfait" overshooting to 96 first', () => {
    expect(countAt('perfect', 100, 0)).toBe(0)
    expect(countAt('perfect', 100, 1)).toBe(100)
    expect(countAt('fail', 38, 1)).toBe(38)
    expect(countAt('close', 82, 1)).toBe(82)
    const peak = Math.max(
      ...Array.from({ length: 101 }, (_, i) => countAt('close', 82, i / 100))
    )
    expect(peak).toBe(96)
    // A score above the peak just counts up.
    expect(countAt('close', 98, 1)).toBe(98)
  })

  it('drops the title letter by letter, in place from step 3', () => {
    const before = fallingLetters('Raté', 2)
    expect(before.map(l => l.ch).join('')).toBe('Raté')
    expect(before.every(l => l.op === 0)).toBe(true)
    const after = fallingLetters('total !', 3, 4)
    expect(after.every(l => l.op === 1 && l.tf === 'none')).toBe(true)
    expect(after[0].delay).toBe(`${4 * 0.045}s`)
    const words = fixWords(3)
    expect(words.map(w => w.t).join(' ')).toBe(
      'Il est temps de corriger ses erreurs !'
    )
    expect(words.filter(w => w.accent).map(w => w.t)).toEqual(['erreurs'])
  })

  it('names the items to review in the module’s words', () => {
    expect(fixLabel('puzzles', 6)).toBe('PUZZLES À REVOIR')
    expect(fixLabel('blindfold', 1)).toBe('PUZZLE À REVOIR')
    expect(fixLabel('repertoire', 2, 'segment')).toBe('TRONÇONS À REVOIR')
    expect(fixLabel('repertoire', 1, 'line')).toBe('LIGNE À REVOIR')
  })

  it('deals seven cards at most, fanned out around the middle', () => {
    const cards = fixCards([4, 11, 17, 23, 29, 36, 41, 48, 52], true)
    expect(cards).toHaveLength(MAX_CARDS)
    expect(cards.map(c => c.number)).toEqual([4, 11, 17, 23, 29, 36, 41])
    expect(cards[3].transform).toBe('translate(0px,0px) rotate(0deg)')
    expect(fixCards([7], false)[0].transform).toBe(
      'translate(0px,420px) rotate(0deg)'
    )
  })

  it('corrects only what can be played again', () => {
    const missed = [
      { item: { type: 'puzzle' }, replayable: true },
      { item: { type: 'repertoire_unit' }, replayable: false },
      { item: { type: 'coordinate' }, replayable: true },
      { item: { type: 'blindfold_puzzle' }, replayable: true }
    ]
    expect(fixableItems(missed).map(m => m.item.type)).toEqual([
      'puzzle',
      'blindfold_puzzle'
    ])
  })
})

import { describe, expect, it } from 'vitest'
import { missedItems } from '@/utils/runEnd'

const label = (/** @type {string} */ key) =>
  ({ fork: 'Fourchette', pin: 'Clouage', short: 'Court' })[key] ?? key

/**
 * @param {number} index
 * @param {'ok'|'hint'|'fail'} status
 * @param {string[]} themes
 */
const puzzle = (index, status, themes) => /** @type {any} */ ({
  index,
  type: 'puzzle',
  status,
  durationMs: 30_000,
  data: { puzzle: { themes } }
})

/**
 * @param {number} index
 * @param {'ok'|'fail'} status
 * @param {string|null} startFen
 */
const unit = (index, status, startFen = 'fen') => /** @type {any} */ ({
  index,
  type: 'repertoire_unit',
  status,
  durationMs: 12_000,
  data: {
    label: { opening: { eco: 'B20', name: 'Sicilienne' }, move: '1…c5' },
    repertoireName: 'Blancs',
    startFen
  }
})

describe('missedItems', () => {
  const items = [
    puzzle(1, 'ok', ['fork']),
    puzzle(2, 'fail', ['fork', 'short']),
    puzzle(3, 'hint', ['pin', 'fork'])
  ]

  it('lists the failed and helped items with their first theme', () => {
    expect(
      missedItems(items, label).map(m => [
        m.number,
        m.title,
        m.meta,
        m.replayable
      ])
    ).toEqual([
      [2, 'Fourchette', 'Raté · 0:30', true],
      [3, 'Clouage', 'Avec aide · 0:30', true]
    ])
  })

  it('titles a repertoire unit by its opening; one presented before its start was kept cannot be replayed', () => {
    const [missed] = missedItems([unit(1, 'fail', null)], label)
    expect([missed.title, missed.replayable]).toEqual([
      'Sicilienne · 1…c5',
      false
    ])
  })

  it('uses the place in the Woodpecker cycle as the number', () => {
    const item = { ...puzzle(1, 'fail', []), data: { number: 38, puzzle: {} } }
    expect(missedItems([item], label)[0].number).toBe(38)
  })
})

describe('a blindfold puzzles run', () => {
  it('lets every missed blindfold puzzle be replayed', () => {
    const items = [
      { ...puzzle(1, 'ok', ['fork']), type: 'blindfold_puzzle' },
      { ...puzzle(2, 'hint', ['pin']), type: 'blindfold_puzzle' },
      { ...puzzle(3, 'fail', ['pin']), type: 'blindfold_puzzle' }
    ]
    expect(missedItems(items, label).map(m => m.replayable)).toEqual([
      true,
      true
    ])
  })
})

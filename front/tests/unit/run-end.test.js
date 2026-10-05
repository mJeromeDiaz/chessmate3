import { describe, expect, it, vi } from 'vitest'
import {
  endHero,
  endMessage,
  endStats,
  gridTitle,
  isFailedRun,
  missedItems,
  weakPoint
} from '@/utils/runEnd'

// utils/sounds (the failure rule) reads the signed-in user's store.
vi.mock('@/services/api', () => ({ authApi: {}, profileApi: {} }))

const label = (/** @type {string} */ key) =>
  ({ fork: 'Fourchette', pin: 'Clouage', short: 'Court' })[key] ?? key

/**
 * @param {string} module
 * @param {number} itemCount
 * @param {number} successCount
 * @param {Record<string, any>} [metrics]
 */
const run = (
  module,
  itemCount,
  successCount,
  metrics = {}
) => /** @type {any} */ ({
  id: 'r1',
  module,
  parentId: null,
  summary: { durationMs: 125_000, itemCount, successCount, metrics }
})

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

describe('isFailedRun', () => {
  it('follows the end sound: under 80 % from 3 items, never free study', () => {
    expect(isFailedRun(run('puzzles', 5, 3))).toBe(true)
    expect(isFailedRun(run('puzzles', 5, 4))).toBe(false)
    expect(isFailedRun(run('repertoire', 2, 0))).toBe(false)
    expect(isFailedRun(run('free', 0, 0))).toBe(false)
  })
})

describe('endHero', () => {
  it('names the cycle of a Woodpecker run and counts what was done', () => {
    const hero = endHero(
      run('woodpecker', 12, 10, { cycle: { number: 2 } }),
      'Léa',
      false
    )
    expect(hero).toEqual({
      kicker: 'SÉANCE TERMINÉE · CYCLE 2',
      title: 'Bravo Léa !',
      subtitle: '12 puzzles en 2:05'
    })
  })

  it('counts repertoire units and free study time; cheers up after a failed run', () => {
    expect(
      endHero(run('repertoire', 1, 0, { unit: 'line' }), 'Léa', true)
    ).toEqual({
      kicker: 'SÉANCE TERMINÉE',
      title: 'Courage Léa !',
      subtitle: '1 ligne en 2:05'
    })
    expect(endHero(run('free', 0, 0), 'Léa', false).subtitle).toBe(
      '2:05 d’étude'
    )
  })
})

describe('missed items and weak point', () => {
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

  it('finds the theme missed most often', () => {
    expect(weakPoint(items, label)).toEqual({ label: 'Fourchette', count: 2 })
    expect(weakPoint([items[0]], label)).toBeNull()
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

describe('endMessage', () => {
  it('praises a good run and points at what to review', () => {
    const items = [
      puzzle(1, 'fail', ['fork']),
      ...Array.from({ length: 9 }, (_, i) => puzzle(i + 2, 'ok', []))
    ]
    expect(endMessage(run('puzzles', 10, 9), items, 'Léa', label)).toBe(
      'Quelle séance, Léa ! 90 % de réussite. Point à reprendre : Fourchette (1 à revoir). On les rejoue maintenant ?'
    )
  })

  it('has a word for a failed run, a perfect one, an empty one and free study', () => {
    expect(
      endMessage(run('repertoire', 4, 1), [unit(1, 'fail')], 'Léa', label)
    ).toMatch(
      /^Séance difficile.*Ligne à reprendre : Sicilienne · 1…c5 \(1 à revoir\)/
    )
    expect(endMessage(run('puzzles', 2, 2), [], 'Léa', label)).toBe(
      'Quelle séance, Léa ! 100 % de réussite, aucune erreur à revoir.'
    )
    expect(endMessage(run('puzzles', 0, 0), [], 'Léa', label)).toMatch(
      /^Rien de terminé/
    )
    expect(
      endMessage(run('free', 0, 0, { format: 'book' }), [], 'Léa', label)
    ).toMatch(/^2:05 d’étude \(livre\)/)
  })
})

describe('endStats', () => {
  it('shows the rating after a puzzles run, then the showcase XP', () => {
    const stats = endStats(
      run('puzzles', 4, 3, {
        averageMs: 20_000,
        ratingAfter: 1512,
        ratingDelta: 12.4
      }),
      []
    )
    expect(stats.map(s => [s.label, s.value, s.sub])).toEqual([
      ['Réussite', '75 %', '3 sur 4'],
      ['Moyenne / puzzle', '0:20', '2:05 au total'],
      ['Classement', '1512', '+12 pts'],
      ['XP gagnés', '+20', expect.stringMatching(/^Niveau \d+ · \d+ %$/)]
    ])
    expect(stats[3].showcase).toBe(true)
  })

  it('shows the cycle progress of a Woodpecker run and the graded positions of a repertoire run', () => {
    expect(
      endStats(
        run('woodpecker', 3, 3, { puzzleCount: 100, cycle: { played: 37 } }),
        []
      )[2]
    ).toMatchObject({ value: '37/100', sub: '63 restants' })
    const repertoire = endStats(
      run('repertoire', 2, 1, { positionsGraded: 9, recovered: 1 }),
      [unit(1, 'ok'), unit(2, 'fail')]
    )
    expect(repertoire[1]).toMatchObject({
      label: 'Moyenne / unité',
      value: '0:12'
    })
    expect(repertoire[2]).toMatchObject({ value: '9', sub: '1 rattrapé' })
  })

  it('keeps free study to its time and the XP', () => {
    expect(
      endStats(run('free', 0, 0, { format: 'video' }), []).map(s => s.label)
    ).toEqual(['Temps d’étude', 'XP gagnés'])
  })
})

describe('gridTitle', () => {
  it('names puzzles, lines or segments', () => {
    expect(gridTitle(run('woodpecker', 1, 1))).toBe('Puzzles de la séance')
    expect(gridTitle(run('repertoire', 1, 1, { unit: 'line' }))).toBe(
      'Lignes de la séance'
    )
    expect(gridTitle(run('repertoire', 1, 1, { unit: 'segment' }))).toBe(
      'Tronçons de la séance'
    )
  })
})

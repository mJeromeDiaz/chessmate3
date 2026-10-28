import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import arenaFr from '@/i18n/fr/arena.json'
import mindMapPathFr from '@/i18n/fr/mindMapPath.json'
import gamificationFr from '@/i18n/fr/gamification.json'
import {
  FEAT_PAGES,
  MASTERS_LEVEL,
  PATH_PAGES,
  arrivalAllowed,
  beatenCount,
  bossMood,
  canPlay,
  cardPicture,
  cardState,
  displayName,
  featProgress,
  isMystery,
  levelsOf,
  lockedHint,
  paletteOf,
  speedOf,
  traitsOf
} from '@/utils/arena'
import { TROPHIES, trophyCards } from '@/utils/gamification'

/** @returns {import('@/utils/arena').ArenaBoss} */
const boss = (/** @type {object} */ over = {}) => ({
  character: 'jongleur',
  capacity: 'visualisation',
  tier: 'chapter',
  level: 1,
  price: null,
  elo: 1000,
  endgameElo: null,
  styles: ['varied'],
  guarded: false,
  status: 'available',
  crystals: 100,
  won: 0,
  drawn: 0,
  lost: 0,
  firstWonAt: null,
  openingElo: null,
  feat: null,
  ...over
})

/** @returns {import('@/utils/arena').ArenaBoss} a professor, locked */
const master = (/** @type {object} */ over = {}) =>
  boss({
    character: 'lizy',
    capacity: null,
    tier: 'master',
    level: MASTERS_LEVEL,
    elo: 2500,
    endgameElo: 2700,
    guarded: true,
    status: 'locked',
    crystals: 1000,
    feat: { current: 9, goal: 27 },
    ...over
  })

/** The professors, the last level (api ArenaRules::MASTERS). */
const MASTERS = ['lizy', 'albert', 'aaron', 'ray']

/** Every boss of the roster (api/src/Arena/Roster/Roster.php). */
const ROSTER = [
  'jongleur',
  'cyclope',
  'gargouille',
  'sablier',
  'flash',
  'fantome',
  'cameleon',
  'sith',
  'metronome',
  'compteur',
  'avocat',
  'bourreau',
  'gardien',
  'horloger',
  'reine-noire',
  'maitre-tours',
  'duelliste',
  'cavalier-errant',
  'roi-cendres',
  'defi-mates',
  'defi-endgames',
  'defi-tactics',
  'defi-opening',
  'zombie',
  'requin',
  'minotaure',
  ...MASTERS
]

describe('arena', () => {
  it('shows a boss beaten once won, open, in the shop, locked or soon', () => {
    expect(cardState(boss())).toBe('open')
    expect(cardState(boss({ won: 1 }))).toBe('beaten')
    expect(cardState(boss({ status: 'shop', price: 250 }))).toBe('shop')
    expect(cardState(boss({ status: 'locked' }))).toBe('locked')
    expect(cardState(boss({ status: 'soon' }))).toBe('soon')
    expect(canPlay(boss())).toBe(true)
    expect(canPlay(boss({ status: 'shop' }))).toBe(false)
  })

  it('groups the bosses by level, the weakest first', () => {
    const levels = levelsOf([
      boss({ character: 'gargouille', level: 3, elo: 1500 }),
      boss({ character: 'metronome', level: 2, elo: 1200, won: 2 }),
      boss({ character: 'zombie', level: 1, elo: 700 }),
      boss({ character: 'jongleur', level: 1, elo: 1000 }),
      boss({ character: 'bourreau', level: 2, elo: 1200 })
    ])
    expect(levels.map(l => l.level)).toEqual([1, 2, 3])
    expect(levels[0].bosses.map(b => b.character)).toEqual([
      'zombie',
      'jongleur'
    ])
    expect(levels[1].bosses.map(b => b.character)).toEqual([
      'bourreau',
      'metronome'
    ])
    expect(levels[1].beaten).toBe(1)
    expect(beatenCount(levels.flatMap(l => l.bosses))).toBe(1)
  })

  it('sends a locked boss to its path, a soon one nowhere', () => {
    expect(lockedHint(boss({ status: 'locked' })).to).toBe(
      '/cerveau/visualization'
    )
    expect(
      lockedHint(boss({ status: 'locked', capacity: 'endgames' })).to
    ).toBe('/finales')
    expect(
      lockedHint(boss({ status: 'locked', capacity: 'challenges' })).to
    ).toBe('/defis')
    expect(lockedHint(boss({ status: 'soon' })).to).toBeNull()
    expect(Object.keys(PATH_PAGES)).toEqual([
      'visualisation',
      'calculation',
      'endgames',
      'challenges'
    ])
  })

  it('draws a boss standing, else as a piece', () => {
    expect(cardPicture('bourreau').picture).not.toBe('')
    expect(cardPicture('gardien')).toEqual({ picture: '', glyph: '♟' })
    expect(cardPicture('sith').glyph).toBe('♚')
  })

  it('gives every boss of the roster its colours, gauges and words', () => {
    const arena = /** @type {any} */ (arenaFr)
    const characters = /** @type {any} */ (mindMapPathFr).characters
    for (const character of ROSTER) {
      expect(paletteOf(character).dark, character).toMatch(/^#/)
      const traits = traitsOf(character)
      expect(traits.map(t => t.key)).toEqual([
        'aggression',
        'patience',
        'calculation',
        'speed'
      ])
      expect(
        traits.every(t => t.value >= 1 && t.value <= 5),
        character
      ).toBe(true)
      expect(speedOf(character)).toBe(traits[3].value)
      for (const key of ['title', 'lore', 'tipTitle', 'tip'])
        expect(
          arena.bosses[character]?.[key],
          `${character}.${key}`
        ).toBeTruthy()
      for (const line of ['name', 'tagline', 'taunt', 'defeat', 'win'])
        expect(
          characters[character]?.[line],
          `${character}.${line}`
        ).toBeTruthy()
    }
    expect(Object.keys(FEAT_PAGES)).toEqual(MASTERS)
  })

  it('brings a professor by a feat, Ray a mystery until then', () => {
    const lizy = master()
    expect(featProgress(lizy)).toBeCloseTo(9 / 27)
    expect(featProgress(boss())).toBeNull()
    expect(featProgress(master({ feat: { current: 60, goal: 51 } }))).toBe(1)
    expect(lockedHint(lizy)).toEqual({
      text: 'Maîtrise toutes les leçons du parcours des Finales pour la défier.',
      to: '/finales',
      go: 'Relever le défi'
    })
    expect(lockedHint(master({ character: 'albert' })).to).toBe('/streak')
    expect(lockedHint(master({ character: 'aaron' })).to).toBe('/repertoire')
    expect(lockedHint(master({ character: 'ray' })).to).toBe('/blindfold')
    expect(displayName(lizy)).toBe('Lizy')
    const ray = master({ character: 'ray', feat: { current: 0, goal: 1 } })
    expect([isMystery(ray), displayName(ray)]).toEqual([true, '???'])
    expect(cardPicture('ray')).toEqual({ picture: '', glyph: '♞' })
    const arrived = { ...ray, status: /** @type {const} */ ('available') }
    expect([isMystery(arrived), displayName(arrived)]).toEqual([false, 'Ray'])
    expect(isMystery(lizy)).toBe(false)
  })

  it('draws the professors from their own pictures, in every mood', () => {
    for (const character of ['lizy', 'albert', 'aaron']) {
      expect(cardPicture(character).picture, character).not.toBe('')
      for (const mood of ['joy', 'surprise', 'doubt', 'anger', 'neutral'])
        expect(
          bossMood(character, /** @type {any} */ (mood)),
          `${character} ${mood}`
        ).not.toBe('')
    }
    expect(bossMood('ray', 'joy')).toBe('')
    expect(bossMood('bourreau', 'joy')).not.toBe('')
  })

  it('puts the professors\u2019 level last', () => {
    const levels = levelsOf([
      master({ character: 'ray', elo: 2800 }),
      boss({ character: 'sith', level: 5, elo: 2600 }),
      master({ elo: 2500 })
    ])
    expect(levels.map(l => l.level)).toEqual([5, MASTERS_LEVEL])
    expect(levels[1].bosses.map(b => b.character)).toEqual(['lizy', 'ray'])
  })

  it('shows the exceptional badges first, in gold, with their words', () => {
    const trophies = /** @type {any} */ (gamificationFr).trophies
    for (const prof of MASTERS)
      for (const key of [`master_${prof}`, `master_${prof}_beaten`]) {
        expect(TROPHIES[key]?.exceptional, key).toBe(true)
        expect(trophies[key]?.name, key).toBeTruthy()
        expect(trophies[key]?.desc, key).toBeTruthy()
      }
    expect(trophies.master_albert.name).toBe('Disciple d’Albert')
    const cards = trophyCards([
      {
        key: 'first_step',
        goal: 1,
        current: 1,
        unlocked: true,
        unlockedAt: '2026-10-01T10:00:00+00:00',
        ratio: null
      },
      {
        key: 'master_lizy',
        goal: 27,
        current: 27,
        unlocked: true,
        unlockedAt: '2026-10-27T10:00:00+00:00',
        ratio: null
      }
    ])
    expect(cards.map(c => c.key)).toEqual(['master_lizy', 'first_step'])
  })

  it('names every style the server gives a boss', () => {
    const styles = /** @type {any} */ (arenaFr).styles
    for (const style of [
      'varied',
      'solid',
      'aggressive',
      'trades',
      'forcing',
      'king_hunter',
      'pawns',
      'queen_keeper',
      'checks',
      'rooks',
      'bishops',
      'knights',
      'mate_finder',
      'captures'
    ])
      expect(styles[style], style).toBeTruthy()
  })

  it('lets a boss arrive outside a run, but right after a path boss\u2019s victory', () => {
    expect(arrivalAllowed('/')).toBe(true)
    expect(arrivalAllowed('/arene')).toBe(true)
    expect(arrivalAllowed('/training/abc')).toBe(false)
    expect(arrivalAllowed('/training/abc', true)).toBe(true)
    expect(arrivalAllowed('/arene/partie', true)).toBe(false)
    expect(arrivalAllowed('/visite')).toBe(false)
  })

  it('keeps the arrivals of the arena just read, and drops them once shown', async () => {
    vi.resetModules()
    const announce = vi.fn(async () => ({ bosses: [] }))
    vi.doMock('@/services/api', async importOriginal => ({
      .../** @type {object} */ (await importOriginal()),
      arenaApi: { announce, unlocks: vi.fn(async () => ({ bosses: [] })) }
    }))
    const { useArenaStore } = await import('@/stores/arena')
    setActivePinia(createPinia())
    const store = useArenaStore()
    store.fromOverview(
      /** @type {any} */ ({
        bosses: [
          boss({ character: 'zombie' }),
          boss(),
          boss({ character: 'requin' })
        ],
        pendingUnlocks: ['requin', 'zombie', 'unknown']
      })
    )
    expect(store.pending.map(b => b.character)).toEqual(['requin', 'zombie'])
    await store.announce(['requin'])
    expect(store.pending.map(b => b.character)).toEqual(['zombie'])
    expect(announce).toHaveBeenCalledWith(['requin'])
    vi.doUnmock('@/services/api')
  })
})

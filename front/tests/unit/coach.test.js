import { describe, expect, it } from 'vitest'
import {
  WOODPECKER_REASONS,
  coachErrorText,
  moduleCard,
  moduleColor,
  needText,
  playedModules,
  scanSays,
  score,
  sessionSays,
  slipText
} from '@/utils/coach'

const rook = {
  type: 'rook',
  games: 9,
  wins: 2,
  draws: 4,
  losses: 3,
  advantages: 6,
  lost: 4
}

/** @returns {import('@/utils/coach').Diagnosis} */
const diagnosis = (over = {}) => ({
  games: 200,
  analysed: 40,
  rating: 1800,
  white: [
    { family: 'Italian Game', games: 38, wins: 20, draws: 2, losses: 16 }
  ],
  black: [
    { family: 'Sicilian Defense', games: 46, wins: 20, draws: 6, losses: 20 }
  ],
  endgames: { games: 30, advantages: 12, lost: 7, types: [rook] },
  weakest: rook,
  ...over
})

const woodpecker = (color, over = {}) => ({
  module: 'woodpecker',
  minutes: 10,
  included: true,
  missing: null,
  facts: {
    color,
    opening: diagnosis()[color][0],
    puzzleCount: 100,
    bothColors: false,
    settings: { setId: `set-${color}` }
  },
  ...over
})

/** @returns {import('@/utils/coach').Composition} */
const composition = (modules, over = {}) => ({
  planId: 'p1',
  minutes: 39,
  diagnosis: diagnosis(),
  repertoire: { status: 'generated', repertoires: [] },
  modules,
  ...over
})

describe('tailored session words', () => {
  it('counts points and slips', () => {
    expect(score({ games: 38, wins: 20, draws: 2 })).toBe(55)
    expect(score({ games: 0, wins: 0, draws: 0 })).toBeNull()
    expect(slipText(rook)).toBe('4 avantages laissés filer sur 6')
    expect(slipText({ advantages: 1, lost: 1 })).toBe(
      '1 avantage laissé filer sur 1'
    )
  })

  it('lets Albert follow the reading, then say what the games say', () => {
    expect(scanSays(0.1, null)).toMatch('une par une')
    expect(scanSays(0.4, null)).toMatch('ouvertures')
    expect(scanSays(0.8, null)).toMatch('finales')
    expect(scanSays(1, diagnosis())).toBe(
      'Trouvé ! Tes finales de type « tours » te coûtent cher : 4 avantages laissés filer sur 6.'
    )
    expect(scanSays(1, diagnosis({ weakest: null }))).toMatch(
      'aucun avantage laissé filer'
    )
    expect(
      scanSays(
        1,
        diagnosis({
          weakest: null,
          endgames: { games: 0, advantages: 0, lost: 0, types: [] }
        })
      )
    ).toMatch('Peu de finales')
    expect(scanSays(1, diagnosis({ games: 0 }))).toMatch('aucune partie')
  })

  it('describes each module from the facts only', () => {
    expect(moduleCard(woodpecker('white'))).toEqual({
      title: 'Woodpecker · Italian Game',
      subtitle: 'Ta n°1 avec les blancs · 38 parties · 55 % des points',
      text: '100 puzzles tirés de parties jouées dans cette ouverture, toi avec les blancs. Les mêmes, séance après séance.',
      glyph: '♔︎'
    })
    expect(
      moduleCard(
        woodpecker('black', { included: false, missing: 'no_puzzles' })
      ).text
    ).toMatch('Pas assez de puzzles')
    expect(
      moduleCard(
        woodpecker('black', {
          included: false,
          missing: 'no_games',
          facts: { color: 'black', opening: null, settings: {} }
        })
      ).text
    ).toMatch('Aucune de tes 200 dernières parties avec les noirs')

    const blindfold = {
      module: 'blindfold',
      minutes: 5,
      included: true,
      missing: null,
      facts: { weakest: rook, settings: {} }
    }
    expect(moduleCard(blindfold).text).toMatch('de mémoire')

    const endgames = {
      module: 'endgames',
      minutes: 10,
      included: true,
      missing: null,
      facts: {
        weakest: rook,
        settings: { mode: 'review', themes: ['rooks'], elo: 1800 }
      }
    }
    expect(moduleCard(endgames)).toEqual({
      title: 'Finales · Tours',
      subtitle: 'Contre Maia, à ton niveau',
      text: 'Tes finales de type « tours » : 4 avantages laissés filer sur 6. 10 minutes de finales (Tours) pour convertir enfin tes avantages.',
      glyph: null
    })
    expect(
      moduleCard({
        ...endgames,
        facts: { weakest: null, settings: { themes: ['knights'] } }
      }).title
    ).toBe('Finales · Cavaliers')
    expect(
      moduleCard({
        module: 'brilliancy',
        minutes: 2,
        included: true,
        missing: null,
        facts: { settings: {} }
      }).title
    ).toBe('Brillant ou Blunder')

    const repertoire = {
      module: 'repertoire',
      minutes: 10,
      included: false,
      missing: 'not_asked',
      facts: { repertoires: [], settings: {} }
    }
    expect(moduleCard(repertoire).subtitle).toBe('Répertoire manquant')
    expect(
      moduleCard({
        ...repertoire,
        included: true,
        missing: null,
        facts: { repertoires: [{ id: 'a', name: 'Italienne' }] }
      }).subtitle
    ).toBe('Italienne')

    const evaluation = {
      module: 'evaluation',
      minutes: 2,
      included: true,
      missing: null,
      facts: { endgames: { games: 30, advantages: 12, lost: 7 } }
    }
    expect(moduleCard(evaluation).text).toMatch(
      'Sur 30 finales, tu avais l’avantage dans 12 et tu en as laissé filer 7.'
    )
  })

  it('tells the need each module answers, and Albert sums the session up', () => {
    const repertoire = {
      module: 'repertoire',
      minutes: 10,
      included: true,
      missing: null,
      facts: {
        repertoires: [{ id: 'a', name: 'Italienne', color: 'white' }],
        opening: diagnosis().white[0],
        settings: { repertoireIds: ['a'] }
      }
    }
    const endgames = {
      module: 'endgames',
      minutes: 10,
      included: true,
      missing: null,
      facts: { weakest: rook, settings: { themes: ['rooks'] } }
    }
    const modules = [repertoire, woodpecker('white'), endgames]
    const existing = composition(modules, {
      repertoire: { status: 'existing', repertoires: [] }
    })
    expect(needText(repertoire, existing)).toBe(
      'Italian Game, ta n°1 avec les Blancs (38 parties) : ton répertoire « Italienne ».'
    )
    expect(needText(repertoire, composition(modules))).toBe(
      'Ton répertoire blanc, tiré de tes parties : « Italienne ».'
    )
    expect(
      needText(
        { ...repertoire, included: false, missing: 'not_asked', facts: {} },
        composition(modules)
      )
    ).toMatch('Pas encore de répertoire')
    expect(needText(modules[1], composition(modules))).toBe(
      'Italian Game : ta n°1 avec les blancs, 38 parties.'
    )
    expect(needText(endgames, composition(modules))).toBe(
      'Finales de type « tours » : 4 avantages laissés filer sur 6.'
    )
    expect(
      needText(
        {
          ...endgames,
          facts: { weakest: null, settings: { themes: ['pawns'] } }
        },
        composition(modules)
      )
    ).toBe('Une finale au hasard : Pions.')

    expect(sessionSays(composition(modules))).toBe(
      'On commence par ton répertoire blanc. Ensuite, cap sur tes finales de type « tours » : c’est là que tu laisses filer le plus d’avantages.'
    )
    const without = [{ ...repertoire, included: false }, woodpecker('white')]
    expect(
      sessionSays(
        composition(without, { diagnosis: diagnosis({ weakest: null }) })
      )
    ).toBe(
      'On commence par ta vision du jeu. Ensuite, ton jugement, tes tactiques et tes finales.'
    )
    expect(playedModules(composition(without))).toHaveLength(1)
  })

  it('colours the steps and keeps the three reasons', () => {
    expect(
      [
        woodpecker('white'),
        { module: 'brilliancy', facts: {} },
        { module: 'endgames', facts: {} }
      ].map(moduleColor)
    ).toEqual(['#FFD43B', '#7FE3D6', '#A8E6A1'])
    expect(WOODPECKER_REASONS.map(r => r.title)).toEqual([
      'Automatiser la reconnaissance des motifs',
      'Améliorer la vitesse de calcul',
      'Transformer les erreurs en automatismes positifs'
    ])
  })

  it('words the refusals', () => {
    expect(
      coachErrorText({
        response: { status: 422, data: { detail: 'limit_plans' } }
      })
    ).toMatch('50 sessions')
    expect(
      coachErrorText({
        response: { status: 409, data: { detail: 'not_ready' } }
      })
    ).toMatch('pas finie')
  })
})

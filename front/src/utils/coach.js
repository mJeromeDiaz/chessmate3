/**
 * The tailored session's words and colours (design "Session perso", docs/COACH.md), from what the
 * API says of the user's games: the diagnosis (`GET /coach/builds/{id}/diagnosis`) and the
 * composition (`POST /coach/builds/{id}/session`). Pure, so that it can be tested alone; nothing is
 * claimed that the games do not say.
 *
 * @typedef {{family: string, games: number, wins: number, draws: number, losses: number}} Opening
 * @typedef {{type: string, label: string, games: number, wins: number, draws: number, losses: number, advantages: number, lost: number}} Endgame
 * @typedef {object} Diagnosis
 * @property {number} games
 * @property {number} analysed
 * @property {number} rating
 * @property {Opening[]} white
 * @property {Opening[]} black
 * @property {{games: number, advantages: number, lost: number, types: Endgame[]}} endgames
 * @property {Endgame|null} weakest
 * @typedef {{module: string, minutes: number, included: boolean, missing: string|null, facts: Record<string, any>}} CoachModule
 * @typedef {{planId: string, minutes: number, diagnosis: Diagnosis, repertoire: {status: string, repertoires: {id: string, name: string, color: string}[]}, modules: CoachModule[]}} Composition
 */

import { autoErrorText } from '@/utils/repertoireAuto'
import { intlLocale } from '@/i18n'

/** Games read, and their speeds (App\Coach\CoachRules). */
export const COACH_GAMES = 200
export const COACH_PERFS = ['blitz', 'rapid', 'classical']

/** The endgame kinds as the scan names them before the diagnosis (App\Enum\Chess\EndgameType). */
export const ENDGAME_LABELS = {
  pawn: 'Pions',
  rook: 'Tours',
  minor: 'Pièces mineures',
  queen: 'Dames',
  rook_minor: 'Tours et pièces mineures',
  mixed: 'Mixtes'
}

/** Why Woodpecker helps (the user's words, 2026-10-11). */
export const WOODPECKER_REASONS = [
  {
    title: 'Automatiser la reconnaissance des motifs',
    text: 'Le Woodpecker fait revoir plusieurs fois les mêmes tactiques jusqu’à ce que les motifs deviennent presque automatiques : fourchettes, clouages, attaques doubles, sacrifices… L’objectif est de voir plus vite les opportunités en partie.'
  },
  {
    title: 'Améliorer la vitesse de calcul',
    text: 'En répétant régulièrement les mêmes exercices, tu passes progressivement de la réflexion consciente à une reconnaissance immédiate. Tu dépenses alors ton temps de réflexion sur les positions complexes plutôt que sur des tactiques élémentaires.'
  },
  {
    title: 'Transformer les erreurs en automatismes positifs',
    text: 'Le principe du Woodpecker est de rejouer les puzzles jusqu’à les résoudre rapidement et correctement. Une tactique que tu avais ratée devient progressivement un schéma que ton cerveau reconnaît naturellement. C’est particulièrement efficace pour réduire les erreurs tactiques récurrentes.'
  }
]

/** The light method, as the session plays it (docs/WOODPECKER.md). */
export const WOODPECKER_METHOD = [
  'Chaque séance repart du premier puzzle du set.',
  'Tu enchaînes les puzzles jusqu’à la fin du chrono, le plus vite possible sans te tromper.',
  'Quand tu en viens à bout, le set grandit de 20 puzzles.'
]

/**
 * The colour of each step, in the session's order: Woodpecker with White, with Black, repertoire,
 * blindfold, evaluation.
 *
 * @param {CoachModule} module
 * @returns {string}
 */
export function moduleColor(module) {
  if (module.module === 'woodpecker') {
    return module.facts.color === 'black' ? '#FFE27A' : '#FFD43B'
  }
  return (
    { repertoire: '#FFB38A', blindfold: '#4FB2FF', evaluation: '#CBBEFF' }[
      module.module
    ] ?? '#E9E6F3'
  )
}

/** @param {string} color 'white' or 'black' */
const side = color => (color === 'black' ? 'les noirs' : 'les blancs')

/** @param {number} n @param {string} word @param {string} [plural] */
const count = (n, word, plural = `${word}s`) =>
  `${n.toLocaleString(intlLocale())} ${n > 1 ? plural : word}`

/**
 * Share of the points: wins plus half the draws.
 *
 * @param {{games: number, wins: number, draws: number}} record
 * @returns {number|null} 0 to 100
 */
export function score(record) {
  return record.games
    ? Math.round(((record.wins + record.draws / 2) / record.games) * 100)
    : null
}

/**
 * "7 avantages laissés filer sur 9"
 *
 * @param {{advantages: number, lost: number}} endgame
 */
export function slipText(endgame) {
  return `${count(endgame.lost, 'avantage laissé filer', 'avantages laissés filer')} sur ${endgame.advantages}`
}

/**
 * Albert's words while the games are read, then once the diagnosis is in.
 *
 * @param {number} progress 0 to 1
 * @param {Diagnosis|null} diagnosis
 */
export function scanSays(progress, diagnosis) {
  if (diagnosis) {
    if (!diagnosis.games) {
      return 'Je n’ai trouvé aucune partie à lire dans ces cadences.'
    }
    if (diagnosis.weakest) {
      return `Trouvé ! Tes finales de type « ${diagnosis.weakest.label.toLowerCase()} » te coûtent cher : ${slipText(diagnosis.weakest)}.`
    }
    if (diagnosis.endgames.games) {
      return 'Tes finales tiennent la route : aucun avantage laissé filer. On garde le cap.'
    }
    return 'Peu de finales dans ces parties : on travaille tes ouvertures et ta vision.'
  }
  if (progress < 0.3) return 'Je lis tes parties une par une…'
  if (progress < 0.5) return 'Je repère tes ouvertures favorites…'
  return 'Des finales apparaissent, je les classe…'
}

/**
 * The need a module answers, as the composition screen reveals it.
 *
 * @param {CoachModule} module
 * @param {Composition} composition
 */
export function needText(module, composition) {
  const f = module.facts
  switch (module.module) {
    case 'woodpecker':
      if (!f.opening)
        return `Aucune partie avec ${side(f.color)} : pas de Woodpecker de ce côté.`
      return `${f.opening.family} : ta n°1 avec ${side(f.color)}, ${count(f.opening.games, 'partie')}.`
    case 'repertoire':
      return (
        {
          existing: `Tes répertoires : ${count(f.repertoires?.length ?? 0, 'répertoire')} à réviser.`,
          generated: `Ton répertoire, tiré de tes parties : ${count(f.repertoires?.length ?? 0, 'ouverture')}.`,
          not_asked: 'Pas encore de répertoire : il te manque.',
          no_opening:
            'Aucune ouverture jouée assez souvent pour un répertoire.',
          failed: 'Le répertoire n’a pas pu être créé.'
        }[module.missing ?? composition.repertoire.status] ?? 'Ton répertoire.'
      )
    case 'blindfold':
      return f.weakest
        ? `Finales de type « ${f.weakest.label.toLowerCase()} » : ${slipText(f.weakest)}.`
        : 'Voir sans regarder, pour calculer plus loin.'
    case 'evaluation':
      return f.endgames?.lost
        ? `En finale, tu as laissé filer ${slipText(f.endgames)}.`
        : 'Juger une position avant de choisir un plan.'
    default:
      return ''
  }
}

/**
 * A module's card on the session screen.
 *
 * @param {CoachModule} module
 * @returns {{title: string, subtitle: string, text: string, glyph: string|null}}
 */
export function moduleCard(module) {
  const f = module.facts
  switch (module.module) {
    case 'woodpecker': {
      const glyph = f.color === 'black' ? '♚︎' : '♔︎'
      if (!f.opening) {
        return {
          title: 'Woodpecker',
          subtitle: `Avec ${side(f.color)}`,
          text: `Aucune de tes ${COACH_GAMES} dernières parties avec ${side(f.color)} : rien à travailler de ce côté.`,
          glyph
        }
      }
      const rate = score(f.opening)
      const subtitle = `Ta n°1 avec ${side(f.color)} · ${count(f.opening.games, 'partie')}${rate === null ? '' : ` · ${rate} % des points`}`
      if (!module.included) {
        return {
          title: `Woodpecker · ${f.opening.family}`,
          subtitle,
          text: 'Pas assez de puzzles de cette ouverture dans notre catalogue : on passe pour cette fois.',
          glyph
        }
      }
      return {
        title: `Woodpecker · ${f.opening.family}`,
        subtitle,
        text: `${f.puzzleCount} puzzles tirés de parties jouées dans cette ouverture${f.bothColors ? ', des deux côtés' : `, toi avec ${side(f.color)}`}. Les mêmes, séance après séance.`,
        glyph
      }
    }
    case 'repertoire': {
      const names = (f.repertoires ?? []).map(r => r.name)
      const texts = {
        not_asked:
          'Tu n’as pas encore de répertoire : génère-le depuis tes parties, puis relance l’analyse.',
        no_opening:
          'Aucune ouverture jouée au moins 3 fois : pas de répertoire à tirer de ces parties.',
        failed:
          'La création du répertoire a échoué : réessaie depuis la page Répertoire.'
      }
      return {
        title: 'Répertoire',
        subtitle: module.included
          ? names.slice(0, 2).join(', ') +
            (names.length > 2 ? ` +${names.length - 2}` : '')
          : 'Répertoire manquant',
        text: module.included
          ? 'Tes lignes à réviser d’abord (répétition espacée), tes coups préparés joués de mémoire.'
          : (texts[module.missing] ?? 'Pas de répertoire pour cette séance.'),
        glyph: null
      }
    }
    case 'blindfold':
      return {
        title: 'Jeu à l’aveugle',
        subtitle: 'Tu joues sans voir les pièces',
        text: f.weakest
          ? `Pour mieux visualiser, et calculer jusqu’au bout. Tes finales de type « ${f.weakest.label.toLowerCase()} » : ${slipText(f.weakest)}.`
          : 'Pour mieux visualiser : la position s’affiche, disparaît, et tu joues de mémoire.',
        glyph: null
      }
    case 'evaluation':
      return {
        title: 'Évaluation',
        subtitle: 'Qui est mieux, et de combien ?',
        text: f.endgames?.lost
          ? `Sur ${count(f.endgames.games, 'finale')}, tu avais l’avantage dans ${f.endgames.advantages} et tu en as laissé filer ${f.endgames.lost}. Juger la position, c’est choisir le bon plan.`
          : 'Juger une position au premier coup d’œil, avant de choisir un plan.',
        glyph: null
      }
    default:
      return { title: module.module, subtitle: '', text: '', glyph: null }
  }
}

/**
 * Albert's word on the session screen.
 *
 * @param {Composition} composition
 */
export function sessionSays(composition) {
  const woodpeckers = composition.modules.filter(
    m => m.module === 'woodpecker' && m.included
  ).length
  const start =
    woodpeckers === 2
      ? 'On commence par tes deux ouvertures phares.'
      : woodpeckers === 1
        ? 'On commence par ton ouverture phare.'
        : 'On commence par ta vision du jeu.'
  const weakest = composition.diagnosis.weakest
  return weakest
    ? `${start} Ensuite, cap sur tes finales de type « ${weakest.label.toLowerCase()} » : c’est là que tu laisses filer le plus d’avantages.`
    : `${start} Ensuite, on entretient ta vision et ton jugement.`
}

/**
 * The included modules of a composition (what the session plays), in order.
 *
 * @param {Composition} composition
 * @returns {CoachModule[]}
 */
export function playedModules(composition) {
  return composition.modules.filter(m => m.included)
}

/**
 * An API refusal, in words.
 *
 * @param {any} error
 */
export function coachErrorText(error) {
  const detail = error?.response?.data?.detail
  if (detail === 'limit_plans') {
    return 'Tu as atteint la limite de 50 sessions enregistrées : supprimes-en une avant de continuer.'
  }
  return autoErrorText(error)
}

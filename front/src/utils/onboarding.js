import {
  AUTO_REPERTOIRE_PATH,
  COACH_PATH,
  TOUR_PATH
} from '@/utils/auth/authFlow'
import { profImage } from '@/utils/prof/images'

/**
 * The guided tour (design "Visite guidée", docs/ONBOARDING.md): its screens, the professors' lines
 * and the choices. Pure. The answers come from the API (GET /onboarding).
 *
 * @typedef {'beginner'|'amateur'|'confirmed'|'expert'|'pro'} LevelId
 * @typedef {'rating'|'blunders'|'fun'|'understanding'|'kasparov'} GoalId
 *
 * @typedef {object} OnboardingAnswers
 * @property {LevelId|null} level
 * @property {GoalId|null} goal
 * @property {LevelId|null} suggestedLevel the level of the linked Lichess account's rating
 * @property {{linked: boolean, perf: string|null, rating: number|null}} lichess
 * @property {boolean} canSeedRating whether a declared level still sets the puzzle rating
 * @property {number|null} puzzleRating
 *
 * @typedef {object} TourTheme
 * @property {string} bg
 * @property {string} deep
 * @property {string} accent readable on white
 * @property {string} line1 title colour, first line
 * @property {string} line2 title colour, second line
 * @property {string} [darkAccent] readable on the dark surface (default: bg)
 *
 * @typedef {object} TourModule
 * @property {string} id
 * @property {string} title
 * @property {string} glyph
 * @property {string} bg
 * @property {string} ink
 * @property {string} soft
 * @property {string} accent
 * @property {string} desc
 * @property {string} why
 * @property {string} [darkAccent] readable on the dark surface (default: bg)
 * @property {string} [badge]
 * @property {boolean} [soon] not playable yet
 */

/** Where the tour lives. */
export { TOUR_PATH }

/** The step the tour is at, per browser (a convenience: lost storage restarts it). */
export const TOUR_STEP_KEY = 'dontstayrooky.tourStep'

/** The screens, in order. */
export const STEPS = [
  'hello',
  'level',
  'goal',
  'albert',
  'aaron',
  'lizy',
  'ray',
  'end'
]

/** The progress bar's segments, and the screens each one holds. */
export const SEGMENTS = [
  { label: 'Accueil', steps: ['hello'] },
  { label: 'Niveau', steps: ['level'] },
  { label: 'Objectif', steps: ['goal'] },
  { label: 'Les profs', steps: ['albert', 'aaron', 'lizy', 'ray'] },
  { label: 'Ton plan', steps: ['end'] }
]

/** Albert's violet, the tour's own screens. */
const VIOLET = {
  bg: '#8B6BFF',
  deep: '#5A3BE0',
  accent: '#5A3BE0',
  line1: '#1B1530',
  line2: '#FFFFFF'
}

/** @type {Record<string, TourModule>} */
export const MODULES = {
  woodpecker: {
    id: 'woodpecker',
    title: 'Woodpecker',
    badge: 'Méthode phare',
    glyph: '♝︎',
    bg: '#C6F432',
    soft: '#F0FBCF',
    accent: '#5A7A00',
    ink: '#1B1530',
    desc: 'Le même set de puzzles, en cycles de plus en plus rapides.',
    why: 'À force de revoir les mêmes motifs, ils deviennent des réflexes : tu calcules moins, tu vois plus. C’est comme apprendre une langue : au début on traduit, ensuite on parle.'
  },
  puzzles: {
    id: 'puzzles',
    title: 'Puzzles',
    glyph: '♞︎',
    bg: '#FF6FAE',
    soft: '#FFE6F1',
    accent: '#C02670',
    ink: '#1B1530',
    desc: 'Des tactiques à ton niveau, par thème quand tu veux cibler.',
    why: 'Tu repères une fourchette en 2 secondes au lieu de la chercher 2 minutes. Et ton adversaire, lui, n’aura pas cette chance.'
  },
  streak: {
    id: 'streak',
    title: 'Puzzle streak',
    glyph: '⚡︎',
    bg: '#FFD43B',
    soft: '#FFF5D1',
    accent: '#8A6A00',
    ink: '#1B1530',
    desc: 'Du plus facile au plus dur : une erreur et tu repars de zéro.',
    why: 'La pression monte à chaque puzzle. Rester lucide quand tout se joue sur un coup, c’est exactement ce qui manque en partie.'
  },
  repertoire: {
    id: 'repertoire',
    title: 'Répertoire',
    badge: 'Indispensable',
    glyph: '♜︎',
    bg: '#4FB2FF',
    soft: '#E2F2FF',
    accent: '#0F6BBA',
    ink: '#1B1530',
    desc: 'Tes ouvertures, révisées coup par coup jusqu’à ce qu’elles sortent toutes seules.',
    why: 'Fini les dix premiers coups au feeling : tu arrives au milieu de partie en terrain connu, avec du temps à la pendule.'
  },
  evaluation: {
    id: 'evaluation',
    title: 'Évaluation de position',
    glyph: '♟︎',
    bg: '#FFD43B',
    soft: '#FFF5D1',
    accent: '#8A6A00',
    ink: '#1B1530',
    desc: 'Qui est mieux, et de combien ? Juger d’un coup d’œil.',
    why: 'Savoir où tu en es avant de calculer, c’est choisir les bonnes variantes au lieu de toutes les essayer.'
  },
  analyse: {
    id: 'analyse',
    title: 'Analyse de parties',
    soon: true,
    glyph: '♔︎',
    bg: '#2ED3A8',
    soft: '#DAF8EF',
    accent: '#0B7D62',
    ink: '#1B1530',
    desc: 'Revenir sur tes parties, surtout les défaites.',
    why: 'Tes erreurs deviennent ton programme, au lieu de se répéter partie après partie.'
  },
  coach: {
    id: 'coach',
    title: 'Séance sur mesure',
    glyph: '✦',
    bg: '#FF8A3D',
    soft: '#FFEBDD',
    accent: '#B84A0E',
    ink: '#1B1530',
    desc: 'Je lis tes 200 dernières parties Lichess, je repère les finales qui te coûtent des points et je compose ta séance.',
    why: 'Tu travailles ce qui te fait perdre, pas ce que tu sais déjà. Le temps d’entraînement est rare : autant viser juste.'
  },
  finales: {
    id: 'finales',
    title: 'Finales',
    soon: true,
    glyph: '♚︎',
    bg: '#FF8A3D',
    soft: '#FFEBDD',
    accent: '#B84A0E',
    ink: '#1B1530',
    desc: 'Tours, pions, pièces mineures : les finales essentielles.',
    why: 'Un pion de plus ne vaut rien si tu ne sais pas l’emmener à dame.'
  },
  aveugle: {
    id: 'aveugle',
    title: 'Jeu à l’aveugle',
    badge: 'Super-pouvoir',
    glyph: '♘︎',
    bg: '#2B2250',
    soft: '#E7E4F2',
    accent: '#4A3D86',
    darkAccent: '#B3A1FF',
    ink: '#FFFFFF',
    desc: 'La position s’affiche, disparaît, puis tu joues de mémoire sur un échiquier vide.',
    why: 'Ta visualisation s’entraîne : tu calcules plus loin, même avec les pièces sous les yeux.'
  },
  coordonnees: {
    id: 'coordonnees',
    title: 'Coordonnées',
    glyph: 'e4',
    bg: '#2B2250',
    soft: '#E7E4F2',
    accent: '#4A3D86',
    darkAccent: '#B3A1FF',
    ink: '#FFFFFF',
    desc: 'Trouver e4 sans réfléchir, sur un échiquier sans lettres ni chiffres.',
    why: 'Une case qu’on nomme vite, c’est une variante qu’on calcule sans se perdre en route.'
  }
}

/**
 * The professors' screens.
 *
 * @type {Record<string, TourTheme & {title: [string, string], image: string, glyph?: string, kicker: string, line: string, modules: string[]}>}
 */
export const PROF_SCREENS = {
  albert: {
    title: ['Albert', 'Stein'],
    image: 'albert-v2-idea',
    bg: '#FF6FAE',
    deep: '#E03C86',
    accent: '#C02670',
    line1: '#1B1530',
    line2: '#FFFFFF',
    kicker: 'ALBERT · PUZZLES, WOODPECKER & STREAK',
    line: 'Le Fou de la tactique, c’est moi : je vois les diagonales avant que tu voies le piège. Et je les vois en diagonale, évidemment.',
    modules: ['woodpecker', 'puzzles', 'streak']
  },
  aaron: {
    title: ['Aaron,', 'le Roi'],
    image: 'aaron-wink',
    bg: '#4FB2FF',
    deep: '#1C86E0',
    accent: '#0F6BBA',
    line1: '#1B1530',
    line2: '#FFFFFF',
    kicker: 'AARON · RÉPERTOIRE & ÉVALUATION',
    line: 'Je prends l’initiative dès le premier coup et je ne la rends jamais. Même au dîner, demande à Lizy.',
    modules: ['repertoire', 'evaluation', 'analyse']
  },
  lizy: {
    title: ['Lizy,', 'la Reine'],
    image: 'lizy-cool',
    bg: '#FF8A3D',
    deep: '#E0601A',
    accent: '#B84A0E',
    line1: '#1B1530',
    line2: '#FFFFFF',
    kicker: 'LIZY · FINALES & SÉANCE SUR MESURE',
    line: 'Ton petit avantage, je le transforme en point entier. Sans trembler, et sans demander l’avis d’Aaron.',
    modules: ['coach', 'finales']
  },
  ray: {
    title: ['Ray,', 'le mystère'],
    image: '',
    glyph: '♘︎',
    bg: '#2B2250',
    deep: '#5B4BA6',
    accent: '#4A3D86',
    darkAccent: '#C6F432',
    line1: '#FFFFFF',
    line2: '#C6F432',
    kicker: 'ALBERT · UN PROF DANS L’OMBRE',
    line: 'Ray joue les yeux fermés. Il se présentera lui-même… dès qu’il aura trouvé la porte.',
    modules: ['aveugle', 'coordonnees']
  }
}

/** The level cards, weakest first. */
export const LEVELS = [
  {
    id: 'beginner',
    name: 'Débutant',
    glyph: '♙︎',
    hint: 'Le fou et la tour, je les confonds parfois.',
    seed: 1000,
    line: 'Parfait : aucune mauvaise habitude à déloger. Enfin, presque.'
  },
  {
    id: 'amateur',
    name: 'Amateur',
    glyph: '♘︎',
    hint: 'Je connais le mat du berger. Des deux côtés.',
    seed: 1400,
    line: 'Le niveau idéal : assez fort pour gaffer avec style, assez curieux pour arrêter.'
  },
  {
    id: 'confirmed',
    name: 'Confirmé',
    glyph: '♗︎',
    hint: 'Ma Najdorf tient sans antisèche.',
    seed: 1800,
    line: 'Joli. On va polir ce qui coince, sans te refaire le coup du berger.'
  },
  {
    id: 'expert',
    name: 'Expert',
    glyph: '♕︎',
    hint: 'Je corrige les commentateurs en direct.',
    seed: 2200,
    line: 'Ah. Je vais devoir réviser mes notes, moi.'
  },
  {
    id: 'pro',
    name: 'Pro',
    glyph: '♔︎',
    hint: 'On m’appelle « Maître », et ce n’est pas ironique.',
    seed: 2500,
    line: 'Un pro ici ? Les puzzles vont transpirer. Moi aussi.'
  }
]

/** The goals (one only), each with Albert's answer. */
export const GOALS = [
  {
    id: 'rating',
    glyph: '▲',
    label: 'Gagner des Elo',
    line: 'Des Elo, c’est noté. On commence par arrêter de les offrir.'
  },
  {
    id: 'blunders',
    glyph: '♛︎',
    label: 'Ne plus offrir ma dame',
    line: 'Une dame offerte, c’est un cadeau que personne n’a demandé. On va apprendre à la garder.'
  },
  {
    id: 'fun',
    glyph: '♟︎',
    label: 'M’amuser',
    line: 'Excellent choix. Personne n’a jamais progressé en s’ennuyant.'
  },
  {
    id: 'understanding',
    glyph: '◉',
    label: 'Comprendre ce que je joue',
    line: 'La plus belle ambition : un coup qu’on comprend vaut dix coups qu’on récite.'
  },
  {
    id: 'kasparov',
    glyph: '♚︎',
    label: 'Arracher une nulle à Kasparov',
    line: 'Une nulle contre Garry ? Il va falloir du Woodpecker. Beaucoup de Woodpecker.'
  }
]

/**
 * Where to start, at the end of the tour.
 *
 * @type {{id: string, glyph: string, title: string, desc: string, path: string, lichess: boolean}[]}
 */
export const END_CHOICES = [
  {
    id: 'coach',
    glyph: '✦',
    title: 'Séance sur mesure',
    desc: 'Lizy lit tes parties Lichess et compose ta séance : finales, ouvertures, tactique.',
    path: COACH_PATH,
    lichess: true
  },
  {
    id: 'repertoire',
    glyph: '♜︎',
    title: 'Répertoire automatique',
    desc: 'Aaron construit ton répertoire avec les ouvertures que tu joues déjà.',
    path: AUTO_REPERTOIRE_PATH,
    lichess: true
  },
  {
    id: 'builder',
    glyph: '♞︎',
    title: 'Je compose ma séance',
    desc: 'Tu choisis les modules et les durées. Pour les maniaques du contrôle.',
    path: '/session/new',
    lichess: false
  }
]

/** Lichess' speeds, as said on the level screen. */
const PERFS = { rapid: 'rapide', blitz: 'blitz', classical: 'classique' }

const number = new Intl.NumberFormat('fr-FR')

/**
 * The segment a screen belongs to.
 *
 * @param {string} step
 */
export function segmentOf(step) {
  return SEGMENTS.findIndex(s => s.steps.includes(step))
}

/**
 * The progress bar: each segment's fill (the professors' segment fills one professor at a time).
 *
 * @param {string} step
 * @returns {{label: string, percent: number, done: boolean, first: number}[]}
 */
export function progressOf(step) {
  const current = segmentOf(step)
  return SEGMENTS.map((segment, i) => ({
    label: segment.label,
    percent:
      i < current
        ? 100
        : i > current
          ? 0
          : ((segment.steps.indexOf(step) + 1) / segment.steps.length) * 100,
    done: i < current,
    first: STEPS.indexOf(segment.steps[0])
  }))
}

/**
 * The chip above the professor: "NIVEAU · 2 / 5", "LES PROFS · PROF 2 / 4".
 *
 * @param {string} step
 */
export function chipOf(step) {
  const i = segmentOf(step)
  const segment = SEGMENTS[i]
  const count =
    segment.steps.length > 1
      ? `PROF ${segment.steps.indexOf(step) + 1} / ${segment.steps.length}`
      : `${i + 1} / ${SEGMENTS.length}`
  return { name: segment.label.toUpperCase(), count }
}

/**
 * A step index read back from storage, kept within the tour.
 *
 * @param {string|null} stored
 */
export function stepIndex(stored) {
  const i = Number.parseInt(stored ?? '', 10)
  return Number.isInteger(i) ? Math.min(STEPS.length - 1, Math.max(0, i)) : 0
}

/**
 * @param {string|null|undefined} id
 */
export function levelOf(id) {
  return LEVELS.find(l => l.id === id) ?? null
}

/**
 * @param {string|null|undefined} id
 */
export function goalOf(id) {
  return GOALS.find(g => g.id === id) ?? null
}

/**
 * The screen's look: a professor's colours, or the tour's violet.
 *
 * @param {string} step
 * @returns {TourTheme}
 */
export function themeOf(step) {
  return PROF_SCREENS[step] ?? VIOLET
}

/**
 * The screen's title, on two lines.
 *
 * @param {string} step
 * @returns {[string, string]}
 */
export function titleOf(step) {
  if (PROF_SCREENS[step]) return PROF_SCREENS[step].title
  return /** @type {Record<string, [string, string]>} */ ({
    hello: ['Bonjour,', 'champion !'],
    level: ['Ton niveau,', 'sans tricher'],
    goal: ['Ton grand', 'objectif ?'],
    end: ['À toi', 'de jouer !']
  })[step]
}

/**
 * The line over the bubble.
 *
 * @param {string} step
 */
export function kickerOf(step) {
  if (PROF_SCREENS[step]) return PROF_SCREENS[step].kicker
  return /** @type {Record<string, string>} */ ({
    hello: 'ALBERT · TON PROF PRINCIPAL',
    level: 'ALBERT · FAISONS CONNAISSANCE',
    goal: 'ALBERT · TES AMBITIONS',
    end: 'ALBERT · ON S’Y MET ?'
  })[step]
}

/**
 * What the professor says on a screen, given what was chosen so far.
 *
 * @param {string} step
 * @param {{level: string|null, goal: string|null, answers: OnboardingAnswers|null, linking?: boolean}} state
 */
export function lineOf(step, { level, goal, answers, linking = false }) {
  if (step === 'hello')
    return 'Bonjour, je suis Albert Stein, ton prof principal. Promis, je serai plus patient que ton adversaire quand tu laisses ta dame en prise.'
  if (step === 'level') {
    if (linking) return 'Je file sur Lichess voir tes parties… Ne bouge pas.'
    const chosen = levelOf(level)
    const lichess = answers?.lichess
    if (
      lichess?.rating &&
      chosen &&
      level === answers?.suggestedLevel &&
      lichess.perf
    )
      return `${number.format(lichess.rating)} en ${PERFS[lichess.perf] ?? lichess.perf}, d’après Lichess. ${chosen.line}`
    return chosen
      ? chosen.line
      : 'Lie ton Lichess, ou avoue ton niveau. Je ne juge pas… pas encore.'
  }
  if (step === 'goal')
    return (
      goalOf(goal)?.line ??
      'Qu’est-ce qui t’amène ? Un seul choix : le plus sincère, pas le plus raisonnable.'
    )
  if (PROF_SCREENS[step]) return PROF_SCREENS[step].line
  return answers?.lichess.linked
    ? 'Assez de théorie ! Choisis par où commencer, je m’occupe du reste.'
    : 'Assez de théorie ! Pour le sur-mesure, il me faudra ton Lichess. Sinon, compose ta séance toi-même.'
}

/**
 * Albert's pose (a professor's screen shows that professor).
 *
 * @param {string} step
 * @param {{level: string|null, goal: string|null, linking?: boolean}} state
 * @returns {string} the picture's file name
 */
export function poseOf(step, { level, goal, linking = false }) {
  if (PROF_SCREENS[step]) return PROF_SCREENS[step].image
  if (step === 'level') {
    if (linking || !level) return 'albert-v2-think'
    return level === 'expert' || level === 'pro'
      ? 'albert-v2-wow'
      : 'albert-v2-cheer'
  }
  if (step === 'goal')
    return goal === 'kasparov' ? 'albert-v2-wow' : 'albert-v2-pointer'
  if (step === 'end') return 'albert-v2-book'
  return 'albert-v2-cheer'
}

/**
 * The line under the level cards: what the declared level does to the puzzle rating.
 *
 * @param {string|null} level
 * @param {OnboardingAnswers|null} answers
 */
export function ratingNote(level, answers) {
  if (!answers) return ''
  if (!answers.canSeedRating)
    return `Ton classement puzzle (${number.format(answers.puzzleRating ?? 1500)}) est déjà établi : il ne bouge plus qu’en jouant.`
  const chosen = levelOf(level)
  return chosen
    ? `Tes puzzles classés démarreront vers ${number.format(chosen.seed)}. Ensuite, c’est toi qui décides.`
    : ''
}

/**
 * The end screen's default choice: the tailored session with Lichess, the builder without.
 *
 * @param {boolean} linked
 */
export function defaultEndChoice(linked) {
  return linked ? 'coach' : 'builder'
}

/**
 * The main button: its label, and whether it can be pressed.
 *
 * @param {string} step
 * @param {{level: string|null, goal: string|null, end: string, linked: boolean, linking?: boolean}} state
 * @returns {{label: string, ok: boolean}}
 */
export function ctaOf(step, { level, goal, end, linked, linking = false }) {
  if (step === 'hello') return { label: 'Enchanté, Albert', ok: true }
  if (step === 'level')
    return level && !linking
      ? { label: 'C’est bien moi', ok: true }
      : { label: 'Choisis ton niveau', ok: false }
  if (step === 'goal')
    return goal
      ? { label: 'Voilà mon objectif', ok: true }
      : { label: 'Choisis ton objectif', ok: false }
  if (step === 'ray') return { label: 'Voir mon plan', ok: true }
  if (PROF_SCREENS[step]) return { label: 'Prof suivant', ok: true }
  const choice = END_CHOICES.find(c => c.id === end)
  if (choice?.lichess && !linked)
    return { label: 'Lier Lichess et continuer', ok: true }
  return { label: 'C’est parti !', ok: true }
}

/**
 * The small line under the main button.
 *
 * @param {string} step
 */
export function footOf(step) {
  if (step === 'hello')
    return 'Tu pourras revoir cette visite depuis ton profil'
  if (PROF_SCREENS[step])
    return 'Touche « Pourquoi ça aide » pour en savoir plus'
  if (step === 'end') return 'Visite à revoir dans Profil › Visite guidée'
  return ''
}

/**
 * The trio on the first screen, and Ray's empty seat.
 */
export function welcomeTrio() {
  return [
    { name: 'Albert', image: profImage('albert-v2-cheer'), bg: '#FF6FAE' },
    { name: 'Aaron', image: profImage('aaron-wink'), bg: '#4FB2FF' },
    { name: 'Lizy', image: profImage('lizy-joy'), bg: '#FF8A3D' },
    { name: '???', image: '', bg: '#2B2250' }
  ]
}

/**
 * An accent readable on the surface: the dark mode takes the bright colour.
 *
 * @param {{accent: string, bg: string, darkAccent?: string}} look
 * @param {boolean} dark
 */
export function accentOn(look, dark) {
  return dark ? (look.darkAccent ?? look.bg) : look.accent
}

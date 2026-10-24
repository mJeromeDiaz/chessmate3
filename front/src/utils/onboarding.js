import {
  AUTO_REPERTOIRE_PATH,
  COACH_PATH,
  TOUR_PATH
} from '@/utils/auth/authFlow'
import { standingImage } from '@/utils/mindMapPath'
import { profImage } from '@/utils/prof/images'
import { intlLocale, t } from '@/i18n'

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
  'bosses',
  'map',
  'end'
]

/** The progress bar's segments, and the screens each one holds. */
export const SEGMENTS = [
  ['hello', ['hello']],
  ['level', ['level']],
  ['goal', ['goal']],
  ['profs', ['albert', 'aaron', 'lizy', 'ray']],
  ['bosses', ['bosses']],
  ['map', ['map']],
  ['end', ['end']]
].map(([key, steps]) => ({
  get label() {
    return t(`onboarding.segments.${key}`)
  },
  steps
}))

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
    get title() {
      return t('onboarding.modules.woodpecker.title')
    },
    get badge() {
      return t('onboarding.modules.woodpecker.badge')
    },
    glyph: '♝︎',
    bg: '#C6F432',
    soft: '#F0FBCF',
    accent: '#5A7A00',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.woodpecker.desc')
    },
    get why() {
      return t('onboarding.modules.woodpecker.why')
    }
  },
  puzzles: {
    id: 'puzzles',
    get title() {
      return t('onboarding.modules.puzzles.title')
    },
    glyph: '♞︎',
    bg: '#FF6FAE',
    soft: '#FFE6F1',
    accent: '#C02670',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.puzzles.desc')
    },
    get why() {
      return t('onboarding.modules.puzzles.why')
    }
  },
  streak: {
    id: 'streak',
    get title() {
      return t('onboarding.modules.streak.title')
    },
    glyph: '⚡︎',
    bg: '#FFD43B',
    soft: '#FFF5D1',
    accent: '#8A6A00',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.streak.desc')
    },
    get why() {
      return t('onboarding.modules.streak.why')
    }
  },
  repertoire: {
    id: 'repertoire',
    get title() {
      return t('onboarding.modules.repertoire.title')
    },
    get badge() {
      return t('onboarding.modules.repertoire.badge')
    },
    glyph: '♜︎',
    bg: '#4FB2FF',
    soft: '#E2F2FF',
    accent: '#0F6BBA',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.repertoire.desc')
    },
    get why() {
      return t('onboarding.modules.repertoire.why')
    }
  },
  evaluation: {
    id: 'evaluation',
    get title() {
      return t('onboarding.modules.evaluation.title')
    },
    glyph: '♟︎',
    bg: '#FFD43B',
    soft: '#FFF5D1',
    accent: '#8A6A00',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.evaluation.desc')
    },
    get why() {
      return t('onboarding.modules.evaluation.why')
    }
  },
  analyse: {
    id: 'analyse',
    get title() {
      return t('onboarding.modules.analyse.title')
    },
    soon: true,
    glyph: '♔︎',
    bg: '#2ED3A8',
    soft: '#DAF8EF',
    accent: '#0B7D62',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.analyse.desc')
    },
    get why() {
      return t('onboarding.modules.analyse.why')
    }
  },
  coach: {
    id: 'coach',
    get title() {
      return t('onboarding.modules.coach.title')
    },
    glyph: '✦',
    bg: '#FF8A3D',
    soft: '#FFEBDD',
    accent: '#B84A0E',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.coach.desc')
    },
    get why() {
      return t('onboarding.modules.coach.why')
    }
  },
  finales: {
    id: 'finales',
    get title() {
      return t('onboarding.modules.finales.title')
    },
    glyph: '♚︎',
    bg: '#FF8A3D',
    soft: '#FFEBDD',
    accent: '#B84A0E',
    ink: '#1B1530',
    get desc() {
      return t('onboarding.modules.finales.desc')
    },
    get why() {
      return t('onboarding.modules.finales.why')
    }
  },
  aveugle: {
    id: 'aveugle',
    get title() {
      return t('onboarding.modules.aveugle.title')
    },
    get badge() {
      return t('onboarding.modules.aveugle.badge')
    },
    glyph: '♘︎',
    bg: '#2B2250',
    soft: '#E7E4F2',
    accent: '#4A3D86',
    darkAccent: '#B3A1FF',
    ink: '#FFFFFF',
    get desc() {
      return t('onboarding.modules.aveugle.desc')
    },
    get why() {
      return t('onboarding.modules.aveugle.why')
    }
  },
  coordonnees: {
    id: 'coordonnees',
    get title() {
      return t('onboarding.modules.coordonnees.title')
    },
    glyph: 'e4',
    bg: '#2B2250',
    soft: '#E7E4F2',
    accent: '#4A3D86',
    darkAccent: '#B3A1FF',
    ink: '#FFFFFF',
    get desc() {
      return t('onboarding.modules.coordonnees.desc')
    },
    get why() {
      return t('onboarding.modules.coordonnees.why')
    }
  }
}

/**
 * The professors' screens.
 *
 * @type {Record<string, TourTheme & {title: [string, string], image: string, glyph?: string, kicker: string, line: string, modules: string[]}>}
 */
export const PROF_SCREENS = {
  albert: {
    get title() {
      return [0, 1].map(n => t(`onboarding.profScreens.albert.title.${n}`))
    },
    image: 'albert-v2-idea',
    bg: '#FF6FAE',
    deep: '#E03C86',
    accent: '#C02670',
    line1: '#1B1530',
    line2: '#FFFFFF',
    get kicker() {
      return t('onboarding.profScreens.albert.kicker')
    },
    get line() {
      return t('onboarding.profScreens.albert.line')
    },
    modules: ['woodpecker', 'puzzles', 'streak']
  },
  aaron: {
    get title() {
      return [0, 1].map(n => t(`onboarding.profScreens.aaron.title.${n}`))
    },
    image: 'aaron-wink',
    bg: '#4FB2FF',
    deep: '#1C86E0',
    accent: '#0F6BBA',
    line1: '#1B1530',
    line2: '#FFFFFF',
    get kicker() {
      return t('onboarding.profScreens.aaron.kicker')
    },
    get line() {
      return t('onboarding.profScreens.aaron.line')
    },
    modules: ['repertoire', 'evaluation', 'analyse']
  },
  lizy: {
    get title() {
      return [0, 1].map(n => t(`onboarding.profScreens.lizy.title.${n}`))
    },
    image: 'lizy-cool',
    bg: '#FF8A3D',
    deep: '#E0601A',
    accent: '#B84A0E',
    line1: '#1B1530',
    line2: '#FFFFFF',
    get kicker() {
      return t('onboarding.profScreens.lizy.kicker')
    },
    get line() {
      return t('onboarding.profScreens.lizy.line')
    },
    modules: ['coach', 'finales']
  },
  ray: {
    get title() {
      return [0, 1].map(n => t(`onboarding.profScreens.ray.title.${n}`))
    },
    image: '',
    glyph: '♘︎',
    bg: '#2B2250',
    deep: '#5B4BA6',
    accent: '#4A3D86',
    darkAccent: '#C6F432',
    line1: '#FFFFFF',
    line2: '#C6F432',
    get kicker() {
      return t('onboarding.profScreens.ray.kicker')
    },
    get line() {
      return t('onboarding.profScreens.ray.line')
    },
    modules: ['aveugle', 'coordonnees']
  }
}

/**
 * @typedef {object} TourBoss
 * @property {string} id
 * @property {string|null} character the mind map's (its name, taunt, picture), null: the others
 * @property {'visualisation'|'calculation'|'endgames'|null} path
 * @property {string} chapter its place on the path, e.g. "CH. 1"
 * @property {number|null} crystals what beating it earns (docs/MIND_MAP.md, docs/ENDGAMES.md)
 * @property {string} dark the card's and the stage's colour
 * @property {string} glow
 * @property {string} accent bright, on the dark
 * @property {string} ink the stage's deep colour
 */

/**
 * The bosses shown on the tour (design « Visite guidée », « Tes pires ennemis »): a few of each
 * path, then the others in the shadow. No count anywhere: there are always more.
 *
 * @type {TourBoss[]}
 */
export const TOUR_BOSSES = [
  {
    id: 'jongleur',
    character: 'jongleur',
    path: 'visualisation',
    chapter: '1',
    crystals: 200,
    dark: '#2B1630',
    glow: 'rgba(230,70,60,.38)',
    accent: '#F2B640',
    ink: '#C0392B'
  },
  {
    id: 'metronome',
    character: 'metronome',
    path: 'calculation',
    chapter: '1',
    crystals: 200,
    dark: '#231B3A',
    glow: 'rgba(245,180,60,.4)',
    accent: '#F5C04A',
    ink: '#B07A12'
  },
  {
    id: 'cyclope',
    character: 'cyclope',
    path: 'visualisation',
    chapter: '2',
    crystals: 240,
    dark: '#16263A',
    glow: 'rgba(80,170,255,.4)',
    accent: '#7CC8FF',
    ink: '#1C86E0'
  },
  {
    id: 'gargouille',
    character: 'gargouille',
    path: 'visualisation',
    chapter: '3',
    crystals: 300,
    dark: '#2A2347',
    glow: 'rgba(160,140,230,.45)',
    accent: '#F5A524',
    ink: '#6B5BD6'
  },
  {
    id: 'fantome',
    character: 'fantome',
    path: 'visualisation',
    chapter: '5',
    crystals: 440,
    dark: '#1E1B40',
    glow: 'rgba(150,160,255,.45)',
    accent: '#AEB8FF',
    ink: '#5562D9'
  },
  {
    id: 'roi-cendres',
    character: 'roi-cendres',
    path: 'endgames',
    chapter: 'final',
    crystals: 600,
    dark: '#2A1A14',
    glow: 'rgba(255,120,40,.42)',
    accent: '#FF8A3D',
    ink: '#E0601A'
  },
  {
    id: 'others',
    character: null,
    path: null,
    chapter: '',
    crystals: null,
    dark: '#1B1530',
    glow: 'rgba(198,244,50,.3)',
    accent: '#C6F432',
    ink: '#5A3BE0'
  }
]

/**
 * A boss of the gallery, the index wrapping around (the gallery loops).
 *
 * @param {number} index
 */
export function tourBoss(index) {
  const n = TOUR_BOSSES.length
  return TOUR_BOSSES[((index % n) + n) % n]
}

/**
 * A boss's name, as the mind map says it ("Le Jongleur"), « Et d'autres… » for the shadow.
 *
 * @param {TourBoss} boss
 */
export function tourBossName(boss) {
  return boss.character
    ? t(`mindMapPath.characters.${boss.character}.name`)
    : t('onboarding.bosses.others.name')
}

/**
 * Where it waits: "VISUALISATION · CH. 1", "FINALES · BOSS FINAL", « ILS SE CACHENT PARTOUT ».
 *
 * @param {TourBoss} boss
 */
export function tourBossPlace(boss) {
  if (!boss.path) return t('onboarding.bosses.others.place')
  const path = t(`onboarding.bosses.paths.${boss.path}`)
  return boss.chapter === 'final'
    ? t('onboarding.bosses.final', { path })
    : t('onboarding.bosses.chapter', { path, n: boss.chapter })
}

/**
 * Its picture standing (the Roi des cendres: the lich), '' for the shadow.
 *
 * @param {TourBoss} boss
 */
export function tourBossImage(boss) {
  return boss.character ? standingImage(boss.character) : ''
}

/**
 * A step of the tour's sample path (the mind map's own shape, docs/MIND_MAP.md).
 *
 * @param {string} key
 * @param {string|null} number
 * @param {import('@/utils/mindMapPath').PathStep['kind']} kind
 * @param {import('@/utils/mindMapPath').PathStep['status']} status
 * @param {{passes?: number, required?: number, crystals?: number, xp?: number, character?: string|null, badges?: string[]}} [more]
 * @returns {import('@/utils/mindMapPath').PathStep}
 */
function sampleStep(key, number, kind, status, more = {}) {
  return {
    key,
    number,
    kind,
    status,
    passes: more.passes ?? (status === 'done' ? (more.required ?? 1) : 0),
    required: more.required ?? 1,
    crystals: more.crystals ?? 0,
    xp: more.xp ?? (kind === 'exercise' ? 15 : 0),
    character: more.character ?? null,
    badges: more.badges ?? [],
    skippable: false,
    chest:
      kind === 'chest' && status === 'done'
        ? { id: key, step: key, crystals: more.crystals ?? 0, opened: true }
        : null
  }
}

/**
 * @param {string} key
 * @param {string} number
 * @param {import('@/utils/mindMapPath').PathStep['status']} status
 * @param {import('@/utils/mindMapPath').PathStep[]} steps
 * @param {import('@/utils/mindMapPath').PathStep[]} miniBosses
 * @param {import('@/utils/mindMapPath').PathStep[]} [hidden]
 * @returns {import('@/utils/mindMapPath').PathSection}
 */
function sampleSection(key, number, status, steps, miniBosses, hidden = []) {
  return { key, number, status, steps, miniBosses, hidden }
}

/**
 * The map's example (design « Visite guidée », « Ta carte d'aventure », redrawn as the real
 * path): the end of the Visualisation path's chapter 1, its boss beaten, then chapter 2 under way,
 * with its real steps, passes, mini-bosses, hidden boss and rewards (docs/MIND_MAP.md, § 5).
 *
 * @type {import('@/utils/mindMapPath').PathChapter[]}
 */
export const SAMPLE_PATH = [
  {
    key: 'c1',
    number: 1,
    status: 'done',
    sections: [
      sampleSection(
        '1',
        null,
        'done',
        [
          sampleStep('1-7', '1.7', 'exercise', 'done', { required: 3 }),
          sampleStep('1-8', '1.8', 'exercise', 'done', { required: 2 }),
          sampleStep('1-9', '1.9', 'exercise', 'done', { required: 2 })
        ],
        []
      )
    ],
    boss: sampleStep('1-boss', null, 'boss', 'done', {
      crystals: 200,
      xp: 100,
      character: 'jongleur',
      badges: ['chapter_1', 'boss_jongleur']
    })
  },
  {
    key: 'c2',
    number: 2,
    status: 'open',
    sections: [
      sampleSection(
        '2-1',
        '2.1',
        'open',
        [
          sampleStep('2-1-1', '2.1.1', 'exercise', 'done', { required: 2 }),
          sampleStep('2-1-2', '2.1.2', 'exercise', 'open', {
            required: 3,
            passes: 2
          }),
          sampleStep('2-1-3', '2.1.3', 'exercise', 'open', { required: 3 }),
          sampleStep('2-1-4', '2.1.4', 'exercise', 'locked', { required: 4 })
        ],
        [
          sampleStep('2-1-boss', null, 'mini_boss', 'open', {
            crystals: 80,
            xp: 40,
            badges: ['section_2_1']
          })
        ]
      ),
      sampleSection(
        '2-2',
        '2.2',
        'locked',
        [
          sampleStep('2-2-1', '2.2.1', 'exercise', 'locked', { required: 2 }),
          sampleStep('2-2-2', '2.2.2', 'exercise', 'locked', { required: 3 })
        ],
        [
          sampleStep('2-2-boss', null, 'mini_boss', 'locked', {
            crystals: 100,
            xp: 40,
            badges: ['section_2_2']
          })
        ]
      ),
      sampleSection(
        '2-3',
        '2.3',
        'locked',
        [sampleStep('2-3-1', '2.3.1', 'exercise', 'locked', { required: 3 })],
        [
          sampleStep('2-3-boss', null, 'mini_boss', 'locked', {
            crystals: 120,
            xp: 40,
            badges: ['section_2_3']
          })
        ],
        [
          sampleStep('2-3-hidden', null, 'hidden_boss', 'hidden', {
            crystals: 60,
            xp: 40,
            badges: ['hidden_2_3']
          })
        ]
      )
    ],
    boss: sampleStep('2-boss', null, 'boss', 'locked', {
      crystals: 240,
      xp: 100,
      character: 'cyclope',
      badges: ['chapter_2', 'boss_cyclope']
    })
  }
]

/** The sample's step to play next: where the map opens, and the detail shown first. */
export const SAMPLE_PATH_CURRENT = '2-1-2'

/**
 * What a step of the sample asks and earns, for the line under the map.
 *
 * @param {import('@/utils/mindMapPath').PathStep} step
 * @returns {string[]}
 */
export function tourStepFacts(step) {
  if (step.status === 'hidden') return [t('onboarding.map.facts.hidden')]
  /** @type {string[]} */
  const facts = []
  if (step.kind === 'exercise')
    facts.push(
      t('onboarding.map.facts.passes', {
        done: step.passes,
        n: step.required
      })
    )
  if (step.kind === 'mini_boss' || step.kind === 'boss')
    facts.push(t('onboarding.map.facts.lives', { n: 3 }))
  if (step.crystals)
    facts.push(t('onboarding.map.facts.crystals', { n: step.crystals }))
  if (step.xp) facts.push(t('onboarding.map.facts.xp', { n: step.xp }))
  if (step.badges.length)
    facts.push(
      t(
        'onboarding.map.facts.badges',
        { n: step.badges.length },
        step.badges.length
      )
    )
  return facts
}

/** The level cards, weakest first. */
export const LEVELS = [
  {
    id: 'beginner',
    get name() {
      return t('onboarding.levels.beginner.name')
    },
    glyph: '♙︎',
    get hint() {
      return t('onboarding.levels.beginner.hint')
    },
    seed: 1000,
    get line() {
      return t('onboarding.levels.beginner.line')
    }
  },
  {
    id: 'amateur',
    get name() {
      return t('onboarding.levels.amateur.name')
    },
    glyph: '♘︎',
    get hint() {
      return t('onboarding.levels.amateur.hint')
    },
    seed: 1400,
    get line() {
      return t('onboarding.levels.amateur.line')
    }
  },
  {
    id: 'confirmed',
    get name() {
      return t('onboarding.levels.confirmed.name')
    },
    glyph: '♗︎',
    get hint() {
      return t('onboarding.levels.confirmed.hint')
    },
    seed: 1800,
    get line() {
      return t('onboarding.levels.confirmed.line')
    }
  },
  {
    id: 'expert',
    get name() {
      return t('onboarding.levels.expert.name')
    },
    glyph: '♕︎',
    get hint() {
      return t('onboarding.levels.expert.hint')
    },
    seed: 2200,
    get line() {
      return t('onboarding.levels.expert.line')
    }
  },
  {
    id: 'pro',
    get name() {
      return t('onboarding.levels.pro.name')
    },
    glyph: '♔︎',
    get hint() {
      return t('onboarding.levels.pro.hint')
    },
    seed: 2500,
    get line() {
      return t('onboarding.levels.pro.line')
    }
  }
]

/** The goals (one only), each with Albert's answer. */
export const GOALS = [
  {
    id: 'rating',
    glyph: '▲',
    get label() {
      return t('onboarding.goals.rating.label')
    },
    get line() {
      return t('onboarding.goals.rating.line')
    }
  },
  {
    id: 'blunders',
    glyph: '♛︎',
    get label() {
      return t('onboarding.goals.blunders.label')
    },
    get line() {
      return t('onboarding.goals.blunders.line')
    }
  },
  {
    id: 'fun',
    glyph: '♟︎',
    get label() {
      return t('onboarding.goals.fun.label')
    },
    get line() {
      return t('onboarding.goals.fun.line')
    }
  },
  {
    id: 'understanding',
    glyph: '◉',
    get label() {
      return t('onboarding.goals.understanding.label')
    },
    get line() {
      return t('onboarding.goals.understanding.line')
    }
  },
  {
    id: 'kasparov',
    glyph: '♚︎',
    get label() {
      return t('onboarding.goals.kasparov.label')
    },
    get line() {
      return t('onboarding.goals.kasparov.line')
    }
  }
]

/**
 * Where to start, at the end of the tour.
 *
 * @type {{id: string, glyph: string, title: string, desc: string, path: string, lichess: boolean}[]}
 */
export const END_CHOICES = [
  {
    id: 'map',
    glyph: '✸',
    get title() {
      return t('onboarding.endChoices.map.title')
    },
    get desc() {
      return t('onboarding.endChoices.map.desc')
    },
    path: '/cerveau',
    lichess: false
  },
  {
    id: 'coach',
    glyph: '✦',
    get title() {
      return t('onboarding.endChoices.coach.title')
    },
    get desc() {
      return t('onboarding.endChoices.coach.desc')
    },
    path: COACH_PATH,
    lichess: true
  },
  {
    id: 'repertoire',
    glyph: '♜︎',
    get title() {
      return t('onboarding.endChoices.repertoire.title')
    },
    get desc() {
      return t('onboarding.endChoices.repertoire.desc')
    },
    path: AUTO_REPERTOIRE_PATH,
    lichess: true
  },
  {
    id: 'builder',
    glyph: '♞︎',
    get title() {
      return t('onboarding.endChoices.builder.title')
    },
    get desc() {
      return t('onboarding.endChoices.builder.desc')
    },
    path: '/session/new',
    lichess: false
  }
]

/** Lichess' speeds, as said on the level screen. */
const PERFS = ['rapid', 'blitz', 'classical']

/** Numbers in the current language (docs/I18N.md). */
const number = { format: n => new Intl.NumberFormat(intlLocale()).format(n) }

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
      ? t('onboarding.chip.prof', {
          n: segment.steps.indexOf(step) + 1,
          total: segment.steps.length
        })
      : t('onboarding.chip.step', { n: i + 1, total: SEGMENTS.length })
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
 * The screen's look: a professor's colours, the boss shown's, or the tour's violet.
 *
 * @param {string} step
 * @param {number} [boss] the gallery's boss shown
 * @returns {TourTheme}
 */
export function themeOf(step, boss = 0) {
  if (step === 'bosses') {
    const b = tourBoss(boss)
    return {
      bg: b.dark,
      deep: b.ink,
      accent: b.ink,
      darkAccent: b.accent,
      line1: '#FFFFFF',
      line2: b.accent
    }
  }
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
  return /** @type {[string, string]} */ ([
    t(`onboarding.titles.${step}.0`),
    t(`onboarding.titles.${step}.1`)
  ])
}

/**
 * The line over the bubble.
 *
 * @param {string} step
 * @param {number} [boss] the gallery's boss shown
 */
export function kickerOf(step, boss = 0) {
  if (PROF_SCREENS[step]) return PROF_SCREENS[step].kicker
  if (step === 'bosses') {
    const b = tourBoss(boss)
    return `${tourBossName(b).toUpperCase()} · ${tourBossPlace(b)}`
  }
  return t(`onboarding.kickers.${step}`)
}

/**
 * What the professor says on a screen, given what was chosen so far.
 *
 * @param {string} step
 * @param {{level: string|null, goal: string|null, answers: OnboardingAnswers|null, linking?: boolean, boss?: number}} state
 */
export function lineOf(
  step,
  { level, goal, answers, linking = false, boss = 0 }
) {
  if (step === 'hello') return t('onboarding.lines.hello')
  if (step === 'bosses') {
    const b = tourBoss(boss)
    return b.character
      ? t(`mindMapPath.characters.${b.character}.taunt`)
      : t('onboarding.bosses.others.line')
  }
  if (step === 'map') return t('onboarding.lines.map')
  if (step === 'level') {
    if (linking) return t('onboarding.lines.linking')
    const chosen = levelOf(level)
    const lichess = answers?.lichess
    if (
      lichess?.rating &&
      chosen &&
      level === answers?.suggestedLevel &&
      lichess.perf
    )
      return t('onboarding.lines.lichess', {
        rating: number.format(lichess.rating),
        perf: PERFS.includes(lichess.perf)
          ? t(`onboarding.perfs.${lichess.perf}`)
          : lichess.perf,
        line: chosen.line
      })
    return chosen ? chosen.line : t('onboarding.lines.noLevel')
  }
  if (step === 'goal') return goalOf(goal)?.line ?? t('onboarding.lines.noGoal')
  if (PROF_SCREENS[step]) return PROF_SCREENS[step].line
  return answers?.lichess.linked
    ? t('onboarding.lines.endLinked')
    : t('onboarding.lines.endUnlinked')
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
  if (step === 'map') return 'albert-v2-pointer'
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
    return t('onboarding.rating.fixed', {
      rating: number.format(answers.puzzleRating ?? 1500)
    })
  const chosen = levelOf(level)
  return chosen
    ? t('onboarding.rating.seed', { rating: number.format(chosen.seed) })
    : ''
}

/**
 * The end screen's default choice: the tailored session with Lichess, the mind map without.
 *
 * @param {boolean} linked
 */
export function defaultEndChoice(linked) {
  return linked ? 'coach' : 'map'
}

/**
 * The main button: its label, and whether it can be pressed.
 *
 * @param {string} step
 * @param {{level: string|null, goal: string|null, end: string, linked: boolean, linking?: boolean}} state
 * @returns {{label: string, ok: boolean}}
 */
export function ctaOf(step, { level, goal, end, linked, linking = false }) {
  const cta = (key, ok = true) => ({ label: t(`onboarding.cta.${key}`), ok })
  if (step === 'hello') return cta('hello')
  if (step === 'level')
    return level && !linking ? cta('levelOk') : cta('level', false)
  if (step === 'goal') return goal ? cta('goalOk') : cta('goal', false)
  if (step === 'ray') return cta('ray')
  if (PROF_SCREENS[step]) return cta('nextProf')
  if (step === 'bosses') return cta('bosses')
  if (step === 'map') return cta('map')
  const choice = END_CHOICES.find(c => c.id === end)
  if (choice?.lichess && !linked) return cta('linkLichess')
  return cta('go')
}

/**
 * The small line under the main button.
 *
 * @param {string} step
 */
export function footOf(step) {
  if (step === 'hello') return t('onboarding.foot.hello')
  if (PROF_SCREENS[step]) return t('onboarding.foot.prof')
  if (step === 'bosses') return t('onboarding.foot.bosses')
  if (step === 'map') return t('onboarding.foot.map')
  if (step === 'end') return t('onboarding.foot.end')
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

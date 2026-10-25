import {
  MODULES,
  MODULES_BY_ID,
  OWN_PAGE_MODULES,
  formatMinutes
} from '@/utils/session/catalog'
import { CAPACITIES, capacityName } from '@/utils/mindMap'
import { hasPath, moodImage, standingImage } from '@/utils/mindMapPath'
import { challengeStepTitle } from '@/utils/challengePath'
import { TOPICS, topicLabel } from '@/utils/puzzleStreak'
import { PROFS } from '@/utils/prof/profs'
import { profImage } from '@/utils/prof/images'
import { t } from '@/i18n'

/**
 * The landing page's showcase content (design "Landing v3"): example values, not a user's data.
 * Module names and settings come from the session builder's catalogue, the path's steps from the
 * mind map's messages: the page never shows a module or a setting that does not exist.
 */

/** Woodpecker grid tones (design "Woodpecker"). */
export const WOODPECKER_TONES = {
  fast: '#8DBA0A',
  ok: '#C6F432',
  hint: '#FFD43B',
  fail: '#FF6FAE'
}

/**
 * A deterministic, varied-looking Woodpecker grid: the first `done` cells get a result tone, the
 * others the "to do" colour.
 *
 * @param {number} length number of cells
 * @param {number} done cells already solved
 * @param {string} todo colour of the cells still to do
 * @returns {string[]} one CSS colour per cell
 */
export function woodpeckerCells(length, done, todo) {
  return Array.from({ length }, (_, i) => {
    if (i >= done) return todo
    const r = (i * 37 + 11) % 13
    if (r < 5) return WOODPECKER_TONES.fast
    if (r < 10) return WOODPECKER_TONES.ok
    if (r < 12) return WOODPECKER_TONES.hint
    return WOODPECKER_TONES.fail
  })
}

/** Colour of a Woodpecker cell still to do, on the dark section. */
export const WOODPECKER_TODO = '#3A3160'

export const WOODPECKER_LEGEND = [
  ['fast', WOODPECKER_TONES.fast],
  ['ok', WOODPECKER_TONES.ok],
  ['hint', WOODPECKER_TONES.hint],
  ['fail', WOODPECKER_TONES.fail],
  ['todo', WOODPECKER_TODO]
].map(([key, color]) => ({
  color,
  get label() {
    return t(`landing.legend.${key}`)
  }
}))

/**
 * The six capacities of the mind map; those without a path yet are tagged "Bientôt".
 *
 * @type {{key: string, color: string, available: boolean, name: string}[]}
 */
export const LANDING_CAPACITIES = CAPACITIES.map(c => ({
  key: c.key,
  color: c.color,
  available: hasPath(c.key),
  get name() {
    return capacityName(c.key)
  }
}))

/** The calculation path's first chapter's chest (App\MindMap\Path\Calculation). */
const CHAPTER_CHEST_CRYSTALS = 60

/**
 * @typedef {object} TrackNode
 * @property {string} key
 * @property {'done'|'current'|'locked'|'chest'|'mini'|'boss'} state
 * @property {string} node what the round shows ('' for the boss's picture)
 * @property {string} kicker
 * @property {string} title
 * @property {string} image the boss's picture, '' for the others
 */

/** @param {string} key a step of the calculation path (mindMapPath.calculation.steps) */
const stepTitle = key => t(`mindMapPath.calculation.steps.${key}.title`)

/**
 * The boss at the end of the track: the Roi des cendres (the lich), the most striking of them,
 * instead of the chapter's own Métronome (a showcase choice).
 */
const TRACK_BOSS = 'roi-cendres'

/**
 * The calculation path's first chapter, step by step (the real steps but the boss; the player's
 * progress is made up: two exercises validated, the third to play).
 *
 * @type {TrackNode[]}
 */
export const CALCULATION_TRACK = [
  ['s1_1', 'done', '✓', () => '1.1'],
  ['s1_2', 'done', '✓', () => '1.2'],
  ['s1_chest', 'chest', '▣', () => t('landing.paths.chest')],
  ['s1_3', 'current', '3', () => t('landing.paths.current', { n: '1.3' })],
  ['s1_4', 'locked', '4', () => '1.4'],
  ['s1_mini', 'mini', '?', () => t('mindMapPath.kinds.mini_boss')],
  ['s1_boss', 'boss', '', () => t('mindMapPath.kinds.boss')]
].map(([key, state, node, kicker]) => ({
  key: String(key),
  state: /** @type {TrackNode['state']} */ (state),
  node: String(node),
  get kicker() {
    return /** @type {() => string} */ (kicker)()
  },
  get title() {
    if (key === 's1_chest')
      return t('landing.paths.crystals', { n: CHAPTER_CHEST_CRYSTALS })
    if (key === 's1_boss') return t('landing.paths.boss')
    return stepTitle(String(key))
  },
  image: key === 's1_boss' ? moodImage(TRACK_BOSS, 'neutral') : ''
}))

/** The chapter shown in the track. */
export const CALCULATION_CHAPTER = {
  get capacity() {
    return capacityName('calculation')
  },
  get title() {
    return t('mindMapPath.calculation.chapters.c1')
  }
}

/**
 * A setting's value as the session builder shows it ("3 min", "Fourchette, Clouage", "Noirs").
 *
 * @param {import('@/utils/session/catalog').Module} module
 * @param {string} key the setting's key in the module's fields
 * @param {any} value
 * @returns {string}
 */
export function settingLabel(module, key, value) {
  // The défis (docs/DEFIS.md): a chapter and one of its steps, not a session's settings.
  if (module.id === 'parcours') {
    return key === 'chapter'
      ? t(`challenge.chapters.${value}.name`)
      : challengeStepTitle(value)
  }
  const field = module.fields.find(f => f.key === key)
  if (!field) throw new Error(`No setting ${key} in ${module.id}`)
  switch (field.type) {
    case 'one':
      return /** @type {(v: any) => string} */ (field.optionLabel)(value)
    case 'many':
      return value
        .map(/** @type {(v: any) => string} */ (field.optionLabel))
        .join(', ')
    case 'slider':
      return /** @type {(v: number) => string} */ (field.format)(value)
    case 'durationChoice':
      return formatMinutes(value)
    case 'themes':
      return value.map((/** @type {string} */ k) => TOPICS[k] ?? k).join(', ')
    case 'streakTopic':
      return topicLabel(value)
    default:
      throw new Error(`Setting ${key} of ${module.id} has no label`)
  }
}

/**
 * A configured module: its catalogue entry and its settings, shown as "3 min · Fourchette".
 *
 * @param {string} id
 * @param {Record<string, any>} values
 */
function configured(id, values) {
  const module = MODULES_BY_ID[id]
  return {
    module,
    values,
    get meta() {
      return Object.entries(values)
        .map(([key, value]) => settingLabel(module, key, value))
        .join(' · ')
    }
  }
}

/**
 * An example session ("Ta séance"): catalogue modules with real settings, then the length of each.
 */
export const EXAMPLE_PROGRAM = [
  ['coordonnees', { mode: 'find', orientation: 'black' }, { duree: 2 }],
  ['puzzles', { themes: ['fork', 'pin'] }, { duree: 20 }],
  ['evaluation', { elo: 1700, couleur: 'white' }, { duree: 5 }],
  ['finales', { mode: 'review', themes: ['rooks'] }, { duree: 15 }]
].map(([id, values, { duree }]) => ({
  ...configured(String(id), values),
  minutes: duree,
  get duration() {
    return formatMinutes(duree)
  }
}))

/** The example session's length, in minutes. */
export const EXAMPLE_MINUTES = EXAMPLE_PROGRAM.reduce(
  (total, item) => total + item.minutes,
  0
)

/** The settings named next to the example session (the builder's labels). */
export const SESSION_SETTINGS = [
  'duration',
  'themes',
  'elo',
  'side',
  'level',
  'memorize',
  'orientation',
  'difficulty'
].map(key => ({
  key,
  get label() {
    return t(`session.catalog.fields.${key}`)
  }
}))

/**
 * The modules of the app: the session builder's, then those played on their own page. The free
 * study (a book, a video: time noted, nothing played in the app) is left out.
 */
export const LANDING_MODULES = [...MODULES, ...OWN_PAGE_MODULES].filter(
  m => m.id !== 'libre'
)

/**
 * The scrolling rows of the modules section: real modules with real settings, three rows. Modules
 * not built yet are left out.
 */
export const MODULE_VARIANTS = [
  configured('puzzles', { duree: 20, themes: ['fork'] }),
  configured('woodpecker', { duree: 15 }),
  configured('finales', { mode: 'learn', themes: ['basicMates'] }),
  configured('aveugle', { niveau: 'medium', longueur: 3 }),
  configured('evaluation', { duree: 2, couleur: 'black' }),
  configured('streak', { difficulte: 'ladder', thematique: 'theme:mateIn2' }),
  configured('coordonnees', { duree: 1, mode: 'name' }),
  configured('rush', { duree: 3 }),
  configured('reconstitution', { niveau: 7 }),
  configured('notation', { exercice: 'game_play' }),
  configured('puzzles', { themes: ['skewer', 'deflection'] }),
  configured('repertoire', { duree: 15 }),
  configured('parcours', { chapter: 'tactics', step: 'tactics-4' }),
  configured('finales', { mode: 'review', themes: ['pawns', 'rooks'] }),
  configured('aveugle', { longueur: 4, memorisation: 5 }),
  configured('streak', { difficulte: 'tiers', thematique: 'theme:fork' }),
  configured('coordonnees', { mode: 'color', orientation: 'black' }),
  configured('evaluation', { duree: 10, elo: 2000 }),
  configured('rush', { duree: 5, themes: ['mateIn1'] }),
  configured('notation', { exercice: 'write' }),
  configured('reconstitution', { niveau: 'mine' }),
  configured('parcours', { chapter: 'mates', step: 'mates-2' }),
  configured('finales', { themes: ['knights'], elo: 2600 }),
  configured('puzzles', { duree: 30, themes: ['discoveredAttack'] }),
  configured('aveugle', { niveau: 'hard', memorisation: 15 }),
  configured('streak', { thematique: 'theme:rookEndgame' }),
  configured('notation', { exercice: 'line' }),
  configured('parcours', { chapter: 'endgames', step: 'endgames-boss' })
]

/**
 * The variants split into rows of equal length.
 *
 * @param {number} rows
 */
export function variantRows(rows) {
  const n = Math.ceil(MODULE_VARIANTS.length / rows)
  return Array.from({ length: rows }, (_, r) =>
    MODULE_VARIANTS.slice(r * n, r * n + n)
  )
}

/**
 * The professors' cards, in the design's order.
 *
 * @type {import('@/utils/prof/profs').Prof[]}
 */
export const LANDING_PROFS = ['lizy', 'aaron', 'albert-stein'].map(
  slug => PROFS[slug]
)

/**
 * A professor's speciality, as shown under their name.
 *
 * @param {import('@/utils/prof/profs').Prof} prof
 * @returns {string} e.g. "FINALES"
 */
export function profRole(prof) {
  return t(`profs.${PROF_KEYS[prof.slug] ?? prof.slug}.role`)
}

/** The keys of the professors' texts (`profs.<key>.*`). */
const PROF_KEYS = { lizy: 'lizy', 'albert-stein': 'albert', aaron: 'aaron' }

/**
 * The bosses facing the professors: their picture standing, their card's colours.
 *
 * @type {{key: string, image: string, dark: string, glow: string, name: string, title: string}[]}
 */
export const LANDING_BOSSES = [
  ['jongleur', '#2B1630', 'rgba(230, 70, 60, 0.45)'],
  ['requin', '#12283A', 'rgba(79, 178, 255, 0.45)'],
  ['gargouille', '#2A2347', 'rgba(160, 140, 230, 0.5)']
].map(([key, dark, glow]) => ({
  key,
  image: standingImage(key),
  dark,
  glow,
  get name() {
    return t(`landing.versus.bosses.${key}.name`)
  },
  get title() {
    return t(`landing.versus.bosses.${key}.title`)
  }
}))

/** What linking Lichess brings, in the current language (welcome screen). */
export function lichessPerks() {
  return [0, 1, 2].map(i => t(`landing.perks.${i}`))
}

/** Illustrations; '' when the picture is not in src/assets/profs (the image is then hidden). */
export const LANDING_IMAGES = {
  albertFull: profImage('albert-full'),
  aaronWink: profImage('aaron-wink'),
  lizyJoy: profImage('lizy-joy'),
  albertJoy: profImage('albert-joy')
}

/** The hero's title: the slogans, shown in turn ({@link HERO_SLOGAN_INTERVAL_MS} each). */
export const HERO_SLOGANS = [
  "Don't Stay Rooky ! Stop taking checkmate and find a mate !",
  "Don't Stay Rooky ! Become the King !"
]

export const HERO_SLOGAN_INTERVAL_MS = 6000

/** The in-page sections reachable from the header. */
export const LANDING_ANCHORS = ['parcours', 'seances', 'modules', 'versus'].map(
  id => ({
    id,
    get label() {
      return t(`landing.anchors.${id}`)
    }
  })
)

/**
 * Scrolls to a section of the page. Anchors are buttons, not links: with the hash router,
 * `href="#modules"` would navigate to the route `/modules`.
 *
 * @param {string} id section element id
 */
export function scrollToSection(id) {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' })
}

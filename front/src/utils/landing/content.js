import { MODULES_BY_ID } from '@/utils/session/catalog'
import { PROFS } from '@/utils/prof/profs'
import { profImage } from '@/utils/prof/images'
import { t } from '@/i18n'

/**
 * The landing page's showcase content (design "Landing"): example values, not a user's data.
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
 * An example session ("Compose" step): catalogue modules with example settings.
 *
 * @type {{module: import('@/utils/session/catalog').Module, meta: string, duration: string}[]}
 */
export const EXAMPLE_PROGRAM = [
  ['repertoire', 15],
  ['puzzles', 20],
  ['finales', 20]
].map(([id, minutes]) => ({
  module: MODULES_BY_ID[id],
  get meta() {
    return t(`landing.example.${id}`)
  },
  get duration() {
    return t('landing.example.minutes', { n: minutes })
  }
}))

/**
 * The professors' cards, in the design's order.
 *
 * @type {import('@/utils/prof/profs').Prof[]}
 */
export const LANDING_PROFS = ['lizy', 'aaron', 'albert-stein'].map(
  slug => PROFS[slug]
)

/**
 * A professor's speciality, as shown above their name.
 *
 * @param {import('@/utils/prof/profs').Prof} prof
 * @returns {string} e.g. "FINALES"
 */
export function profRole(prof) {
  return t(`profs.${PROF_KEYS[prof.slug] ?? prof.slug}.role`)
}

/** The keys of the professors' texts (`profs.<key>.*`). */
const PROF_KEYS = { lizy: 'lizy', 'albert-stein': 'albert', aaron: 'aaron' }

/** What linking Lichess brings, in the current language. */
export function lichessPerks() {
  return [0, 1, 2].map(i => t(`landing.perks.${i}`))
}

/** Illustrations; '' when the picture is not in src/assets/profs (the image is then hidden). */
export const LANDING_IMAGES = {
  lizyFull: profImage('lizy-full'),
  albertFull: profImage('albert-full'),
  aaronFull: profImage('aaron-full'),
  albertIdea: profImage('albert-idea'),
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
export const LANDING_ANCHORS = ['modules', 'profs', 'suivi', 'lichess'].map(
  id => ({
    id,
    get label() {
      return t(`landing.anchors.${id}`)
    }
  })
)

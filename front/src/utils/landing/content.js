import { MODULES_BY_ID } from '@/utils/session/catalog'
import { PROFS } from '@/utils/prof/profs'
import { profImage } from '@/utils/prof/images'

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
  { color: WOODPECKER_TONES.fast, label: 'Rapide' },
  { color: WOODPECKER_TONES.ok, label: 'Réussi' },
  { color: WOODPECKER_TONES.hint, label: 'Avec aide' },
  { color: WOODPECKER_TONES.fail, label: 'Raté' },
  { color: WOODPECKER_TODO, label: 'À faire' }
]

/**
 * An example session ("Compose" step): catalogue modules with example settings.
 *
 * @type {{module: import('@/utils/session/catalog').Module, meta: string, duration: string}[]}
 */
export const EXAMPLE_PROGRAM = [
  {
    module: MODULES_BY_ID.repertoire,
    meta: 'Mes ouvertures Blancs',
    duration: '15 min'
  },
  {
    module: MODULES_BY_ID.puzzles,
    meta: 'Fourchette, Clouage',
    duration: '20 min'
  },
  {
    module: MODULES_BY_ID.finales,
    meta: 'Réviser · Tours',
    duration: '20 min'
  }
]

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
  return prof.kicker.replace(/^PROF · /, '')
}

export const LICHESS_PERKS = [
  'Import automatique de tes parties',
  'Répertoire et Elo synchronisés',
  'Puzzles tirés de tes propres erreurs'
]

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

/** The in-page sections reachable from the header. */
export const LANDING_ANCHORS = [
  { id: 'modules', label: 'Modules' },
  { id: 'profs', label: 'Les profs' },
  { id: 'suivi', label: 'Suivi' },
  { id: 'lichess', label: 'Lichess' }
]

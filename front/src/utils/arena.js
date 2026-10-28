/**
 * The arena's page (docs/ARENA.md, design « Défier les boss »): each boss's colours and playing
 * style, its levels, the state of its card. Pure, except the words read from the arena and
 * mindMapPath message files. The bosses, their strength and what the player can do with them come
 * from GET /arena.
 */
import { t } from '@/i18n'
import { BOSS_GLYPHS, standingImage, moodImage } from '@/utils/mindMapPath'
import { profImage } from '@/utils/prof/images'

/**
 * @typedef {{character: string, capacity: string|null, tier: string, level: number, price: number|null, elo: number, openingElo: number|null, endgameElo: number|null, styles: string[], guarded: boolean, status: 'locked'|'soon'|'shop'|'available', crystals: number, won: number, drawn: number, lost: number, firstWonAt: string|null, feat: {current: number, goal: number}|null}} ArenaBoss
 * @typedef {{bosses: ArenaBoss[], cadences: string[], defaultCadence: string, badges: {badge: string, earnedAt: string}[], balance: number, currentGame: string|null, pendingUnlocks: string[]}} ArenaOverview
 * @typedef {'beaten'|'open'|'shop'|'locked'|'soon'} CardState
 * @typedef {{dark: string, glow: string, accent: string, ink: string, soft: string}} Palette
 */

/**
 * The colours of each boss's card and sheet: its background, the halo behind it, the accent of its
 * title, the ink of its gauges, the soft tint of its chips.
 *
 * @type {Record<string, Palette>}
 */
const PALETTES = {
  zombie: palette(
    '#1D2A27',
    'rgba(140,200,120,.45)',
    '#B9E56C',
    '#4F8A2E',
    '#E7F2DD'
  ),
  jongleur: palette(
    '#2B1630',
    'rgba(230,70,60,.42)',
    '#F2B640',
    '#C0392B',
    '#FBE7E4'
  ),
  sablier: palette(
    '#2A2116',
    'rgba(230,190,110,.42)',
    '#F2CF7A',
    '#A3741C',
    '#FAF1DD'
  ),
  metronome: palette(
    '#231B3A',
    'rgba(245,180,60,.42)',
    '#F5C04A',
    '#B07A12',
    '#FBF1DC'
  ),
  bourreau: palette(
    '#1E1719',
    'rgba(220,50,50,.42)',
    '#F2B640',
    '#C0262D',
    '#F7E6E4'
  ),
  requin: palette(
    '#14243A',
    'rgba(60,160,230,.42)',
    '#5CC8FF',
    '#1E6FB8',
    '#E2F2FF'
  ),
  gardien: palette(
    '#1E2633',
    'rgba(150,180,220,.4)',
    '#B9D3F2',
    '#3C6A9E',
    '#E6EEF8'
  ),
  cyclope: palette(
    '#24202E',
    'rgba(120,190,230,.42)',
    '#8FD3FF',
    '#2D7BB8',
    '#E2F2FF'
  ),
  flash: palette(
    '#1C1A33',
    'rgba(250,230,90,.45)',
    '#FFE45C',
    '#A88A00',
    '#FBF6D6'
  ),
  'defi-mates': palette(
    '#1A1530',
    'rgba(150,90,240,.48)',
    '#E9B949',
    '#6A3FC9',
    '#EFE9FB'
  ),
  horloger: palette(
    '#26201A',
    'rgba(220,170,90,.42)',
    '#E8C27A',
    '#8C6420',
    '#F6EEDF'
  ),
  gargouille: palette(
    '#2A2347',
    'rgba(160,140,230,.48)',
    '#F5A524',
    '#6B5BD6',
    '#EEEBFA'
  ),
  'defi-endgames': palette(
    '#21202A',
    'rgba(180,170,150,.4)',
    '#D9CBA8',
    '#6E6248',
    '#F1EDE3'
  ),
  compteur: palette(
    '#16242A',
    'rgba(90,200,190,.42)',
    '#7FE0D2',
    '#1F8A7E',
    '#E0F5F2'
  ),
  'defi-tactics': palette(
    '#2A1A26',
    'rgba(240,110,170,.42)',
    '#FF9CC8',
    '#B83377',
    '#FBE6F0'
  ),
  'reine-noire': palette(
    '#1E1530',
    'rgba(220,60,200,.45)',
    '#E9B949',
    '#A0287F',
    '#F6E7F3'
  ),
  fantome: palette(
    '#1E1B40',
    'rgba(150,160,255,.48)',
    '#AEB8FF',
    '#5562D9',
    '#ECEEFC'
  ),
  'defi-opening': palette(
    '#1B2430',
    'rgba(110,200,140,.4)',
    '#9FE3B4',
    '#2E8A52',
    '#E3F5E9'
  ),
  'maitre-tours': palette(
    '#232029',
    'rgba(200,160,110,.42)',
    '#E3B98A',
    '#8A5A2B',
    '#F5ECE2'
  ),
  minotaure: palette(
    '#26182A',
    'rgba(230,160,60,.42)',
    '#F2B640',
    '#7A3FA0',
    '#F3EBF5'
  ),
  cameleon: palette(
    '#16271E',
    'rgba(110,220,120,.45)',
    '#9BF0A0',
    '#2F8F44',
    '#E2F7E4'
  ),
  duelliste: palette(
    '#1F1B2E',
    'rgba(200,200,230,.4)',
    '#D8D8F2',
    '#5A5A9A',
    '#ECECF8'
  ),
  avocat: palette(
    '#22131F',
    'rgba(230,50,60,.42)',
    '#F2B640',
    '#B3242F',
    '#FBE6E7'
  ),
  'cavalier-errant': palette(
    '#2A1E16',
    'rgba(220,130,70,.42)',
    '#F2A66A',
    '#A2521E',
    '#FAEADF'
  ),
  'roi-cendres': palette(
    '#1F1636',
    'rgba(245,190,60,.45)',
    '#F5B63A',
    '#7A4FC9',
    '#F1EAFB'
  ),
  sith: palette(
    '#1A0F14',
    'rgba(230,40,40,.5)',
    '#FF5A5A',
    '#B3141E',
    '#FBE3E4'
  ),
  // The professors' level.
  lizy: palette(
    '#2A1631',
    'rgba(255,120,190,.45)',
    '#FF9CD0',
    '#B8337A',
    '#FBE6F1'
  ),
  albert: palette(
    '#1A2033',
    'rgba(110,170,255,.45)',
    '#9CC6FF',
    '#2C64B8',
    '#E4EEFC'
  ),
  aaron: palette(
    '#2A1F14',
    'rgba(255,170,70,.45)',
    '#FFC27A',
    '#B36A14',
    '#FBEEDD'
  ),
  ray: palette(
    '#15121F',
    'rgba(198,244,50,.35)',
    '#C6F432',
    '#5C7A00',
    '#F0FBCF'
  )
}

/** The default palette, for a boss added to the roster before its colours. */
const DEFAULT_PALETTE = palette(
  '#211A33',
  'rgba(140,110,255,.42)',
  '#C6F432',
  '#6A4CFF',
  '#EEE9FF'
)

/**
 * The style of play of each boss on its sheet, out of 5: aggressiveness, patience, calculation,
 * speed (how fast it plays its moves, A2b).
 *
 * @type {Record<string, [number, number, number, number]>}
 */
const TRAITS = {
  zombie: [3, 1, 1, 2],
  jongleur: [4, 2, 2, 3],
  sablier: [2, 4, 2, 2],
  metronome: [3, 3, 2, 5],
  bourreau: [5, 2, 2, 4],
  requin: [4, 3, 2, 3],
  gardien: [1, 5, 2, 2],
  cyclope: [3, 2, 2, 3],
  flash: [4, 1, 2, 5],
  'defi-mates': [5, 2, 3, 4],
  horloger: [2, 5, 3, 3],
  gargouille: [1, 5, 3, 2],
  'defi-endgames': [2, 5, 3, 2],
  compteur: [2, 4, 4, 3],
  'defi-tactics': [5, 2, 4, 4],
  'reine-noire': [3, 4, 4, 4],
  fantome: [2, 4, 4, 3],
  'defi-opening': [3, 3, 3, 4],
  'maitre-tours': [2, 5, 4, 3],
  minotaure: [5, 2, 4, 3],
  cameleon: [3, 4, 4, 3],
  duelliste: [3, 4, 4, 3],
  avocat: [3, 4, 5, 3],
  'cavalier-errant': [4, 3, 5, 4],
  'roi-cendres': [2, 5, 5, 3],
  sith: [3, 5, 5, 4],
  lizy: [2, 5, 5, 3],
  albert: [5, 3, 5, 4],
  aaron: [3, 4, 4, 5],
  ray: [4, 4, 5, 5]
}

/** The gauges of the sheet, in order (words in arena.traits.<key>). */
export const TRAIT_KEYS = ['aggression', 'patience', 'calculation', 'speed']

/** The bosses without a picture: a piece stands for them (the paths' glyphs first). */
const GLYPHS = /** @type {Record<string, string>} */ ({
  ...BOSS_GLYPHS,
  sablier: '♟',
  flash: '♞',
  cameleon: '♝',
  sith: '♚',
  compteur: '♜',
  ray: '♞'
})

/** The paths where a locked boss is beaten (arena.locked.<capacity>), and their pages. */
export const PATH_PAGES = /** @type {Record<string, string>} */ ({
  visualisation: '/cerveau/visualization',
  calculation: '/cerveau/calculation',
  endgames: '/finales',
  challenges: '/defis'
})

/** The professors' level, the last (docs/ARENA.md, § 2 bis). */
export const MASTERS_LEVEL = 6

/** Ray: a mystery (a silhouette, no name) until he arrives. */
export const MYSTERY = 'ray'

/**
 * The professors' pictures (src/assets/profs): standing, and one for each mood of a boss (Ray has
 * none: a silhouette).
 *
 * @type {Record<string, {pose: string, moods: Partial<Record<import('@/utils/mindMapPath').Mood, string>> & {neutral: string}}>}
 */
const PROF_PICTURES = {
  lizy: {
    pose: 'lizy-full',
    moods: {
      joy: 'lizy-joy',
      surprise: 'lizy-wow',
      doubt: 'lizy-think',
      sadness: 'lizy-think',
      pride: 'lizy-cool',
      focus: 'lizy-think',
      anxiety: 'lizy-wow',
      neutral: 'lizy-bust'
    }
  },
  albert: {
    pose: 'albert-full',
    moods: {
      joy: 'albert-joy',
      surprise: 'albert-v2-wow',
      doubt: 'albert-think',
      sadness: 'albert-think',
      pride: 'albert-cool',
      anger: 'albert-v2-angry',
      focus: 'albert-think',
      anxiety: 'albert-v2-wow',
      neutral: 'albert-bust'
    }
  },
  aaron: {
    pose: 'aaron-full',
    moods: {
      joy: 'aaron-laugh',
      surprise: 'aaron-wow',
      doubt: 'aaron-cross',
      sadness: 'aaron-cross',
      pride: 'aaron-wink',
      anger: 'aaron-cross',
      focus: 'aaron-cross',
      anxiety: 'aaron-wow',
      neutral: 'aaron-bust'
    }
  }
}

/** Where each professor's feat is reached (arena.feat.<professor>). */
export const FEAT_PAGES = /** @type {Record<string, string>} */ ({
  lizy: '/finales',
  albert: '/streak',
  aaron: '/repertoire',
  ray: '/blindfold'
})

/**
 * @param {string} dark
 * @param {string} glow
 * @param {string} accent
 * @param {string} ink
 * @param {string} soft
 * @returns {Palette}
 */
function palette(dark, glow, accent, ink, soft) {
  return { dark, glow, accent, ink, soft }
}

/** @param {string} character */
export function paletteOf(character) {
  return PALETTES[character] ?? DEFAULT_PALETTE
}

/**
 * The gauges of a boss's sheet: `{key, label, value}`, value out of 5.
 *
 * @param {string} character
 */
export function traitsOf(character) {
  const values = TRAITS[character] ?? [3, 3, 3, 3]
  return TRAIT_KEYS.map((key, i) => ({
    key,
    label: t(`arena.traits.${key}`),
    value: values[i]
  }))
}

/** @param {string} character its speed out of 5 (3 by default) */
export function speedOf(character) {
  return (TRAITS[character] ?? [3, 3, 3, 3])[3]
}

/**
 * What a boss's card shows: beaten (once won), open, in the shop, locked or soon.
 *
 * @param {ArenaBoss} boss
 * @returns {CardState}
 */
export function cardState(boss) {
  if (boss.status === 'available') return boss.won > 0 ? 'beaten' : 'open'
  return boss.status
}

/** @param {ArenaBoss} boss the player can start a game against it */
export function canPlay(boss) {
  return boss.status === 'available'
}

/**
 * The bosses grouped by level, strongest last in each, then the professors' level.
 *
 * @param {ArenaBoss[]} bosses
 * @returns {{level: number, bosses: ArenaBoss[], beaten: number}[]}
 */
export function levelsOf(bosses) {
  /** @type {Map<number, ArenaBoss[]>} */
  const byLevel = new Map()
  for (const boss of bosses) {
    const list = byLevel.get(boss.level) ?? []
    list.push(boss)
    byLevel.set(boss.level, list)
  }
  return [...byLevel.entries()]
    .sort(([a], [b]) => a - b)
    .map(([level, list]) => ({
      level,
      bosses: [...list].sort(
        (a, b) => a.elo - b.elo || a.character.localeCompare(b.character)
      ),
      beaten: list.filter(boss => boss.won > 0).length
    }))
}

/** @param {ArenaBoss[]} bosses how many were beaten at least once */
export function beatenCount(bosses) {
  return bosses.filter(boss => boss.won > 0).length
}

/** @param {number} level its name (arena.levels.<n>) */
export function levelName(level) {
  return t(`arena.levels.${level}`)
}

/**
 * A boss's name and lines: `name`, `tagline`, `taunt`, `defeat`, `win` (mindMapPath.characters).
 *
 * @param {string} character
 * @param {'name'|'tagline'|'taunt'|'defeat'|'win'} line
 */
export function bossText(character, line) {
  return t(`mindMapPath.characters.${character}.${line}`)
}

/**
 * Its sheet's words: `title`, `lore`, `tipTitle`, `tip` (arena.bosses.<character>).
 *
 * @param {string} character
 * @param {'title'|'lore'|'tipTitle'|'tip'} key
 */
export function sheetText(character, key) {
  return t(`arena.bosses.${character}.${key}`)
}

/** @param {string[]} styles its preferences' chips (arena.styles.<key>) */
export function stylesOf(styles) {
  return styles.map(style => t(`arena.styles.${style}`))
}

/**
 * The picture of a boss's card: standing (a professor's own), else a piece.
 *
 * @param {string} character
 * @returns {{picture: string, glyph: string}}
 */
export function cardPicture(character) {
  const prof = PROF_PICTURES[character]
  const picture = prof ? profImage(prof.pose) : standingImage(character)
  return { picture, glyph: picture ? '' : (GLYPHS[character] ?? '♚') }
}

/**
 * A boss's face in a mood (its fight's pictures; a professor's poses), '' without pictures.
 *
 * @param {string} character
 * @param {import('@/utils/mindMapPath').Mood} mood
 */
export function bossMood(character, mood) {
  const prof = PROF_PICTURES[character]
  if (prof) return profImage(prof.moods[mood] ?? prof.moods.neutral)
  return moodImage(character, mood)
}

/** @param {string} character its face for the sheet's bubble, '' without pictures */
export function faceOf(character) {
  return bossMood(character, 'neutral')
}

/** @param {ArenaBoss} boss a professor, the last level */
export function isMaster(boss) {
  return boss.tier === 'master'
}

/** @param {ArenaBoss} boss Ray before his arrival: no name, no face */
export function isMystery(boss) {
  return boss.character === MYSTERY && boss.status !== 'available'
}

/**
 * Its name on its card and sheet (Ray's hidden until he arrives).
 *
 * @param {ArenaBoss} boss
 */
export function displayName(boss) {
  return isMystery(boss)
    ? t('arena.masters.mystery')
    : bossText(boss.character, 'name')
}

/**
 * A professor's feat on the way, 0 to 1; null for the other bosses.
 *
 * @param {ArenaBoss} boss
 */
export function featProgress(boss) {
  const feat = boss.feat
  if (!feat || feat.goal <= 0) return null
  return Math.min(1, feat.current / feat.goal)
}

/**
 * Why a locked boss cannot be challenged yet, where to beat it (a professor: where their feat is
 * reached), and the link's words.
 *
 * @param {ArenaBoss} boss
 * @returns {{text: string, to: string|null, go: string}}
 */
export function lockedHint(boss) {
  if (boss.status === 'soon')
    return { text: t('arena.sheet.soon'), to: null, go: '' }
  if (isMaster(boss))
    return {
      text: t(`arena.feat.${boss.character}`),
      to: FEAT_PAGES[boss.character] ?? null,
      go: t('arena.feat.go')
    }
  const capacity = boss.capacity ?? ''
  return {
    text: t(`arena.locked.${capacity in PATH_PAGES ? capacity : 'other'}`),
    to: PATH_PAGES[capacity] ?? null,
    go: t('arena.locked.go')
  }
}

/** The pages a boss never arrives over: a game of the arena, the guided tour, the welcome screen. */
const NO_ARRIVAL = ['/arene/partie', '/visite', '/bienvenue']

/**
 * Whether a boss may arrive over this page (docs/ARENA.md, § 5): never during a run, except on its
 * end screen once the path boss's victory and its chests are closed (`afterRun`); never during a
 * game of the arena, the guided tour or the welcome screen.
 *
 * @param {string} path the route's path
 * @param {boolean} [afterRun] a path boss's victory just closed on the run's end screen
 */
export function arrivalAllowed(path, afterRun = false) {
  if (NO_ARRIVAL.some(page => path === page || path.startsWith(`${page}/`)))
    return false
  if (path.startsWith('/training/')) return afterRun
  return true
}

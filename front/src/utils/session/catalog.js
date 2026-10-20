import { t } from '@/i18n'
import { PROFS } from '@/utils/prof/profs'
import { GENERAL, RAMPS, topicLabel } from '@/utils/puzzleStreak'

/**
 * The training-session builder's module catalogue (design "Session Builder"). Each module has a
 * professor and a list of settings; a session is an ordered list of configured modules.
 *
 * Available modules keep only the settings the API honours: a timed run's length (1 to 60 min, see
 * docs/TRAINING.md) and its subject. Modules not built yet keep the design's settings.
 *
 * Field types beyond the generic ones: `themes` (puzzle theme keys, picked in a second panel),
 * `repertoire` (ids of the user's repertoires, at least one) and `lightSet` (the user's Woodpecker light
 * set: shown, not chosen, as there is at most one; no value) and `durationChoice` (the length of a
 * module whose runs last one of a few lengths only, the coordinates series, the evaluation and the puzzle rush: picked among the API's
 * lengths in `context.durationChoices`, its default from there too) and `streakTopic` (a puzzle
 * streak's topic, docs/PUZZLE_STREAK.md: its key, picked among `context.streakRules` and
 * `context.streakOpenings`).
 *
 * @typedef {'slider'|'one'|'many'|'toggle'|'text'|'themes'|'repertoire'|'lightSet'|'durationChoice'|'streakTopic'} FieldType
 *
 * @typedef {object} Field
 * @property {string} key value key in the module's settings
 * @property {string} label
 * @property {FieldType} type
 * @property {any} default
 * @property {number} [min] slider
 * @property {number} [max] slider
 * @property {number} [step] slider
 * @property {(v: number) => string} [format] slider: the value as displayed
 * @property {Array<string|number>} [options] one / many: language-neutral values
 * @property {(v: any) => string} [optionLabel] one / many: an option as displayed
 * @property {string} [hint]
 * @property {string} [chip] toggle: the chip shown when on
 * @property {string} [placeholder] text
 *
 * @typedef {object} Module
 * @property {string} id
 * @property {string} title
 * @property {string} desc
 * @property {string} prof professor's display name
 * @property {string|null} profSlug professor with a card (/prof/<slug>), if any
 * @property {string} image professor's bust, '' when there is none
 * @property {string} glyph fallback avatar piece
 * @property {boolean} available whether the module exists in the app (others show "Bientôt")
 * @property {string} bg
 * @property {string} ink text colour on `bg`
 * @property {string} soft
 * @property {string} deep
 * @property {string} accentInk
 * @property {Field[]} fields
 * @property {(values: Record<string, any>) => number} [duration] minutes, when not the
 *   `duree` setting
 *
 * @typedef {object} SessionItem
 * @property {number} uid unique within the session
 * @property {string} moduleId
 * @property {Record<string, any>} values
 */

/** @param {string} piece */
const glyph = piece => `${piece}︎`

/**
 * A catalogue message (session.catalog.*), pluralized by `plural` when given.
 *
 * @param {string} key
 * @param {Record<string, unknown>} [params]
 * @param {number} [plural]
 * @returns {string}
 */
const m = (key, params = {}, plural) =>
  plural === undefined
    ? t(`session.catalog.${key}`, params)
    : t(`session.catalog.${key}`, params, plural)

/** A timed run lasts at most an hour (TimeboxRunner). */
export const MAX_RUN_MINUTES = 60

/**
 * A field whose label (and hint, placeholder, chip) are messages, read when shown.
 *
 * @param {Omit<Field, 'label'|'hint'|'placeholder'|'chip'>} spec
 * @param {{label: string, hint?: string, placeholder?: string, chip?: string}} keys message keys
 *   under session.catalog
 * @returns {Field}
 */
function field(spec, keys) {
  const texts = /** @type {Record<string, string>} */ (keys)
  return Object.defineProperties(
    spec,
    Object.fromEntries(
      Object.keys(texts).map(name => [
        name,
        { get: () => m(texts[name]), enumerable: true }
      ])
    )
  )
}

/** @param {number} def @param {number} [max] @returns {Field} */
const duration = (def, max = 90) =>
  field(
    {
      key: 'duree',
      type: 'slider',
      min: 5,
      max,
      step: 5,
      default: def,
      format: v => m('units.minutes', { n: v })
    },
    { label: 'fields.duration' }
  )

/** @param {number} def @param {string} [label] @returns {Field} */
const elo = (def, label = 'fields.elo') =>
  field(
    {
      key: 'elo',
      type: 'slider',
      min: 800,
      max: 2600,
      step: 50,
      default: def,
      format: v => m('units.elo', { n: v })
    },
    { label }
  )

/**
 * @param {string} label @param {number} min @param {number} max @param {number} def
 * @param {string} unit message key of the value, pluralized by it
 * @returns {Field}
 */
const count = (label, min, max, def, unit) =>
  field(
    {
      key: 'nombre',
      type: 'slider',
      min,
      max,
      step: 1,
      default: def,
      format: v => m(unit, { n: v }, v)
    },
    { label }
  )

/** The exercises of the notation module (docs/NOTATION.md). */
const NOTATION_KINDS = [
  'play',
  'sequence',
  'write',
  'line',
  'game_play',
  'game_write'
]

/** The modes of a coordinates series (docs/COORDINATES.md, § 2 bis). */
const COORDINATE_MODES = ['find', 'name', 'color']

/**
 * A single choice. Values are language-neutral (the API's when it has them); `labels` names the
 * group of session.catalog.options holding their labels, or is a function.
 *
 * @param {string} key @param {string} label @param {Array<string|number>} options
 * @param {string|number} def @param {string|((v: any) => string)} labels
 * @returns {Field}
 */
const one = (key, label, options, def, labels) =>
  field(
    {
      key,
      type: 'one',
      options,
      default: def,
      optionLabel: optionLabel(labels)
    },
    { label }
  )

/**
 * @param {string} key @param {string} label @param {string[]} options @param {string[]} def
 * @param {string} labels
 * @returns {Field}
 */
const many = (key, label, options, def, labels) =>
  field(
    {
      key,
      type: 'many',
      options,
      default: def,
      optionLabel: optionLabel(labels)
    },
    { label }
  )

/**
 * @param {string|((v: any) => string)} labels
 * @returns {(v: any) => string}
 */
function optionLabel(labels) {
  return typeof labels === 'function'
    ? labels
    : v => m(`options.${labels}.${v}`)
}

/** @param {boolean} def @returns {Field} */
const spaced = def =>
  field(
    { key: 'rep', type: 'toggle', default: def },
    {
      label: 'fields.spaced',
      hint: 'hints.spaced',
      chip: 'chips.spaced'
    }
  )

/** @param {string} [placeholder] message key @returns {Field} */
const notes = placeholder =>
  field(
    { key: 'notes', type: 'text', default: '' },
    placeholder
      ? { label: 'fields.notes', placeholder }
      : { label: 'fields.notes' }
  )

/** Colours, as the API names them. */
const SIDES = ['white', 'black', 'both']

/** @param {string} [def] @returns {Field} */
const color = (def = 'both') =>
  one('couleur', 'fields.color', SIDES, def, 'sides')

/**
 * @param {string} slug
 * @returns {Pick<Module, 'prof'|'profSlug'|'image'>}
 */
const byProf = slug => ({
  prof: PROFS[slug].name,
  profSlug: slug,
  image: PROFS[slug].bust
})

/** @param {string} name @returns {Pick<Module, 'prof'|'profSlug'|'image'>} */
const noCard = name => ({ prof: name, profSlug: null, image: '' })

/**
 * A module whose title and description are messages (session.catalog.modules.<id>).
 *
 * @param {Omit<Module, 'title'|'desc'>} spec
 * @returns {Module}
 */
function defineModule(spec) {
  return Object.defineProperties(spec, {
    title: { get: () => m(`modules.${spec.id}.title`), enumerable: true },
    desc: { get: () => m(`modules.${spec.id}.desc`), enumerable: true }
  })
}

/** Puzzle themes (keys of /puzzles/themes, combined with OR); none = every theme. */
export const MAX_THEMES = 10

/** @type {Field} */
const themes = field(
  { key: 'themes', type: 'themes', default: [] },
  { label: 'fields.themes', hint: 'hints.themes' }
)

/**
 * The settings of blindfold puzzles (App\Blindfold\Puzzle\PuzzleRules, docs/BLINDFOLD.md), as the
 * API takes them. The API refuses any other value (422): change them together.
 */
const BLINDFOLD_LEVELS = ['easy', 'medium', 'hard']
const BLINDFOLD_LENGTHS = [2, 3, 4]
const BLINDFOLD_VISIBLE = [5, 10, 15, 20, 30]

/**
 * The levels of position recall (App\Blindfold\Recall\RecallRules, docs/BLINDFOLD_RECALL.md):
 * "mine" (the highest one open when the step starts) or one of the twenty. A level not open yet
 * is refused by the API.
 */
const RECALL_LEVELS = ['mine', ...Array.from({ length: 20 }, (_, i) => i + 1)]

/** Free study formats, as the API names them. */
const FREE_FORMATS = ['book', 'video', 'course', 'podcast', 'other']

/** @type {Module[]} */
export const MODULES = [
  defineModule({
    id: 'libre',
    ...noCard('Biblio'),
    glyph: glyph('♛'),
    available: true,
    bg: '#8B6BFF',
    ink: '#FFFFFF',
    soft: '#EEE9FF',
    deep: '#5A3BE0',
    accentInk: '#5A3BE0',
    fields: [
      duration(30, MAX_RUN_MINUTES),
      one('type', 'fields.format', FREE_FORMATS, 'book', 'formats'),
      notes('placeholders.free')
    ]
  }),
  defineModule({
    id: 'finales',
    ...byProf('lizy'),
    glyph: glyph('♚'),
    available: false,
    bg: '#FF8A3D',
    ink: '#1B1530',
    soft: '#FFEBDD',
    deep: '#E0601A',
    accentInk: '#B84A0E',
    fields: [
      duration(20),
      one('mode', 'fields.mode', ['learn', 'review'], 'review', 'endgameModes'),
      many(
        'themes',
        'fields.themes',
        ['pawns', 'rooks', 'basicMates', 'minorPieces', 'queens'],
        ['rooks'],
        'endgames'
      ),
      elo(1500),
      spaced(true),
      notes('placeholders.endgames')
    ]
  }),
  defineModule({
    id: 'puzzles',
    ...byProf('albert-stein'),
    glyph: glyph('♞'),
    available: true,
    bg: '#FF6FAE',
    ink: '#1B1530',
    soft: '#FFE6F1',
    deep: '#E03C86',
    accentInk: '#C02670',
    fields: [duration(20, MAX_RUN_MINUTES), themes, notes()]
  }),
  defineModule({
    id: 'streak',
    ...byProf('albert-stein'),
    glyph: glyph('♛'),
    available: true,
    bg: '#FF5A36',
    ink: '#FFFFFF',
    soft: '#FFE7E1',
    deep: '#D93A16',
    accentInk: '#B8300F',
    fields: [
      duration(10, MAX_RUN_MINUTES),
      one(
        'difficulte',
        'fields.difficulty',
        Object.keys(RAMPS),
        'ladder',
        v => RAMPS[v] ?? v
      ),
      field(
        { key: 'thematique', type: 'streakTopic', default: GENERAL },
        { label: 'fields.topic', hint: 'hints.topic' }
      ),
      notes()
    ]
  }),
  defineModule({
    id: 'rush',
    ...byProf('albert-stein'),
    glyph: glyph('♗'),
    available: true,
    bg: '#FFB020',
    ink: '#1B1530',
    soft: '#FFF1D6',
    deep: '#E08A00',
    accentInk: '#8A5200',
    fields: [
      field(
        { key: 'duree', type: 'durationChoice', default: 0 },
        { label: 'fields.duration', hint: 'hints.rushDuration' }
      ),
      themes,
      notes()
    ]
  }),
  defineModule({
    id: 'woodpecker',
    ...byProf('albert-stein'),
    glyph: glyph('♝'),
    available: true,
    bg: '#C6F432',
    ink: '#1B1530',
    soft: '#F0FBCF',
    deep: '#8DBA0A',
    accentInk: '#5A7A00',
    fields: [
      duration(15, MAX_RUN_MINUTES),
      field(
        { key: 'set', type: 'lightSet', default: null },
        { label: 'fields.lightSet', hint: 'hints.lightSet' }
      ),
      notes()
    ]
  }),
  defineModule({
    id: 'repertoire',
    ...byProf('aaron'),
    glyph: glyph('♜'),
    available: true,
    bg: '#4FB2FF',
    ink: '#1B1530',
    soft: '#E2F2FF',
    deep: '#1C86E0',
    accentInk: '#0F6BBA',
    fields: [
      duration(15, MAX_RUN_MINUTES),
      field(
        { key: 'repertoires', type: 'repertoire', default: [] },
        { label: 'fields.repertoires', hint: 'hints.repertoires' }
      ),
      notes()
    ]
  }),
  defineModule({
    id: 'evaluation',
    ...byProf('aaron'),
    glyph: glyph('♟'),
    available: true,
    bg: '#FFD43B',
    ink: '#1B1530',
    soft: '#FFF5D1',
    deep: '#E0AE00',
    accentInk: '#8A6A00',
    fields: [
      field(
        { key: 'duree', type: 'durationChoice', default: 0 },
        { label: 'fields.duration', hint: 'hints.evaluationDuration' }
      ),
      elo(1500),
      one('couleur', 'fields.side', SIDES, 'both', 'sides'),
      notes()
    ]
  }),
  defineModule({
    id: 'analyse',
    ...noCard('Lupa'),
    glyph: glyph('♔'),
    available: false,
    bg: '#2ED3A8',
    ink: '#1B1530',
    soft: '#DAF8EF',
    deep: '#0FA582',
    accentInk: '#0B7D62',
    fields: [
      duration(30),
      count('fields.games', 1, 5, 2, 'units.games'),
      one(
        'filtre',
        'fields.selection',
        ['latest', 'losses', 'draws', 'chosen'],
        'losses',
        'gameFilters'
      ),
      color(),
      notes('placeholders.analysis')
    ]
  }),
  defineModule({
    id: 'coordonnees',
    ...noCard('Noctis'),
    glyph: glyph('♙'),
    available: true,
    bg: '#4A3D86',
    ink: '#FFFFFF',
    soft: '#E7E4F2',
    deep: '#2B2250',
    accentInk: '#4A3D86',
    fields: [
      field(
        { key: 'duree', type: 'durationChoice', default: 0 },
        { label: 'fields.duration', hint: 'hints.coordinatesDuration' }
      ),
      one('mode', 'fields.mode', COORDINATE_MODES, 'find', 'coordinateModes'),
      one(
        'orientation',
        'fields.orientation',
        ['white', 'black'],
        'white',
        'sides'
      ),
      notes()
    ]
  }),
  defineModule({
    id: 'notation',
    ...noCard('Noctis'),
    glyph: glyph('♗'),
    available: true,
    bg: '#5B4BA6',
    ink: '#FFFFFF',
    soft: '#ECE8F7',
    deep: '#3D2F73',
    accentInk: '#4A3D86',
    fields: [
      duration(10, MAX_RUN_MINUTES),
      one(
        'exercice',
        'fields.exercise',
        NOTATION_KINDS,
        'play',
        'notationKinds'
      ),
      notes()
    ]
  }),
  defineModule({
    id: 'aveugle',
    ...noCard('Noctis'),
    glyph: glyph('♘'),
    available: true,
    bg: '#2B2250',
    ink: '#FFFFFF',
    soft: '#E7E4F2',
    deep: '#5B4BA6',
    accentInk: '#4A3D86',
    fields: [
      duration(15, MAX_RUN_MINUTES),
      one('niveau', 'fields.level', BLINDFOLD_LEVELS, 'easy', 'levels'),
      one('longueur', 'fields.length', BLINDFOLD_LENGTHS, 2, v =>
        v >= 4
          ? m('options.lengthOrMore', { n: v })
          : m('options.length', { n: v })
      ),
      one('memorisation', 'fields.memorize', BLINDFOLD_VISIBLE, 10, v =>
        m('units.seconds', { n: v })
      ),
      notes()
    ]
  }),
  defineModule({
    id: 'reconstitution',
    ...noCard('Noctis'),
    glyph: glyph('♖'),
    available: true,
    bg: '#3D2F73',
    ink: '#FFFFFF',
    soft: '#ECE8F7',
    deep: '#6A58C2',
    accentInk: '#4A3D86',
    fields: [
      duration(10, MAX_RUN_MINUTES),
      one('niveau', 'fields.level', RECALL_LEVELS, 'mine', v =>
        v === 'mine'
          ? m('options.recallMine')
          : m('options.recallLevel', { n: v })
      ),
      notes()
    ]
  })
]

/**
 * The values drafts kept before the settings were language-neutral (2026-10-14): the French
 * labels, per module and setting, to today's values.
 *
 * @type {Record<string, Record<string, Record<string, any>>>}
 */
const LEGACY_VALUES = {
  libre: {
    type: {
      Livre: 'book',
      Vidéo: 'video',
      Cours: 'course',
      Podcast: 'podcast',
      Autre: 'other'
    }
  },
  finales: {
    mode: { Apprendre: 'learn', Réviser: 'review' },
    themes: {
      Pions: 'pawns',
      Tours: 'rooks',
      'Mats élémentaires': 'basicMates',
      'Pièces mineures': 'minorPieces',
      Dames: 'queens'
    }
  },
  streak: { difficulte: { Échelle: 'ladder', Paliers: 'tiers' } },
  evaluation: {
    couleur: { Blancs: 'white', Noirs: 'black', 'Les deux': 'both' }
  },
  analyse: {
    filtre: {
      Dernières: 'latest',
      Défaites: 'losses',
      Nulles: 'draws',
      'Au choix': 'chosen'
    },
    couleur: { Blancs: 'white', Noirs: 'black', 'Les deux': 'both' }
  },
  coordonnees: { orientation: { Blancs: 'white', Noirs: 'black' } },
  aveugle: {
    niveau: { Facile: 'easy', Moyen: 'medium', Difficile: 'hard' },
    longueur: { '2 coups': 2, '3 coups': 3, '4 coups et +': 4 },
    memorisation: { '5 s': 5, '10 s': 10, '15 s': 15, '20 s': 20, '30 s': 30 }
  }
}

/**
 * A stored item's settings with the French labels of older drafts turned into today's values;
 * any other choice that is not an option any more falls back to the default.
 *
 * @param {Module} module
 * @param {Record<string, any>} values
 * @returns {Record<string, any>}
 */
export function upgradeValues(module, values) {
  const legacy = LEGACY_VALUES[module.id] ?? {}
  const upgraded = { ...values }
  for (const f of module.fields) {
    if (f.type !== 'one' && f.type !== 'many') continue
    const map = legacy[f.key] ?? {}
    const options = /** @type {any[]} */ (f.options)
    const v = upgraded[f.key]
    if (f.type === 'one') {
      const value = v in map ? map[v] : v
      upgraded[f.key] = options.includes(value) ? value : f.default
    } else if (Array.isArray(v)) {
      upgraded[f.key] = v
        .map(x => (x in map ? map[x] : x))
        .filter(x => options.includes(x))
    }
  }
  return upgraded
}

/** @type {Record<string, Module>} */
/**
 * Modules played only through their own page, never added by hand to a session: the parcours
 * (docs/PARCOURS.md), whose tiers of puzzles are steps of the session it launches.
 *
 * @type {Module[]}
 */
export const OWN_PAGE_MODULES = [
  defineModule({
    id: 'parcours',
    ...byProf('lizy'),
    glyph: glyph('♚'),
    available: true,
    bg: '#FF8A3D',
    ink: '#1B1530',
    soft: '#FFEBDD',
    deep: '#E0601A',
    accentInk: '#B84A0E',
    fields: []
  })
]

export const MODULES_BY_ID = Object.fromEntries(
  [...MODULES, ...OWN_PAGE_MODULES].map(m => [m.id, m])
)

/**
 * "45 s", "1 min", "1 min 30 s".
 *
 * @param {number} seconds
 * @returns {string}
 */
export function formatSeconds(seconds) {
  if (seconds < 60) return `${seconds} s`
  const rest = seconds % 60
  return `${Math.floor(seconds / 60)} min${rest ? ` ${rest} s` : ''}`
}

/**
 * "45 min", "1 h", "1 h 05".
 *
 * @param {number} minutes
 * @returns {string}
 */
export function formatMinutes(minutes) {
  if (minutes < 60) return `${minutes} min`
  const rest = minutes % 60
  return `${Math.floor(minutes / 60)} h${rest ? ` ${String(rest).padStart(2, '0')}` : ''}`
}

/**
 * A module's default settings (arrays copied, so editing them never touches the catalogue).
 *
 * @param {Module} module
 * @returns {Record<string, any>}
 */
export function defaultValues(module) {
  return Object.fromEntries(
    module.fields.map(f => [
      f.key,
      Array.isArray(f.default) ? [...f.default] : f.default
    ])
  )
}

/**
 * How long a configured module lasts, in minutes.
 *
 * @param {Module} module
 * @param {Record<string, any>} values
 * @returns {number}
 */
export function moduleMinutes(module, values) {
  return module.duration ? module.duration(values) : values.duree
}

/**
 * Total length of a session, in minutes (items of unknown modules count for nothing).
 *
 * @param {SessionItem[]} items
 * @returns {number}
 */
export function sessionMinutes(items) {
  return items.reduce((total, item) => {
    const module = MODULES_BY_ID[item.moduleId]
    return module ? total + moduleMinutes(module, item.values) : total
  }, 0)
}

/**
 * What the chips and checks need to know about the user's data (see the session store).
 *
 * @typedef {object} SubjectContext
 * @property {boolean} loaded the user's repertoires and light set have been fetched
 * @property {{id: string, name: string, color: 'white'|'black'}[]} repertoires
 * @property {{id: string, name: string, status: string, puzzleCount: number, runCount: number}|null} lightSet the
 *   ongoing (active or paused) light set
 * @property {{id: string, name: string, status: string, puzzleCount: number, color: string}[]} openingSets
 *   the ongoing opening sets of the tailored session (docs/COACH.md): a Woodpecker step may play
 *   one of them (its `set` value: the set id; null: the light set)
 * @property {(key: string) => string} themeLabel
 * @property {Record<string, {minutes: number[], default: number}>} durationChoices the lengths a
 *   module's runs may last and the one offered first (catalogue id: the coordinates, the evaluation, the rush), from the API
 * @property {import('@/utils/puzzleStreak').StreakRules|null} [streakRules] the puzzle streak's rules
 * @property {import('@/utils/puzzleStreak').StreakOpenings|null} [streakOpenings] the openings a streak can draw from
 */

/** @type {SubjectContext} */
const NO_CONTEXT = {
  loaded: false,
  repertoires: [],
  lightSet: null,
  openingSets: [],
  themeLabel: key => key,
  durationChoices: {},
  streakRules: null,
  streakOpenings: null
}

/**
 * The settings of a module with lengths of its own, its length set to the API's default when it is
 * not one of them (unchanged when they are not known yet, or for another module).
 *
 * @param {Module} module
 * @param {Record<string, any>} values
 * @param {SubjectContext} [context]
 * @returns {Record<string, any>}
 */
export function withDurationChoice(module, values, context = NO_CONTEXT) {
  const choices = context.durationChoices?.[module.id]
  const field = module.fields.find(f => f.type === 'durationChoice')
  if (!field || !choices || choices.minutes.includes(values[field.key])) {
    return values
  }
  return { ...values, [field.key]: choices.default }
}

/**
 * At most two names, then "+n".
 *
 * @param {string[]} names
 * @returns {string[]}
 */
function firstTwo(names) {
  return names.length
    ? [
        names.slice(0, 2).join(', ') +
          (names.length > 2 ? ` +${names.length - 2}` : '')
      ]
    : []
}

/**
 * The settings summarised as chips on a program item: every setting except the duration (shown
 * apart) and the notes; at most two choices of a multiple choice, then "+n".
 *
 * @param {Module} module
 * @param {Record<string, any>} values
 * @param {SubjectContext} [context]
 * @returns {string[]}
 */
export function settingChips(module, values, context = NO_CONTEXT) {
  return module.fields.flatMap(f => {
    const v = values[f.key]
    if (f.key === 'duree') return []
    switch (f.type) {
      case 'slider':
        return [f.format ? f.format(v) : String(v)]
      case 'one':
        return [f.optionLabel ? f.optionLabel(v) : String(v)]
      case 'many':
        return firstTwo(f.optionLabel ? v.map(f.optionLabel) : v)
      case 'toggle':
        return v && f.chip ? [f.chip] : []
      case 'themes':
        return v?.length
          ? firstTwo(v.map(context.themeLabel))
          : [m('allThemes')]
      case 'repertoire':
        return firstTwo(
          (v ?? []).flatMap(
            (/** @type {string} */ id) =>
              context.repertoires.find(r => r.id === id)?.name ?? []
          )
        )
      case 'lightSet': {
        const set = v
          ? context.openingSets?.find(s => s.id === v)
          : context.lightSet
        return set
          ? [m('units.puzzles', { n: set.puzzleCount }, set.puzzleCount)]
          : []
      }
      case 'streakTopic':
        return [topicLabel(v ?? GENERAL, context.streakOpenings ?? null)]
      default:
        return []
    }
  })
}

/**
 * Why a configured module cannot be played as it is, or null. Subjects are only checked once
 * the user's data is loaded.
 *
 * @param {Module} module
 * @param {Record<string, any>} values
 * @param {SubjectContext} [context]
 * @returns {string|null}
 */
export function itemIssue(module, values, context = NO_CONTEXT) {
  for (const f of module.fields) {
    const v = values[f.key]
    if (f.type === 'themes' && (v?.length ?? 0) > MAX_THEMES) {
      return m('issues.tooManyThemes', { n: MAX_THEMES })
    }
    if (f.type === 'repertoire') {
      if (!v?.length) return m('issues.noRepertoire')
      if (
        context.loaded &&
        v.some(
          (/** @type {string} */ id) =>
            !context.repertoires.some(r => r.id === id)
        )
      ) {
        return m('issues.repertoireGone')
      }
    }
    if (f.type === 'durationChoice' && !(v > 0)) {
      return m('issues.loadingDuration')
    }
    if (f.type === 'lightSet' && context.loaded && v) {
      const set = context.openingSets?.find(s => s.id === v)
      if (!set) return m('issues.openingSetGone')
      if (set.status === 'paused') return m('issues.openingSetPaused')
      continue
    }
    if (f.type === 'lightSet' && context.loaded) {
      if (!context.lightSet) return m('issues.noLightSet')
      if (context.lightSet.status === 'paused')
        return m('issues.lightSetPaused')
    }
  }
  return null
}

/** The API's module of each playable catalogue module. */
export const API_MODULES = {
  libre: 'free',
  puzzles: 'puzzles',
  woodpecker: 'woodpecker',
  repertoire: 'repertoire',
  coordonnees: 'coordinates',
  aveugle: 'blindfold',
  evaluation: 'evaluation',
  streak: 'puzzle_streak',
  rush: 'puzzle_rush',
  reconstitution: 'blindfold_recall',
  notation: 'notation',
  parcours: 'parcours_puzzles'
}

/**
 * A program item as a step of the API's session (docs/TRAINING.md): module, length, notes and the
 * settings the module checks.
 *
 * @param {SessionItem} item
 * @returns {{module: string, minutes: number, notes: string, settings: Record<string, any>}}
 */
export function toStep(item) {
  const v = item.values
  const module = API_MODULES[item.moduleId]
  if (!module) throw new Error(`Module ${item.moduleId} cannot be played yet.`)
  /** @type {Record<string, any>} */
  let settings = {}
  if (module === 'free')
    settings = { format: FREE_FORMATS.includes(v.type) ? v.type : 'other' }
  if (module === 'puzzles' || module === 'puzzle_rush')
    settings = { themes: [...(v.themes ?? [])] }
  if (module === 'woodpecker' && v.set) settings = { setId: v.set }
  if (module === 'repertoire')
    settings = { repertoireIds: [...(v.repertoires ?? [])] }
  if (module === 'coordinates') {
    settings = { orientation: v.orientation === 'black' ? 'black' : 'white' }
    // The find mode is the API's default: older steps have no mode.
    const mode = oneOf(COORDINATE_MODES, v.mode, 'find')
    if (mode !== 'find') settings.mode = mode
  }
  if (module === 'blindfold')
    settings = {
      level: oneOf(BLINDFOLD_LEVELS, v.niveau, 'easy'),
      length: oneOf(BLINDFOLD_LENGTHS, v.longueur, 2),
      visibleSeconds: oneOf(BLINDFOLD_VISIBLE, v.memorisation, 10)
    }
  if (module === 'blindfold_recall')
    settings = { level: oneOf(RECALL_LEVELS, v.niveau, 'mine') }
  if (module === 'notation')
    settings = { kind: oneOf(NOTATION_KINDS, v.exercice, 'play') }
  if (module === 'evaluation')
    settings = {
      elo: v.elo,
      side: oneOf(SIDES, v.couleur, 'both')
    }
  if (module === 'puzzle_streak')
    settings = {
      ramp: oneOf(Object.keys(RAMPS), v.difficulte, 'ladder'),
      topic: v.thematique || GENERAL
    }
  return {
    module,
    minutes: moduleMinutes(MODULES_BY_ID[item.moduleId], v),
    notes: String(v.notes ?? '').trim(),
    settings
  }
}

/** The catalogue module of an API module (the reverse of API_MODULES). */
export const CATALOG_MODULES = Object.fromEntries(
  Object.entries(API_MODULES).map(([catalog, api]) => [api, catalog])
)

/**
 * A step of a saved session back as a program item's module and settings (the reverse of
 * `toStep`), or null for a module the catalogue cannot play.
 *
 * @param {{module: string, minutes: number, notes: string, settings: Record<string, any>}} step
 * @returns {{moduleId: string, values: Record<string, any>}|null}
 */
export function fromStep(step) {
  const moduleId = CATALOG_MODULES[step.module]
  const module = MODULES_BY_ID[moduleId]
  if (!module || OWN_PAGE_MODULES.includes(module)) return null
  const values = defaultValues(module)
  values.duree = step.minutes
  values.notes = step.notes ?? ''
  const settings = step.settings ?? {}
  if (step.module === 'free') {
    values.type = oneOf(FREE_FORMATS, settings.format, 'other')
  }
  if (step.module === 'puzzles' || step.module === 'puzzle_rush')
    values.themes = [...(settings.themes ?? [])]
  if (step.module === 'woodpecker') values.set = settings.setId ?? null
  if (step.module === 'repertoire')
    values.repertoires = [...(settings.repertoireIds ?? [])]
  if (step.module === 'coordinates') {
    values.orientation = settings.orientation === 'black' ? 'black' : 'white'
    values.mode = oneOf(COORDINATE_MODES, settings.mode, 'find')
  }
  if (step.module === 'blindfold') {
    values.niveau = oneOf(BLINDFOLD_LEVELS, settings.level, values.niveau)
    values.longueur = oneOf(BLINDFOLD_LENGTHS, settings.length, values.longueur)
    values.memorisation = oneOf(
      BLINDFOLD_VISIBLE,
      settings.visibleSeconds,
      values.memorisation
    )
  }
  if (step.module === 'blindfold_recall')
    values.niveau = oneOf(RECALL_LEVELS, settings.level, values.niveau)
  if (step.module === 'notation')
    values.exercice = oneOf(NOTATION_KINDS, settings.kind, 'play')
  if (step.module === 'evaluation') {
    if (Number.isInteger(settings.elo)) values.elo = settings.elo
    values.couleur = oneOf(SIDES, settings.side, values.couleur)
  }
  if (step.module === 'puzzle_streak') {
    values.difficulte = oneOf(
      Object.keys(RAMPS),
      settings.ramp,
      values.difficulte
    )
    if (typeof settings.topic === 'string') values.thematique = settings.topic
  }
  return { moduleId, values }
}

/**
 * `value` when it is one of `options`, `fallback` otherwise.
 *
 * @template T
 * @param {T[]} options
 * @param {any} value
 * @param {T} fallback
 * @returns {T}
 */
function oneOf(options, value, fallback) {
  return options.includes(value) ? value : fallback
}

/**
 * A copy of `list` with the element at `from` moved to `to`.
 *
 * @template T
 * @param {T[]} list
 * @param {number} from
 * @param {number} to
 * @returns {T[]}
 */
export function move(list, from, to) {
  const copy = [...list]
  if (from < 0 || from >= copy.length || to < 0 || to >= copy.length) {
    return copy
  }
  const [item] = copy.splice(from, 1)
  copy.splice(to, 0, item)
  return copy
}

import { PROFS } from '@/utils/prof/profs'

/**
 * The training-session builder's module catalogue (design "Session Builder"). Each module has a
 * professor and a list of settings; a session is an ordered list of configured modules.
 *
 * Available modules keep only the settings the API honours: a timed run's length (1 to 60 min, see
 * docs/TRAINING.md) and its subject. Modules not built yet keep the design's settings.
 *
 * Field types beyond the generic ones: `themes` (puzzle theme keys, picked in a second panel),
 * `repertoire` (ids of the user's repertoires, at least one) and `lightSet` (the user's Woodpecker light
 * set: shown, not chosen, as there is at most one; no value) and `fixedDuration` (the length of a
 * module whose runs all last the same time, the coordinates series: shown, not chosen, set from the
 * API's rules in `context.fixedMinutes`).
 *
 * @typedef {'slider'|'one'|'many'|'toggle'|'text'|'themes'|'repertoire'|'lightSet'|'fixedDuration'} FieldType
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
 * @property {string[]} [options] one / many
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

/** A timed run lasts at most an hour (TimeboxRunner). */
export const MAX_RUN_MINUTES = 60

/** @param {number} def @param {number} [max] @returns {Field} */
const duration = (def, max = 90) => ({
  key: 'duree',
  label: 'Durée',
  type: 'slider',
  min: 5,
  max,
  step: 5,
  default: def,
  format: v => `${v} min`
})

/** @param {number} def @param {string} [label] @returns {Field} */
const elo = (def, label = 'Difficulté (Elo)') => ({
  key: 'elo',
  label,
  type: 'slider',
  min: 800,
  max: 2600,
  step: 50,
  default: def,
  format: v => `Elo ${v}`
})

/**
 * @param {string} label @param {number} min @param {number} max @param {number} def
 * @param {string} unit
 * @returns {Field}
 */
const count = (label, min, max, def, unit) => ({
  key: 'nombre',
  label,
  type: 'slider',
  min,
  max,
  step: 1,
  default: def,
  format: v => `${v} ${unit}`
})

/**
 * @param {string} key @param {string} label @param {string[]} options @param {string} def
 * @returns {Field}
 */
const one = (key, label, options, def) => ({
  key,
  label,
  type: 'one',
  options,
  default: def
})

/**
 * @param {string} key @param {string} label @param {string[]} options @param {string[]} def
 * @returns {Field}
 */
const many = (key, label, options, def) => ({
  key,
  label,
  type: 'many',
  options,
  default: def
})

/** @param {boolean} def @returns {Field} */
const spaced = def => ({
  key: 'rep',
  label: 'Répétitions espacées',
  type: 'toggle',
  default: def,
  hint: 'Revoir les erreurs selon une courbe d’oubli.',
  chip: 'Rép. espacées'
})

/** @param {string} [placeholder] @returns {Field} */
const notes = (placeholder = '') => ({
  key: 'notes',
  label: 'Notes',
  type: 'text',
  default: '',
  placeholder
})

/** @param {string} [def] @returns {Field} */
const color = (def = 'Les deux') =>
  one('couleur', 'Couleur jouée', ['Blancs', 'Noirs', 'Les deux'], def)

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

/** Puzzle themes (keys of /puzzles/themes, combined with OR); none = every theme. */
export const MAX_THEMES = 10

/** @type {Field} */
const themes = {
  key: 'themes',
  label: 'Thèmes',
  type: 'themes',
  default: [],
  hint: 'Aucun thème : tous les thèmes. Sinon, un puzzle a au moins un des thèmes choisis.'
}

/**
 * The settings of blindfold puzzles (App\Blindfold\Puzzle\PuzzleRules, docs/BLINDFOLD.md),
 * labels to API values. The API refuses any other value (422): change them together.
 */
const BLINDFOLD_LEVELS = { Facile: 'easy', Moyen: 'medium', Difficile: 'hard' }
const BLINDFOLD_LENGTHS = { '2 coups': 2, '3 coups': 3, '4 coups et +': 4 }
const BLINDFOLD_VISIBLE = ['5 s', '10 s', '15 s', '20 s', '30 s']

/** @type {Module[]} */
export const MODULES = [
  {
    id: 'libre',
    title: 'Libre',
    desc: 'Lecture d’un livre, vidéos, cours… à ton rythme.',
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
      one(
        'type',
        'Format',
        ['Livre', 'Vidéo', 'Cours', 'Podcast', 'Autre'],
        'Livre'
      ),
      notes('Titre, chapitre, lien…')
    ]
  },
  {
    id: 'finales',
    title: 'Finales',
    desc: 'Apprendre ou réviser les finales essentielles.',
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
      one('mode', 'Mode', ['Apprendre', 'Réviser'], 'Réviser'),
      many(
        'themes',
        'Thèmes',
        ['Pions', 'Tours', 'Mats élémentaires', 'Pièces mineures', 'Dames'],
        ['Tours']
      ),
      elo(1500),
      spaced(true),
      notes('Ex. Lucena, Philidor…')
    ]
  },
  {
    id: 'puzzles',
    title: 'Puzzles',
    desc: 'Des puzzles à ton niveau, tant qu’il reste du temps.',
    ...byProf('albert-stein'),
    glyph: glyph('♞'),
    available: true,
    bg: '#FF6FAE',
    ink: '#1B1530',
    soft: '#FFE6F1',
    deep: '#E03C86',
    accentInk: '#C02670',
    fields: [duration(20, MAX_RUN_MINUTES), themes, notes()]
  },
  {
    id: 'woodpecker',
    title: 'Woodpecker',
    desc: 'Ton set light : des puzzles faciles, revus encore et encore.',
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
      {
        key: 'set',
        label: 'Set light',
        type: 'lightSet',
        default: null,
        hint: 'La séance repart du début du set ; il grandit tout seul quand tu l’as presque épuisé.'
      },
      notes()
    ]
  },
  {
    id: 'repertoire',
    title: 'Répertoire',
    desc: 'Réviser les ouvertures de ton répertoire.',
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
      {
        key: 'repertoires',
        label: 'Répertoires',
        type: 'repertoire',
        default: [],
        hint: 'Les révisions dues passent en premier (répétition espacée automatique).'
      },
      notes()
    ]
  },
  {
    id: 'evaluation',
    title: 'Évaluation de position',
    desc: 'Juger une position en 2 minutes maximum.',
    ...byProf('aaron'),
    glyph: glyph('♟'),
    available: false,
    bg: '#FFD43B',
    ink: '#1B1530',
    soft: '#FFF5D1',
    deep: '#E0AE00',
    accentInk: '#8A6A00',
    fields: [
      count('Positions', 3, 20, 6, 'positions'),
      {
        key: 'chrono',
        label: 'Temps par position',
        type: 'slider',
        min: 30,
        max: 120,
        step: 15,
        default: 120,
        format: formatSeconds,
        hint: '2 minutes maximum.'
      },
      elo(1600),
      color(),
      notes()
    ],
    duration: v => Math.ceil((v.nombre * v.chrono) / 60)
  },
  {
    id: 'analyse',
    title: 'Analyse de parties',
    desc: 'Revenir sur tes parties et en tirer des leçons.',
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
      count('Parties', 1, 5, 2, 'parties'),
      one(
        'filtre',
        'Sélection',
        ['Dernières', 'Défaites', 'Nulles', 'Au choix'],
        'Défaites'
      ),
      color(),
      notes('Points à creuser…')
    ]
  },
  {
    id: 'coordonnees',
    title: 'Coordonnées',
    desc: 'Trouver les cases sur un échiquier vide, sans coordonnées.',
    ...noCard('Noctis'),
    glyph: glyph('♙'),
    available: true,
    bg: '#4A3D86',
    ink: '#FFFFFF',
    soft: '#E7E4F2',
    deep: '#2B2250',
    accentInk: '#4A3D86',
    fields: [
      {
        key: 'duree',
        label: 'Durée',
        type: 'fixedDuration',
        default: 0,
        hint: 'Une série dure toujours le même temps.'
      },
      one('orientation', 'Orientation', ['Blancs', 'Noirs'], 'Blancs'),
      notes()
    ]
  },
  {
    id: 'aveugle',
    title: 'Jeu à l’aveugle',
    desc: 'Des puzzles résolus de mémoire : la position s’affiche, disparaît, puis tu joues sur un échiquier vide.',
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
      one('niveau', 'Niveau', Object.keys(BLINDFOLD_LEVELS), 'Facile'),
      one('longueur', 'Longueur', Object.keys(BLINDFOLD_LENGTHS), '2 coups'),
      one('memorisation', 'Temps pour mémoriser', BLINDFOLD_VISIBLE, '10 s'),
      notes()
    ]
  }
]

/** @type {Record<string, Module>} */
export const MODULES_BY_ID = Object.fromEntries(MODULES.map(m => [m.id, m]))

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
 * @property {(key: string) => string} themeLabel
 * @property {Record<string, number>} fixedMinutes the length of each fixed-length module (catalogue
 *   id), from the API
 */

/** @type {SubjectContext} */
const NO_CONTEXT = {
  loaded: false,
  repertoires: [],
  lightSet: null,
  themeLabel: key => key,
  fixedMinutes: {}
}

/**
 * The settings with the length of a fixed-length module set from the API (unchanged when it is
 * not known yet, or for another module).
 *
 * @param {Module} module
 * @param {Record<string, any>} values
 * @param {SubjectContext} [context]
 * @returns {Record<string, any>}
 */
export function withFixedDuration(module, values, context = NO_CONTEXT) {
  const minutes = context.fixedMinutes?.[module.id]
  const field = module.fields.find(f => f.type === 'fixedDuration')
  if (!field || !minutes || values[field.key] === minutes) return values
  return { ...values, [field.key]: minutes }
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
        return [v]
      case 'many':
        return firstTwo(v)
      case 'toggle':
        return v && f.chip ? [f.chip] : []
      case 'themes':
        return v?.length
          ? firstTwo(v.map(context.themeLabel))
          : ['Tous les thèmes']
      case 'repertoire':
        return firstTwo(
          (v ?? []).flatMap(
            (/** @type {string} */ id) =>
              context.repertoires.find(r => r.id === id)?.name ?? []
          )
        )
      case 'lightSet':
        return context.lightSet
          ? [`${context.lightSet.puzzleCount} puzzles`]
          : []
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
      return `${MAX_THEMES} thèmes au plus.`
    }
    if (f.type === 'repertoire') {
      if (!v?.length) return 'Choisis au moins un répertoire.'
      if (
        context.loaded &&
        v.some(
          (/** @type {string} */ id) =>
            !context.repertoires.some(r => r.id === id)
        )
      ) {
        return 'Un répertoire choisi n’existe plus.'
      }
    }
    if (f.type === 'fixedDuration' && !(v > 0)) {
      return 'Chargement de la durée…'
    }
    if (f.type === 'lightSet' && context.loaded) {
      if (!context.lightSet) return 'Tu n’as pas de set light en cours.'
      if (context.lightSet.status === 'paused')
        return 'Ton set light est en pause.'
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
  aveugle: 'blindfold'
}

/** Free study formats: the catalogue's labels, as the API names them. */
const FREE_FORMATS = {
  Livre: 'book',
  Vidéo: 'video',
  Cours: 'course',
  Podcast: 'podcast',
  Autre: 'other'
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
  if (module === 'free') settings = { format: FREE_FORMATS[v.type] ?? 'other' }
  if (module === 'puzzles') settings = { themes: [...(v.themes ?? [])] }
  if (module === 'repertoire')
    settings = { repertoireIds: [...(v.repertoires ?? [])] }
  if (module === 'coordinates')
    settings = { orientation: v.orientation === 'Noirs' ? 'black' : 'white' }
  if (module === 'blindfold')
    settings = {
      level: BLINDFOLD_LEVELS[v.niveau] ?? 'easy',
      length: BLINDFOLD_LENGTHS[v.longueur] ?? 2,
      visibleSeconds: parseInt(v.memorisation, 10) || 10
    }
  return {
    module,
    minutes: v.duree,
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
  if (!module) return null
  const values = defaultValues(module)
  values.duree = step.minutes
  values.notes = step.notes ?? ''
  const settings = step.settings ?? {}
  if (step.module === 'free') {
    values.type =
      Object.entries(FREE_FORMATS).find(
        ([, f]) => f === settings.format
      )?.[0] ?? 'Autre'
  }
  if (step.module === 'puzzles') values.themes = [...(settings.themes ?? [])]
  if (step.module === 'repertoire')
    values.repertoires = [...(settings.repertoireIds ?? [])]
  if (step.module === 'coordinates')
    values.orientation = settings.orientation === 'black' ? 'Noirs' : 'Blancs'
  if (step.module === 'blindfold') {
    values.niveau = labelOf(BLINDFOLD_LEVELS, settings.level) ?? values.niveau
    values.longueur =
      labelOf(BLINDFOLD_LENGTHS, settings.length) ?? values.longueur
    if (BLINDFOLD_VISIBLE.includes(`${settings.visibleSeconds} s`))
      values.memorisation = `${settings.visibleSeconds} s`
  }
  return { moduleId, values }
}

/**
 * The label of an API value in a labels-to-values map, or null.
 *
 * @param {Record<string, any>} map
 * @param {any} value
 * @returns {string|null}
 */
function labelOf(map, value) {
  return Object.entries(map).find(([, v]) => v === value)?.[0] ?? null
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

import { describe, expect, it } from 'vitest'
import {
  MODULES,
  MODULES_BY_ID,
  MAX_RUN_MINUTES,
  MAX_THEMES,
  defaultValues,
  formatMinutes,
  itemIssue,
  formatSeconds,
  moduleMinutes,
  move,
  sessionMinutes,
  settingChips,
  fromStep,
  toStep,
  upgradeValues,
  withDurationChoice
} from '@/utils/session/catalog'
import { PROFS, PROF_SLUGS } from '@/utils/prof/profs'

describe('session catalogue', () => {
  it('offers only the modules the app has', () => {
    expect(MODULES.filter(m => m.available).map(m => m.id)).toEqual([
      'libre',
      'finales',
      'puzzles',
      'streak',
      'rush',
      'woodpecker',
      'repertoire',
      'evaluation',
      'coordonnees',
      'notation',
      'aveugle',
      'reconstitution'
    ])
  })

  it('keeps only the settings a timed run honours', () => {
    const keys = id => MODULES_BY_ID[id].fields.map(f => f.key)
    expect(keys('puzzles')).toEqual(['duree', 'themes', 'notes'])
    expect(keys('woodpecker')).toEqual(['duree', 'set', 'notes'])
    expect(keys('repertoire')).toEqual(['duree', 'repertoires', 'notes'])
    expect(keys('libre')).toEqual(['duree', 'type', 'notes'])
    expect(keys('finales')).toEqual(['duree', 'mode', 'themes', 'elo', 'notes'])
    expect(keys('coordonnees')).toEqual([
      'duree',
      'mode',
      'orientation',
      'notes'
    ])
    expect(keys('streak')).toEqual([
      'duree',
      'difficulte',
      'thematique',
      'notes'
    ])
    expect(keys('aveugle')).toEqual([
      'duree',
      'niveau',
      'longueur',
      'memorisation',
      'notes'
    ])
    // The evaluation lasts one of the API's lengths, like a coordinates series.
    expect(keys('evaluation')).toEqual(['duree', 'elo', 'couleur', 'notes'])
    // The puzzle rush too (2, 3 or 5 minutes), with the puzzles' themes.
    expect(keys('rush')).toEqual(['duree', 'themes', 'notes'])
    // Position recall: the level ("mine" or one of the twenty).
    expect(keys('reconstitution')).toEqual(['duree', 'niveau', 'notes'])
    expect(MODULES_BY_ID.libre.fields[1].options).toContain('other')
    expect(MODULES_BY_ID.libre.fields[1].optionLabel('other')).toBe('Autre')
    for (const m of MODULES.filter(m => m.available)) {
      if (m.fields[0].key === 'duree' && m.fields[0].type === 'slider')
        expect(m.fields[0].max).toBe(MAX_RUN_MINUTES)
    }
  })

  it('links modules to existing professor cards', () => {
    for (const m of MODULES) {
      if (m.profSlug) expect(PROF_SLUGS).toContain(m.profSlug)
    }
    for (const prof of Object.values(PROFS)) {
      expect(MODULES_BY_ID[prof.cta.module]).toBeDefined()
    }
  })

  it('gives every field a default within its range or options', () => {
    for (const m of MODULES) {
      for (const f of m.fields) {
        if (f.type === 'slider') {
          expect(f.default).toBeGreaterThanOrEqual(f.min)
          expect(f.default).toBeLessThanOrEqual(f.max)
        }
        if (f.type === 'one') expect(f.options).toContain(f.default)
        if (f.type === 'many') {
          for (const v of f.default) expect(f.options).toContain(v)
        }
      }
    }
  })

  it('copies default arrays', () => {
    const puzzles = MODULES_BY_ID.puzzles
    const values = defaultValues(puzzles)
    values.themes.push('mate')

    expect(defaultValues(puzzles).themes).toEqual([])
  })

  it('computes durations', () => {
    expect(moduleMinutes(MODULES_BY_ID.evaluation, { duree: 5 })).toBe(5)
    expect(
      sessionMinutes([
        { uid: 1, moduleId: 'puzzles', values: { duree: 20 } },
        { uid: 2, moduleId: 'repertoire', values: { duree: 15 } },
        { uid: 3, moduleId: 'gone', values: { duree: 99 } }
      ])
    ).toBe(35)
  })

  it('formats minutes and seconds', () => {
    expect(formatMinutes(45)).toBe('45 min')
    expect(formatMinutes(60)).toBe('1 h')
    expect(formatMinutes(65)).toBe('1 h 05')
    expect(formatSeconds(45)).toBe('45 s')
    expect(formatSeconds(60)).toBe('1 min')
    expect(formatSeconds(90)).toBe('1 min 30 s')
  })

  it("summarises settings as chips, with the user's names", () => {
    const context = {
      loaded: true,
      repertoires: [
        { id: 'r1', name: 'Italienne', color: 'white' },
        { id: 'r2', name: 'Caro-Kann', color: 'black' },
        { id: 'r3', name: 'Londres', color: 'white' }
      ],
      lightSet: {
        id: 's',
        name: 'Express',
        status: 'active',
        puzzleCount: 120,
        runCount: 3
      },
      themeLabel: key => ({ fork: 'Fourchette', pin: 'Clouage' })[key] ?? key
    }
    const { puzzles, woodpecker, repertoire, libre, finales } = MODULES_BY_ID

    expect(settingChips(puzzles, defaultValues(puzzles), context)).toEqual([
      'Tous les thèmes'
    ])
    expect(
      settingChips(
        puzzles,
        { duree: 5, themes: ['fork', 'pin', 'mate', 'skewer'], notes: '' },
        context
      )
    ).toEqual(['Fourchette, Clouage +2'])
    expect(
      settingChips(woodpecker, defaultValues(woodpecker), context)
    ).toEqual(['120 puzzles'])
    expect(
      settingChips(
        repertoire,
        { duree: 5, repertoires: ['r1', 'gone', 'r2', 'r3'], notes: '' },
        context
      )
    ).toEqual(['Italienne, Caro-Kann +1'])
    expect(settingChips(libre, defaultValues(libre))).toEqual(['Livre'])
    expect(settingChips(finales, defaultValues(finales))).toEqual([
      'Réviser',
      'Tours',
      'Elo 1500'
    ])
    // The top of the slider is the tablebase's perfect defence.
    expect(
      settingChips(finales, { ...defaultValues(finales), elo: 2600 })
    ).toContain('Parfait')
  })

  it('splits the old minor pieces theme into bishops and knights', () => {
    const { finales } = MODULES_BY_ID
    expect(
      upgradeValues(finales, {
        ...defaultValues(finales),
        mode: 'Apprendre',
        themes: ['Pièces mineures', 'rooks', 'minorPieces']
      })
    ).toMatchObject({ mode: 'learn', themes: ['bishops', 'knights', 'rooks'] })
  })

  it('tells why a module cannot be played', () => {
    const { puzzles, woodpecker, repertoire, libre } = MODULES_BY_ID
    const context = {
      loaded: true,
      repertoires: [{ id: 'r1', name: 'Italienne', color: 'white' }],
      lightSet: null,
      themeLabel: key => key
    }
    const tooMany = Array.from({ length: MAX_THEMES + 1 }, (_, i) => `t${i}`)

    expect(itemIssue(libre, defaultValues(libre), context)).toBeNull()
    expect(itemIssue(puzzles, defaultValues(puzzles), context)).toBeNull()
    expect(itemIssue(puzzles, { themes: tooMany }, context)).toMatch('10')
    expect(itemIssue(repertoire, { repertoires: [] }, context)).toMatch(
      'au moins un'
    )
    expect(itemIssue(repertoire, { repertoires: ['r1'] }, context)).toBeNull()
    expect(itemIssue(repertoire, { repertoires: ['r9'] }, context)).toMatch(
      'n’existe plus'
    )
    expect(itemIssue(woodpecker, {}, context)).toMatch('pas de set light')
    expect(
      itemIssue(woodpecker, {}, { ...context, lightSet: { status: 'paused' } })
    ).toMatch('en pause')
    // An opening set of the tailored session: that set, not the light set.
    const openingSets = [
      {
        id: 's1',
        name: 'Italian Game · Blancs',
        status: 'active',
        puzzleCount: 100,
        color: 'white'
      },
      {
        id: 's2',
        name: 'Sicilian Defense · Noirs',
        status: 'paused',
        puzzleCount: 40,
        color: 'black'
      }
    ]
    expect(
      itemIssue(woodpecker, { set: 's1' }, { ...context, openingSets })
    ).toBeNull()
    expect(
      itemIssue(woodpecker, { set: 's2' }, { ...context, openingSets })
    ).toMatch('en pause')
    expect(
      itemIssue(woodpecker, { set: 's9' }, { ...context, openingSets })
    ).toMatch('plus en cours')
    // Subjects not loaded yet: only what the settings themselves say.
    expect(itemIssue(woodpecker, {})).toBeNull()
    expect(itemIssue(repertoire, { repertoires: ['r9'] })).toBeNull()
  })

  it('moves an element', () => {
    expect(move(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a'])
    expect(move(['a', 'b', 'c'], 2, 0)).toEqual(['c', 'a', 'b'])
    expect(move(['a', 'b'], 0, 5)).toEqual(['a', 'b'])
  })

  it("turns program items into the API's session steps", () => {
    expect(
      toStep({
        uid: 1,
        moduleId: 'libre',
        values: { duree: 30, type: 'video', notes: ' Cours de finales ' }
      })
    ).toEqual({
      module: 'free',
      minutes: 30,
      notes: 'Cours de finales',
      settings: { format: 'video' }
    })
    expect(
      toStep({
        uid: 2,
        moduleId: 'puzzles',
        values: { duree: 20, themes: ['fork'], notes: '' }
      })
    ).toEqual({
      module: 'puzzles',
      minutes: 20,
      notes: '',
      settings: { themes: ['fork'] }
    })
    expect(
      toStep({
        uid: 3,
        moduleId: 'repertoire',
        values: { duree: 15, repertoires: ['r1'], notes: '' }
      }).settings
    ).toEqual({ repertoireIds: ['r1'] })
    expect(
      toStep({
        uid: 4,
        moduleId: 'woodpecker',
        values: { duree: 15, set: null, notes: '' }
      })
    ).toEqual({ module: 'woodpecker', minutes: 15, notes: '', settings: {} })
    // An opening set (the tailored session) keeps its id, both ways.
    const opening = toStep({
      uid: 6,
      moduleId: 'woodpecker',
      values: { duree: 10, set: 's1', notes: '' }
    })
    expect(opening.settings).toEqual({ setId: 's1' })
    expect(fromStep(opening)?.values.set).toBe('s1')
    expect(() =>
      toStep({ uid: 5, moduleId: 'analyse', values: { duree: 5 } })
    ).toThrow()

    // Endgames (docs/ENDGAMES.md): mode, themes and Maia's Elo.
    const endgames = toStep({
      uid: 7,
      moduleId: 'finales',
      values: {
        duree: 5,
        mode: 'learn',
        themes: ['rooks', 'knights'],
        elo: 1800,
        notes: ''
      }
    })
    expect(endgames).toMatchObject({
      module: 'endgames',
      minutes: 5,
      settings: { mode: 'learn', themes: ['rooks', 'knights'], elo: 1800 }
    })
    expect(fromStep(endgames)?.values).toMatchObject({
      mode: 'learn',
      themes: ['rooks', 'knights'],
      elo: 1800
    })
  })

  it('gives a coordinates series one of the API lengths and its orientation', () => {
    const module = MODULES_BY_ID.coordonnees
    const values = defaultValues(module)
    const context = {
      loaded: true,
      repertoires: [],
      lightSet: null,
      themeLabel: key => key,
      durationChoices: { coordonnees: { minutes: [1, 2, 5, 10], default: 2 } }
    }
    expect(itemIssue(module, values)).toBe('Chargement de la durée…')
    expect(withDurationChoice(module, values)).toBe(values)

    const chosen = withDurationChoice(module, values, context)
    expect(chosen.duree).toBe(2)
    expect(itemIssue(module, chosen, context)).toBeNull()
    expect(moduleMinutes(module, chosen)).toBe(2)
    expect(withDurationChoice(module, chosen, context)).toBe(chosen)
    const ten = { ...chosen, duree: 10 }
    expect(withDurationChoice(module, ten, context)).toBe(ten)
    expect(
      withDurationChoice(module, { ...chosen, duree: 3 }, context).duree
    ).toBe(2)
    expect(
      withDurationChoice(MODULES_BY_ID.libre, { duree: 30 }, context)
    ).toEqual({ duree: 30 })

    const step = toStep({
      uid: 6,
      moduleId: 'coordonnees',
      values: { ...ten, orientation: 'black' }
    })
    expect(step).toEqual({
      module: 'coordinates',
      minutes: 10,
      notes: '',
      settings: { orientation: 'black' }
    })
    expect(fromStep(step)).toEqual({
      moduleId: 'coordonnees',
      values: { duree: 10, mode: 'find', orientation: 'black', notes: '' }
    })

    // Another mode travels with the step (the find mode is the API's default).
    const colour = toStep({
      uid: 8,
      moduleId: 'coordonnees',
      values: { ...ten, mode: 'color' }
    })
    expect(colour.settings).toEqual({ orientation: 'white', mode: 'color' })
    expect(fromStep(colour)?.values.mode).toBe('color')
  })

  it('plays a notation exercise, its kind as the API names it', () => {
    const step = toStep({
      uid: 9,
      moduleId: 'notation',
      values: { ...defaultValues(MODULES_BY_ID.notation), exercice: 'write' }
    })
    expect(step).toEqual({
      module: 'notation',
      minutes: 10,
      notes: '',
      settings: { kind: 'write' }
    })
    expect(fromStep(step)?.values.exercice).toBe('write')
  })

  it('plays blindfold puzzles without any prerequisite, their settings as API values', () => {
    const module = MODULES_BY_ID.aveugle
    const values = defaultValues(module)
    const context = {
      loaded: true,
      repertoires: [],
      lightSet: null,
      themeLabel: key => key,
      durationChoices: {}
    }
    expect(itemIssue(module, values, context)).toBeNull()
    expect(itemIssue(module, values)).toBeNull()

    const step = toStep({
      uid: 7,
      moduleId: 'aveugle',
      values: {
        ...values,
        duree: 20,
        niveau: 'hard',
        longueur: 4,
        memorisation: 30
      }
    })
    expect(step).toEqual({
      module: 'blindfold',
      minutes: 20,
      notes: '',
      settings: { level: 'hard', length: 4, visibleSeconds: 30 }
    })
    expect(fromStep(step)).toEqual({
      moduleId: 'aveugle',
      values: {
        duree: 20,
        niveau: 'hard',
        longueur: 4,
        memorisation: 30,
        notes: ''
      }
    })
    expect(toStep({ uid: 8, moduleId: 'aveugle', values }).settings).toEqual({
      level: 'easy',
      length: 2,
      visibleSeconds: 10
    })
  })

  it('plays timed puzzle streaks, their ramp and topic as API values', () => {
    const module = MODULES_BY_ID.streak
    const values = defaultValues(module)
    expect(values.thematique).toBe('general')
    expect(itemIssue(module, values)).toBeNull()
    expect(toStep({ uid: 1, moduleId: 'streak', values })).toEqual({
      module: 'puzzle_streak',
      minutes: 10,
      notes: '',
      settings: { ramp: 'ladder', topic: 'general' }
    })

    const step = toStep({
      uid: 2,
      moduleId: 'streak',
      values: {
        ...values,
        duree: 20,
        difficulte: 'tiers',
        thematique: 'opening:Italian_Game:w'
      }
    })
    expect(step.settings).toEqual({
      ramp: 'tiers',
      topic: 'opening:Italian_Game:w'
    })
    expect(fromStep(step)).toEqual({
      moduleId: 'streak',
      values: {
        duree: 20,
        difficulte: 'tiers',
        thematique: 'opening:Italian_Game:w',
        notes: ''
      }
    })
    const openings = {
      mine: [],
      all: [{ tag: 'Italian_Game', name: 'Italian Game', white: 40, black: 3 }]
    }
    expect(
      settingChips(module, fromStep(step).values, {
        loaded: true,
        repertoires: [],
        lightSet: null,
        openingSets: [],
        themeLabel: key => key,
        durationChoices: {},
        streakRules: null,
        streakOpenings: openings
      })
    ).toEqual(['Paliers', 'Italian Game (Blancs)'])
    expect(
      settingChips(module, { ...values, thematique: 'theme:rookEndgame' })
    ).toEqual(['Échelle', 'Finales de tours'])
  })

  it('gives an evaluation run one of the API lengths, its level and side, nothing per position', () => {
    const step = toStep({
      uid: 9,
      moduleId: 'evaluation',
      values: { duree: 5, elo: 1600, couleur: 'black', notes: '' }
    })
    expect(step).toEqual({
      module: 'evaluation',
      minutes: 5,
      notes: '',
      settings: { elo: 1600, side: 'black' }
    })
    expect(fromStep(step)).toEqual({
      moduleId: 'evaluation',
      values: { duree: 5, elo: 1600, couleur: 'black', notes: '' }
    })
  })

  it('plays position recall at the level reached, or at a level chosen', () => {
    expect(MODULES_BY_ID.reconstitution.fields[1].default).toBe('mine')
    const mine = toStep({
      uid: 11,
      moduleId: 'reconstitution',
      values: { duree: 10, niveau: 'mine', notes: '' }
    })
    expect(mine).toEqual({
      module: 'blindfold_recall',
      minutes: 10,
      notes: '',
      settings: { level: 'mine' }
    })
    const fixed = toStep({
      uid: 12,
      moduleId: 'reconstitution',
      values: { duree: 15, niveau: 7, notes: 'cavalier' }
    })
    expect(fixed.settings).toEqual({ level: 7 })
    expect(fromStep(fixed)).toEqual({
      moduleId: 'reconstitution',
      values: { duree: 15, niveau: 7, notes: 'cavalier' }
    })
    // An unknown level falls back to the player's.
    expect(
      toStep({
        uid: 13,
        moduleId: 'reconstitution',
        values: { duree: 10, niveau: 21, notes: '' }
      }).settings
    ).toEqual({ level: 'mine' })
  })

  it('gives a puzzle rush one of the API lengths and its themes', () => {
    const step = toStep({
      uid: 10,
      moduleId: 'rush',
      values: { duree: 3, themes: ['fork', 'pin'], notes: ' vite ' }
    })
    expect(step).toEqual({
      module: 'puzzle_rush',
      minutes: 3,
      notes: 'vite',
      settings: { themes: ['fork', 'pin'] }
    })
    expect(fromStep(step)).toEqual({
      moduleId: 'rush',
      values: { duree: 3, themes: ['fork', 'pin'], notes: 'vite' }
    })
    expect(MODULES_BY_ID.rush.profSlug).toBe('albert-stein')
  })
})

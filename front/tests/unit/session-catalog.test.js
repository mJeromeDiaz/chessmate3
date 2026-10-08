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
  withFixedDuration
} from '@/utils/session/catalog'
import { PROFS, PROF_SLUGS } from '@/utils/prof/profs'

describe('session catalogue', () => {
  it('offers only the modules the app has', () => {
    expect(MODULES.filter(m => m.available).map(m => m.id)).toEqual([
      'libre',
      'puzzles',
      'woodpecker',
      'repertoire',
      'evaluation',
      'coordonnees',
      'aveugle'
    ])
  })

  it('keeps only the settings a timed run honours', () => {
    const keys = id => MODULES_BY_ID[id].fields.map(f => f.key)
    expect(keys('puzzles')).toEqual(['duree', 'themes', 'notes'])
    expect(keys('woodpecker')).toEqual(['duree', 'set', 'notes'])
    expect(keys('repertoire')).toEqual(['duree', 'repertoires', 'notes'])
    expect(keys('libre')).toEqual(['duree', 'type', 'notes'])
    expect(keys('coordonnees')).toEqual(['duree', 'orientation', 'notes'])
    expect(keys('aveugle')).toEqual([
      'duree',
      'niveau',
      'longueur',
      'memorisation',
      'notes'
    ])
    // The evaluation's length follows from its positions: no duration field.
    expect(keys('evaluation')).toEqual([
      'nombre',
      'chrono',
      'elo',
      'couleur',
      'notes'
    ])
    expect(MODULES_BY_ID.libre.fields[1].options).toContain('Autre')
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

  it('computes durations, the evaluation one from its positions', () => {
    const evaluation = MODULES_BY_ID.evaluation
    // 6 positions × (120 s + 5 s of margin) = 12.5 min, rounded up.
    expect(moduleMinutes(evaluation, defaultValues(evaluation))).toBe(13)
    // 5 × (45 + 5) s = 4 min 10 s.
    expect(moduleMinutes(evaluation, { nombre: 5, chrono: 45 })).toBe(5)
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
    expect(
      settingChips(finales, { ...defaultValues(finales), rep: false })
    ).toEqual(['Réviser', 'Tours', 'Elo 1500'])
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
        values: { duree: 30, type: 'Vidéo', notes: ' Cours de finales ' }
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
    expect(() =>
      toStep({ uid: 5, moduleId: 'finales', values: { duree: 5 } })
    ).toThrow()
  })

  it('gives a coordinates series the length of the API and its orientation', () => {
    const module = MODULES_BY_ID.coordonnees
    const values = defaultValues(module)
    const context = {
      loaded: true,
      repertoires: [],
      lightSet: null,
      themeLabel: key => key,
      fixedMinutes: { coordonnees: 5 }
    }
    expect(itemIssue(module, values)).toBe('Chargement de la durée…')
    expect(withFixedDuration(module, values)).toBe(values)

    const fixed = withFixedDuration(module, values, context)
    expect(fixed.duree).toBe(5)
    expect(itemIssue(module, fixed, context)).toBeNull()
    expect(moduleMinutes(module, fixed)).toBe(5)
    expect(withFixedDuration(module, fixed, context)).toBe(fixed)
    expect(
      withFixedDuration(MODULES_BY_ID.libre, { duree: 30 }, context)
    ).toEqual({ duree: 30 })

    const step = toStep({
      uid: 6,
      moduleId: 'coordonnees',
      values: { ...fixed, orientation: 'Noirs' }
    })
    expect(step).toEqual({
      module: 'coordinates',
      minutes: 5,
      notes: '',
      settings: { orientation: 'black' }
    })
    expect(fromStep(step)).toEqual({
      moduleId: 'coordonnees',
      values: { duree: 5, orientation: 'Noirs', notes: '' }
    })
  })

  it('plays blindfold puzzles without any prerequisite, their settings as API values', () => {
    const module = MODULES_BY_ID.aveugle
    const values = defaultValues(module)
    const context = {
      loaded: true,
      repertoires: [],
      lightSet: null,
      themeLabel: key => key,
      fixedMinutes: {}
    }
    expect(itemIssue(module, values, context)).toBeNull()
    expect(itemIssue(module, values)).toBeNull()

    const step = toStep({
      uid: 7,
      moduleId: 'aveugle',
      values: {
        ...values,
        duree: 20,
        niveau: 'Difficile',
        longueur: '4 coups et +',
        memorisation: '30 s'
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
        niveau: 'Difficile',
        longueur: '4 coups et +',
        memorisation: '30 s',
        notes: ''
      }
    })
    expect(toStep({ uid: 8, moduleId: 'aveugle', values }).settings).toEqual({
      level: 'easy',
      length: 2,
      visibleSeconds: 10
    })
  })
})

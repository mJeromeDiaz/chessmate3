import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

const api = vi.hoisted(() => ({
  repertoireApi: { list: vi.fn() },
  woodpeckerApi: { sets: vi.fn() },
  planApi: { create: vi.fn(), update: vi.fn() },
  puzzleApi: { themes: vi.fn() }
}))

vi.mock('@/services/api', () => ({
  authApi: {},
  profileApi: {},
  ...api
}))

const { useSessionStore, DRAFT_KEY } = await import('@/stores/session')
const { useAuthStore } = await import('@/stores/auth')

const stored = () => JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null')

describe('session store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('adds available modules with their defaults', () => {
    const store = useSessionStore()

    const a = store.add('puzzles')
    const b = store.add('repertoire', {
      duree: 25,
      repertoires: ['r1'],
      notes: ''
    })

    expect(a?.values.duree).toBe(20)
    expect(b?.values).toEqual({ duree: 25, repertoires: ['r1'], notes: '' })
    expect(b?.uid).toBeGreaterThan(a?.uid ?? Infinity)
    expect(store.totalMinutes).toBe(45)
  })

  it('refuses unknown and not yet available modules', () => {
    const store = useSessionStore()

    expect(store.add('finales')).toBeNull()
    expect(store.add('nope')).toBeNull()
    expect(store.items).toEqual([])
  })

  it('copies the settings it is given', () => {
    const store = useSessionStore()
    const values = { duree: 10, themes: ['mate'] }

    const item = store.add('puzzles', values)
    values.themes.push('pin')

    expect(item?.values.themes).toEqual(['mate'])
  })

  it('updates, moves and removes items', () => {
    const store = useSessionStore()
    const a = store.add('puzzles')
    const b = store.add('woodpecker')
    const c = store.add('repertoire')

    store.update(b.uid, { ...b.values, duree: 45 })
    store.move(2, 0)
    store.remove(a.uid)

    expect(store.items.map(i => i.uid)).toEqual([c.uid, b.uid])
    expect(store.items[1].values.duree).toBe(45)
  })

  it('keeps the draft between visits', async () => {
    const store = useSessionStore()
    store.title = 'Mardi soir'
    store.add('puzzles')
    await nextTick()

    expect(stored().title).toBe('Mardi soir')

    setActivePinia(createPinia())
    const again = useSessionStore()
    expect(again.title).toBe('Mardi soir')
    expect(again.items).toHaveLength(1)
    // New uids never collide with restored ones.
    expect(again.add('woodpecker')?.uid).toBeGreaterThan(again.items[0].uid)
  })

  it('drops invalid stored items', () => {
    localStorage.setItem(
      DRAFT_KEY,
      JSON.stringify({
        title: 'T',
        items: [
          { uid: 1, moduleId: 'puzzles', values: { duree: 5 } },
          { uid: 2, moduleId: 'finales', values: { duree: 5 } },
          { uid: 'x', moduleId: 'puzzles', values: {} },
          null
        ]
      })
    )

    expect(useSessionStore().items.map(i => i.uid)).toEqual([1])
  })

  it('brings stored settings up to the current catalogue', () => {
    localStorage.setItem(
      DRAFT_KEY,
      JSON.stringify({
        items: [
          {
            uid: 1,
            moduleId: 'woodpecker',
            values: { duree: 30, cycle: 'Cycle 2', elo: 1600, notes: 'x' }
          },
          {
            uid: 2,
            moduleId: 'repertoire',
            values: { duree: 15, couleur: 'Blancs', repertoires: 'oops' }
          }
        ]
      })
    )

    expect(useSessionStore().items.map(i => i.values)).toEqual([
      { duree: 30, set: null, notes: 'x' },
      { duree: 15, repertoires: [], notes: '' }
    ])
  })

  it("loads the user's repertoires, ongoing light set and themes", async () => {
    api.repertoireApi.list.mockResolvedValue([
      { id: 'r1', name: 'Italienne', color: 'white', positionCount: 9 }
    ])
    api.woodpeckerApi.sets.mockResolvedValue([
      { id: 'c', mode: 'classic', status: 'active', archived: false },
      { id: 'old', mode: 'light', status: 'abandoned', archived: false },
      {
        id: 'l',
        name: 'Express',
        mode: 'light',
        status: 'paused',
        archived: false,
        puzzleCount: 40,
        runs: [{}, {}]
      }
    ])
    api.puzzleApi.themes.mockResolvedValue([
      { key: 'fork', labelFr: 'Fourchette' }
    ])
    const store = useSessionStore()
    expect(store.context.loaded).toBe(false)

    await store.fetchSubjects()

    expect(store.context.loaded).toBe(true)
    expect(store.context.repertoires).toEqual([
      { id: 'r1', name: 'Italienne', color: 'white' }
    ])
    expect(store.context.lightSet).toEqual({
      id: 'l',
      name: 'Express',
      status: 'paused',
      puzzleCount: 40,
      runCount: 2
    })
    expect(store.context.themeLabel('fork')).toBe('Fourchette')
  })

  it('reports a failed load and stays unloaded', async () => {
    api.repertoireApi.list.mockRejectedValue(new Error('boom'))
    api.woodpeckerApi.sets.mockResolvedValue([])
    api.puzzleApi.themes.mockResolvedValue([])
    const store = useSessionStore()

    await store.fetchSubjects()

    expect(store.context.loaded).toBe(false)
    expect(store.subjectsError).not.toBe('')
  })

  it('saves the session once every module and setting is valid', async () => {
    api.repertoireApi.list.mockResolvedValue([])
    api.woodpeckerApi.sets.mockResolvedValue([])
    api.puzzleApi.themes.mockResolvedValue([])
    const store = useSessionStore()
    store.title = ' Mardi '
    store.add('libre', { duree: 10, type: 'Livre', notes: '' })
    expect(store.canSave).toBe(false)

    await store.fetchSubjects()
    expect(store.canSave).toBe(true)
    store.add('woodpecker')
    expect(store.canSave).toBe(false)
    store.remove(store.items[1].uid)
    store.settings.repetition = 'daily'
    store.settings.weekdays = []
    expect(store.settingsError).toBe('Choisis au moins un jour.')
    expect(store.canSave).toBe(false)
    store.settings.weekdays = [2, 4]
    store.settings.reminderEnabled = true

    const saved = {
      id: 'p1',
      title: 'Mardi',
      description: '',
      steps: [
        { module: 'free', minutes: 10, notes: '', settings: { format: 'book' } }
      ],
      repetition: 'daily',
      time: '18:30',
      weekdays: [2, 4],
      public: false,
      reminderEnabled: true,
      reminderChannels: ['email'],
      reminderMinutes: 30,
      calendarEnabled: false
    }
    api.planApi.create.mockResolvedValue(saved)
    expect(await store.save()).toEqual(saved)
    expect(api.planApi.create).toHaveBeenCalledWith({
      title: 'Mardi',
      description: '',
      steps: [
        { module: 'free', minutes: 10, notes: '', settings: { format: 'book' } }
      ],
      repetition: 'daily',
      time: '18:30',
      weekdays: [2, 4],
      public: false,
      reminderEnabled: true,
      reminderChannels: ['email'],
      reminderMinutes: 30,
      calendarEnabled: false
    })
    // Saved: the draft is emptied, the session is now edited as a saved one.
    await nextTick()
    expect(stored()).toBeNull()
    expect(store.planId).toBe('p1')
    expect(store.items.map(i => i.moduleId)).toEqual(['libre'])

    api.planApi.update.mockResolvedValue(saved)
    store.title = 'Mardi soir'
    await store.save()
    expect(api.planApi.update).toHaveBeenCalledWith(
      'p1',
      expect.objectContaining({ title: 'Mardi soir' })
    )
  })

  it('edits a saved session without touching the draft of a new one', async () => {
    const store = useSessionStore()
    store.title = 'Brouillon'
    store.add('libre')
    await nextTick()

    store.edit({
      id: 'p2',
      title: 'Enregistrée',
      description: 'But',
      steps: [
        {
          module: 'puzzles',
          minutes: 15,
          notes: 'n',
          settings: { themes: ['fork'] }
        },
        { module: 'chess960', minutes: 5, notes: '', settings: {} }
      ],
      repetition: 'weekly',
      time: '07:00',
      weekdays: [2],
      public: true,
      reminderEnabled: false,
      reminderChannels: [],
      reminderMinutes: 60,
      calendarEnabled: true
    })
    await nextTick()

    expect(store.items).toHaveLength(1)
    expect(store.items[0]).toMatchObject({
      moduleId: 'puzzles',
      values: { duree: 15, themes: ['fork'], notes: 'n' }
    })
    expect(store.settings).toMatchObject({
      repetition: 'weekly',
      time: '07:00',
      weekdays: [2],
      public: true,
      calendarEnabled: true
    })
    expect(stored().title).toBe('Brouillon')

    store.startNew()
    expect(store.planId).toBeNull()
    expect(store.title).toBe('Brouillon')
    expect(store.items.map(i => i.moduleId)).toEqual(['libre'])
  })

  it('survives a corrupt draft', () => {
    localStorage.setItem(DRAFT_KEY, '{oops')

    expect(useSessionStore().items).toEqual([])
  })

  it('forgets the draft on sign-out', async () => {
    const auth = useAuthStore()
    auth.accessToken = 'token'
    const store = useSessionStore()
    store.add('puzzles')
    await nextTick()
    expect(stored()).not.toBeNull()

    auth.accessToken = null
    await nextTick()
    await nextTick()

    expect(store.items).toEqual([])
    expect(stored()).toBeNull()
  })
})

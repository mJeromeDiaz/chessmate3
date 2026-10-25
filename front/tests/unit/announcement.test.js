import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  announcementApi: {
    current: vi.fn(),
    markRead: vi.fn()
  }
}))

const { announcementApi } = await import('@/services/api')
const { useAnnouncementStore } = await import('@/stores/announcement')
const { announcementAllowed, unsubscribeParams } =
  await import('@/utils/announcement')
const {
  announcementForm,
  announcementFormValid,
  announcementPayload,
  canEmail,
  emailState,
  fieldlessViolations,
  hasText
} = await import('@/utils/admin/announcement')
const { toLocalInput } = await import('@/utils/admin/shop')

/**
 * @param {Partial<import('@/utils/admin/announcement').AdminAnnouncement>} [overrides]
 * @returns {import('@/utils/admin/announcement').AdminAnnouncement}
 */
function announcement(overrides = {}) {
  return {
    id: 'a1',
    titleFr: 'Nouveau module',
    bodyFr: '<p>Bonjour</p>',
    titleEn: null,
    bodyEn: null,
    status: 'published',
    state: 'live',
    startsAt: null,
    endsAt: null,
    publishedAt: '2026-10-25T08:00:00Z',
    createdAt: '2026-10-25T08:00:00Z',
    updatedAt: '2026-10-25T08:00:00Z',
    author: 'admin@example.com',
    reads: 0,
    email: { requestedAt: null, finishedAt: null, queued: 0, sent: 0 },
    ...overrides
  }
}

describe('announcement form (docs/ANNOUNCEMENTS.md, § 2)', () => {
  it('starts blank, without the English version', () => {
    expect(announcementForm(null)).toEqual({
      titleFr: '',
      bodyFr: '',
      english: false,
      titleEn: '',
      bodyEn: '',
      startsAt: '',
      endsAt: ''
    })
  })

  it('reads an announcement back, its dates in the browser’s time zone', () => {
    const form = announcementForm(
      announcement({
        titleEn: 'New module',
        bodyEn: '<p>Hello</p>',
        startsAt: '2026-11-01T09:00:00Z'
      })
    )
    expect(form.english).toBe(true)
    expect(form.titleEn).toBe('New module')
    expect(form.startsAt).toBe(toLocalInput('2026-11-01T09:00:00Z'))
    expect(form.endsAt).toBe('')
  })

  it('sends the English fields empty when the version is off', () => {
    const form = {
      ...announcementForm(null),
      titleFr: '  Nouveau  ',
      bodyFr: '<p>Texte</p>',
      titleEn: 'Left over',
      bodyEn: '<p>Left over</p>'
    }
    expect(announcementPayload(form, true)).toEqual({
      titleFr: 'Nouveau',
      bodyFr: '<p>Texte</p>',
      titleEn: null,
      bodyEn: null,
      startsAt: null,
      endsAt: null,
      publish: true
    })
    expect(
      announcementPayload({ ...form, english: true }, false)
    ).toMatchObject({
      titleEn: 'Left over',
      bodyEn: '<p>Left over</p>',
      publish: false
    })
  })

  it('needs French text, and both English fields or neither', () => {
    const form = {
      ...announcementForm(null),
      titleFr: 'Titre',
      bodyFr: '<p>Texte</p>'
    }
    expect(announcementFormValid(form)).toBe(true)
    expect(announcementFormValid({ ...form, bodyFr: '<br>' })).toBe(false)
    expect(announcementFormValid({ ...form, titleFr: '  ' })).toBe(false)
    expect(
      announcementFormValid({ ...form, english: true, titleEn: 'Title' })
    ).toBe(false)
    expect(
      announcementFormValid({
        ...form,
        english: true,
        titleEn: 'Title',
        bodyEn: '<p>Text</p>'
      })
    ).toBe(true)
  })

  it('sees no text in an empty editor', () => {
    expect(hasText('<br>')).toBe(false)
    expect(hasText('<p>&nbsp;</p>')).toBe(false)
    expect(hasText('<p><b>x</b></p>')).toBe(true)
  })

  it('keeps the refusals tied to no field for the banner', () => {
    expect(
      fieldlessViolations([
        { propertyPath: 'endsAt', title: 'La fin est déjà passée.' },
        {
          propertyPath: 'status',
          title: 'Une annonce archivée ne change plus.'
        }
      ])
    ).toBe('Une annonce archivée ne change plus.')
    expect(
      fieldlessViolations([{ propertyPath: 'titleFr', title: 'Requis.' }])
    ).toBe('')
  })
})

describe('announcement email (docs/ANNOUNCEMENTS.md, § 4)', () => {
  it('is asked once, for a published announcement not ended', () => {
    expect(canEmail(announcement())).toBe(true)
    expect(canEmail(announcement({ state: 'scheduled' }))).toBe(true)
    expect(canEmail(announcement({ state: 'draft', status: 'draft' }))).toBe(
      false
    )
    expect(canEmail(announcement({ state: 'ended' }))).toBe(false)
    expect(
      canEmail(
        announcement({
          email: {
            requestedAt: '2026-10-25T09:00:00Z',
            finishedAt: null,
            queued: 3,
            sent: 1
          }
        })
      )
    ).toBe(false)
  })

  it('waits for the start, then sends, then is done', () => {
    const asked = {
      requestedAt: '2026-10-25T09:00:00Z',
      finishedAt: null,
      queued: 0,
      sent: 0
    }
    expect(emailState(announcement())).toBe('none')
    expect(emailState(announcement({ state: 'scheduled', email: asked }))).toBe(
      'waiting'
    )
    expect(emailState(announcement({ email: asked }))).toBe('sending')
    expect(
      emailState(
        announcement({
          email: { ...asked, finishedAt: '2026-10-25T10:00:00Z' }
        })
      )
    ).toBe('done')
  })
})

describe('announcements on the player’s side (docs/ANNOUNCEMENTS.md, § 3)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(announcementApi.current).mockReset()
    vi.mocked(announcementApi.markRead).mockReset()
  })

  it('never opens during a run', () => {
    expect(announcementAllowed('/training/r1')).toBe(false)
    expect(announcementAllowed('/')).toBe(true)
    expect(announcementAllowed('/trainings')).toBe(true)
  })

  it('reads the email link’s account and token', () => {
    expect(unsubscribeParams({ u: 'id', t: 'tok' })).toEqual({
      u: 'id',
      t: 'tok'
    })
    expect(unsubscribeParams({ u: ['id'] })).toEqual({ u: '', t: '' })
  })

  it('keeps what it had when the server can’t be reached', async () => {
    const store = useAnnouncementStore()
    vi.mocked(announcementApi.current).mockResolvedValueOnce({
      announcements: [
        {
          id: 'a1',
          title: 'T',
          body: '<p>B</p>',
          startsAt: '2026-10-25T08:00:00Z'
        }
      ]
    })
    await store.load()
    vi.mocked(announcementApi.current).mockRejectedValueOnce(new Error('down'))
    await store.load()
    expect(store.items.map(a => a.id)).toEqual(['a1'])
  })

  it('forgets a closed one at once, even if the server refuses', async () => {
    const store = useAnnouncementStore()
    store.items = [
      { id: 'a1', title: 'T1', body: '', startsAt: '2026-10-25T08:00:00Z' },
      { id: 'a2', title: 'T2', body: '', startsAt: '2026-10-25T09:00:00Z' }
    ]
    vi.mocked(announcementApi.markRead).mockRejectedValueOnce(new Error('down'))
    await store.markRead('a1')
    expect(announcementApi.markRead).toHaveBeenCalledWith('a1')
    expect(store.items.map(a => a.id)).toEqual(['a2'])
  })
})

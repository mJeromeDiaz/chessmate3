import { describe, expect, it } from 'vitest'
import {
  avatarTile,
  cleanHandle,
  connectionRows,
  handleStatus,
  relativeDay,
  sessionLine,
  formatMonth,
  identityDetail,
  profileName
} from '@/utils/profile'

describe('profileName', () => {
  it('prefers the chosen display name', () => {
    expect(
      profileName({
        displayName: 'Léa',
        email: 'x@example.com',
        identities: []
      })
    ).toBe('Léa')
  })

  it('uses the part of the email before "@"', () => {
    expect(
      profileName({ email: 'lea.moreau@example.com', identities: [] })
    ).toBe('lea.moreau')
  })

  it('falls back on a linked identity, then on "Joueur"', () => {
    expect(
      profileName({
        email: null,
        identities: [{ username: null, name: 'Léa Moreau' }]
      })
    ).toBe('Léa Moreau')
    expect(profileName({ email: null, identities: [] })).toBe('Joueur')
  })
})

describe('formatMonth', () => {
  it('writes the month and year in French', () => {
    expect(formatMonth('2026-03-14T10:00:00+00:00')).toBe('mars 2026')
  })
})

describe('connectionRows', () => {
  it('lists Google then Lichess, linked or linkable', () => {
    const lichess = { id: 'l1', provider: 'lichess', removable: false }
    const rows = connectionRows({
      identities: [lichess],
      linkableProviders: ['google']
    })

    expect(rows).toEqual([
      { provider: 'google', identity: null, linkable: true },
      { provider: 'lichess', identity: lichess, linkable: false }
    ])
  })
})

describe('identityDetail', () => {
  it('shows a Lichess username with "@" and its ratings', () => {
    expect(
      identityDetail({
        provider: 'lichess',
        username: 'lea_echecs',
        ratings: {
          blitz: { rating: 1712 },
          rapid: { rating: 1650, provisional: true }
        }
      })
    ).toBe('@lea_echecs · blitz 1712, rapid 1650?')
  })

  it('shows a Google account by its name, else its email', () => {
    expect(
      identityDetail({
        provider: 'google',
        name: 'Léa',
        providerEmail: 'l@x.fr'
      })
    ).toBe('Léa')
    expect(
      identityDetail({ provider: 'google', providerEmail: 'l@x.fr' })
    ).toBe('l@x.fr')
  })
})

describe('avatarTile', () => {
  it('shows the chosen piece on its colour, else the initial on lime', () => {
    expect(avatarTile({ avatar: 'queen' }, 'léa')).toEqual({
      glyph: '♛\uFE0E',
      color: '#FF6FAE'
    })
    expect(avatarTile({ avatar: null }, 'léa')).toEqual({
      glyph: 'L',
      color: '#C6F432'
    })
  })
})

describe('cleanHandle', () => {
  it('keeps lower-case letters, digits and "_", 20 at most', () => {
    expect(cleanHandle('Léa Échecs-75!')).toBe('lachecs75')
    expect(cleanHandle('A'.repeat(25))).toBe('a'.repeat(20))
  })
})

describe('handleStatus', () => {
  const answer = (handle, available, reason = null) => ({
    handle,
    available,
    reason
  })

  it('accepts an empty handle (optional) and the current one', () => {
    expect(handleStatus('', 'lea', null).savable).toBe(true)
    expect(handleStatus('lea', 'lea', null)).toEqual({
      text: 'Ton pseudo actuel',
      tone: 'ok',
      savable: true
    })
  })

  it('refuses a short handle without asking the API', () => {
    expect(handleStatus('le', null, null)).toMatchObject({
      tone: 'error',
      savable: false
    })
  })

  it('waits for the answer about this very handle', () => {
    expect(handleStatus('lea', null, 'checking').savable).toBe(false)
    expect(handleStatus('lea', null, answer('le', true)).savable).toBe(false)
  })

  it('follows the API answer', () => {
    expect(handleStatus('lea', null, answer('lea', true))).toEqual({
      text: '✓ @lea est disponible',
      tone: 'ok',
      savable: true
    })
    expect(handleStatus('lea', null, answer('lea', false, 'taken'))).toEqual({
      text: 'Ce pseudo est déjà pris.',
      tone: 'error',
      savable: false
    })
    expect(
      handleStatus('admin', null, answer('admin', false, 'reserved')).text
    ).toBe('Ce pseudo est réservé.')
  })
})

describe('sessionLine', () => {
  const now = new Date(2026, 9, 5, 10, 30)
  const session = {
    browser: 'Chrome',
    os: 'macOS',
    form: 'desktop',
    ip: '198.51.100.0',
    lastActiveAt: new Date(2026, 9, 4, 21, 14).toISOString(),
    current: false
  }

  it('names the device and says when it was last used', () => {
    expect(sessionLine(session, now)).toEqual({
      device: 'Chrome · macOS',
      icon: '▢',
      meta: 'réseau 198.51.100.0 · hier, 21:14'
    })
  })

  it('reads "maintenant" for this device and copes with an unknown one', () => {
    expect(
      sessionLine(
        {
          ...session,
          browser: null,
          os: null,
          form: null,
          ip: null,
          current: true
        },
        now
      )
    ).toEqual({ device: 'Appareil inconnu', icon: '▢', meta: 'maintenant' })
    expect(sessionLine({ ...session, form: 'phone' }, now).icon).toBe('▯')
  })
})

describe('relativeDay', () => {
  const now = new Date(2026, 9, 5, 10, 30)

  it('says today, yesterday, then the date', () => {
    expect(relativeDay(new Date(2026, 9, 5, 9, 42), now)).toBe(
      'aujourd’hui, 09:42'
    )
    expect(relativeDay(new Date(2026, 9, 4, 21, 14), now)).toBe('hier, 21:14')
    expect(relativeDay(new Date(2026, 9, 2, 9, 42), now)).toBe('2 oct., 09:42')
    expect(relativeDay(new Date(2025, 11, 31, 8, 5), now)).toBe(
      '31 déc. 2025, 08:05'
    )
  })
})

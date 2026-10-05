import { describe, expect, it } from 'vitest'
import {
  endOfDay,
  invitationActions,
  playerName,
  signupLink
} from '@/utils/admin/invitations'

describe('signupLink', () => {
  it('points at the sign-up page of this SPA, without its current route', () => {
    expect(
      signupLink('Abc123', 'https://chessmate.test/app/#/admin/invitations')
    ).toBe('https://chessmate.test/app/#/register?key=Abc123')
  })
})

describe('invitationActions', () => {
  it('only lets an open invitation be resent or revoked', () => {
    expect(invitationActions({ status: 'pending' })).toEqual({
      resend: true,
      revoke: true
    })
    expect(invitationActions({ status: 'expired' })).toEqual({
      resend: true,
      revoke: true
    })
    expect(invitationActions({ status: 'used' })).toEqual({
      resend: false,
      revoke: false
    })
    expect(invitationActions({ status: 'revoked' })).toEqual({
      resend: false,
      revoke: false
    })
  })
})

describe('endOfDay', () => {
  it('is the last second of the chosen day, in the local timezone', () => {
    const instant = new Date(endOfDay('2026-10-12'))

    expect([
      instant.getFullYear(),
      instant.getMonth(),
      instant.getDate(),
      instant.getHours(),
      instant.getMinutes()
    ]).toEqual([2026, 9, 12, 23, 59])
    expect(new Date(endOfDay('2026/10/12')).getTime()).toBe(instant.getTime())
  })
})

describe('playerName', () => {
  const base = { displayName: null, handle: null, email: null, lichess: null }

  it('prefers the display name, then the handle, then the email', () => {
    expect(
      playerName({
        ...base,
        displayName: 'Alice',
        handle: 'ali',
        email: 'a@x.fr'
      })
    ).toBe('Alice')
    expect(playerName({ ...base, handle: 'ali', email: 'a@x.fr' })).toBe('@ali')
    expect(playerName({ ...base, email: 'a@x.fr' })).toBe('a@x.fr')
  })

  it('names a Lichess sign-up without email by its Lichess account', () => {
    expect(playerName({ ...base, lichess: { username: 'Magnus' } })).toBe(
      'Magnus (Lichess)'
    )
    expect(playerName(base)).toBe('Compte sans email')
  })
})

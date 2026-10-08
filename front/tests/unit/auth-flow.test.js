import { afterEach, describe, expect, it } from 'vitest'
import {
  INVITATION_REASONS,
  KEY_ERRORS,
  PASSWORD_MIN_LENGTH,
  dropLetters,
  isEmail,
  keyFormatError,
  markLinkFromWelcome,
  markWelcome,
  normalizeKey,
  passwordStrength,
  providerById,
  takeLinkFromWelcome,
  takeWelcome
} from '@/utils/auth/authFlow'

describe('auth flow', () => {
  afterEach(() => sessionStorage.clear())

  it('checks an address lightly', () => {
    expect(isEmail('lea@exemple.fr')).toBe(true)
    expect(isEmail(' lea@exemple.fr ')).toBe(true)
    expect(isEmail('lea@exemple')).toBe(false)
    expect(isEmail('lea exemple.fr')).toBe(false)
    expect(isEmail('')).toBe(false)
  })

  it('cleans a pasted key and tells a malformed one', () => {
    const key = 'aZ3k'.repeat(8)
    expect(normalizeKey(` ${key.slice(0, 16)}\n${key.slice(16)} `)).toBe(key)
    expect(keyFormatError(key)).toBe('')
    expect(keyFormatError('')).toBe(KEY_ERRORS.invitation_required)
    expect(keyFormatError('trop-court')).toMatch(/32 lettres et chiffres/)
    expect(keyFormatError(`${key.slice(1)}-`)).toMatch(/32/)
    expect(INVITATION_REASONS).toEqual([
      'invitation_required',
      'invitation_invalid',
      'invitation_expired'
    ])
  })

  it('never rates a password below the minimum length above "Faible"', () => {
    expect(passwordStrength('')).toEqual({ score: 0, label: '' })
    expect(passwordStrength('Ab1!Ab1!')).toEqual({ score: 1, label: 'Faible' })
    expect(passwordStrength('a'.repeat(PASSWORD_MIN_LENGTH))).toEqual({
      score: 1,
      label: 'Faible'
    })
    expect(passwordStrength('abcdefgh1234').score).toBe(2)
    expect(passwordStrength('abcdefgh123A').score).toBe(3)
    expect(passwordStrength('cheval blanc 4 tours !').label).toBe('Solide')
  })

  it('drops the letters of a title one after the other', () => {
    expect(dropLetters('Tu', 3)).toEqual([
      { ch: 'T', delay: '0.12s', tilt: '14deg' },
      { ch: 'u', delay: '0.16s', tilt: '-14deg' }
    ])
  })

  it('knows the providers, Lichess first', () => {
    expect(providerById('lichess')?.label).toBe('Lichess')
    expect(providerById('github')).toBeNull()
  })

  it('remembers a sign-up and a link for one read only', () => {
    expect(takeWelcome()).toBe(false)
    markWelcome()
    expect(takeWelcome()).toBe(true)
    expect(takeWelcome()).toBe(false)

    markLinkFromWelcome()
    expect(takeLinkFromWelcome()).toBe(true)
    expect(takeLinkFromWelcome()).toBe(false)
  })
})

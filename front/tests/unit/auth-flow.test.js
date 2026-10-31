import { afterEach, describe, expect, it } from 'vitest'
import {
  AUTO_REPERTOIRE_PATH,
  COACH_PATH,
  INVITATION_REASONS,
  KEY_ERRORS,
  TOUR_PATH,
  dropLetters,
  isAcceptablePassword,
  isEmail,
  keyFormatError,
  markAfterSignIn,
  markLinkFromWelcome,
  markLinkReturn,
  markWelcome,
  normalizeKey,
  passwordEntropy,
  passwordStrength,
  providerById,
  takeAfterSignIn,
  takeLinkFromWelcome,
  takeLinkReturn,
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

  it("rates a password on the API's own scale", () => {
    expect(passwordStrength('')).toEqual({ score: 0, label: '' })
    // Symfony's estimateStrength() for the same passwords: 1 (weak), 2 (medium), 3, 4.
    expect(passwordStrength('Ab1!Cd2@Ef')).toEqual({
      score: 1,
      label: 'Trop faible'
    })
    expect(passwordStrength('Ab1!Cd2@Ef3#').score).toBe(1)
    expect(passwordStrength('abcdefghijklmnop').score).toBe(1)
    expect(passwordStrength('Ab1!Cd2@Ef3#Gh')).toEqual({
      score: 2,
      label: 'Moyen'
    })
    expect(passwordStrength('Tr0ub4dour&3xyzQW').score).toBe(3)
    expect(passwordStrength('cheval blanc 4 tours !').label).toBe('Solide')
    // Accented letters are counted as UTF-8 bytes, as PHP does.
    expect(passwordStrength('échiquier-Roi-9!').score).toBe(4)
  })

  it('accepts a password only when long and strong enough', () => {
    expect(isAcceptablePassword('Ab1!Cd2@Ef3#')).toBe(false)
    expect(isAcceptablePassword('Ab1!Cd2@Ef3#Gh')).toBe(true)
    // Strong but too short: the API's Length(min: 12) refuses it.
    expect(isAcceptablePassword('é'.repeat(3) + 'Ab1!Cd2@')).toBe(false)
    // One distinct byte from a pool of 26, then three repeats worth log2(1) = 0.
    expect(passwordEntropy('aaaa')).toBe(Math.log2(26))
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

  it('comes back after a link to allowed pages only', () => {
    markLinkReturn(AUTO_REPERTOIRE_PATH)
    expect(takeLinkReturn()).toBe('/repertoire/auto')
    expect(takeLinkReturn()).toBeNull()

    markLinkReturn('https://evil.example')
    expect(takeLinkReturn()).toBeNull()
    sessionStorage.setItem('dontstayrooky.linkReturn', '/admin')
    expect(takeLinkReturn()).toBeNull()
  })

  it('leads a provider sign-in to allowed pages only', () => {
    markAfterSignIn(AUTO_REPERTOIRE_PATH)
    expect(takeAfterSignIn()).toBe('/repertoire/auto')
    expect(takeAfterSignIn()).toBeNull()
    // The tailored session too (docs/COACH.md), for a link as well.
    markAfterSignIn(COACH_PATH)
    expect(takeAfterSignIn()).toBe('/session/auto')
    markLinkReturn(COACH_PATH)
    expect(takeLinkReturn()).toBe('/session/auto')
    // The guided tour comes back to itself (docs/ONBOARDING.md).
    markLinkReturn(TOUR_PATH)
    expect(takeLinkReturn()).toBe('/visite')

    markAfterSignIn('/bienvenue')
    expect(takeAfterSignIn()).toBeNull()
    markAfterSignIn('https://evil.example')
    expect(takeAfterSignIn()).toBeNull()
    sessionStorage.setItem('dontstayrooky.afterSignIn', '/admin')
    expect(takeAfterSignIn()).toBeNull()
  })
})

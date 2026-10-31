import { describe, expect, it, vi } from 'vitest'
import {
  ACCOUNT_PAGE,
  createAuthGuard,
  FROZEN_PAGE,
  safeRedirect
} from '@/router/guards'

function fakeAuth({
  authenticated = false,
  mfa = null,
  frozen = false,
  admin = false,
  guest = false
} = {}) {
  return {
    isAuthenticated: authenticated,
    isFrozen: frozen,
    isAdmin: admin,
    isGuest: guest,
    mfa,
    init: vi.fn(async () => {})
  }
}

const route = (auth, fullPath = '/somewhere') => ({
  meta: auth ? { auth } : {},
  fullPath
})

describe('createAuthGuard', () => {
  it('opens a guest account the pages marked for it only, and the sign-up', async () => {
    const guard = createAuthGuard(() =>
      fakeAuth({ authenticated: true, guest: true })
    )

    expect(await guard({ meta: { auth: 'required', guestOpen: true } })).toBe(
      true
    )
    expect(await guard(route('required', '/repertoire'))).toEqual({
      path: ACCOUNT_PAGE
    })
    expect(await guard(route('admin'))).toEqual({ path: '/' })
    expect(await guard(route('guest', '/register'))).toBe(true)
    expect(await guard(route('public'))).toBe(true)
  })

  it('restores the session before deciding', async () => {
    const auth = fakeAuth()

    await createAuthGuard(() => auth)(route('public'))

    expect(auth.init).toHaveBeenCalled()
  })

  it('lets anyone through public pages (the default)', async () => {
    expect(await createAuthGuard(() => fakeAuth())(route(undefined))).toBe(true)
    expect(
      await createAuthGuard(() => fakeAuth({ authenticated: true }))(
        route('public')
      )
    ).toBe(true)
  })

  it('sends signed-out users to the login page, remembering where they were going', async () => {
    const result = await createAuthGuard(() => fakeAuth())(
      route('required', '/profile?tab=devices')
    )

    expect(result).toEqual({
      path: '/login',
      query: { redirect: '/profile?tab=devices' }
    })
  })

  it('lets signed-in users through protected pages', async () => {
    expect(
      await createAuthGuard(() => fakeAuth({ authenticated: true }))(
        route('required')
      )
    ).toBe(true)
  })

  it('keeps the administration to admins', async () => {
    expect(
      await createAuthGuard(() => fakeAuth())(route('admin', '/admin'))
    ).toEqual({ path: '/login', query: { redirect: '/admin' } })
    expect(
      await createAuthGuard(() => fakeAuth({ authenticated: true }))(
        route('admin')
      )
    ).toEqual({ path: '/' })
    expect(
      await createAuthGuard(() =>
        fakeAuth({ authenticated: true, admin: true })
      )(route('admin'))
    ).toBe(true)
  })

  it('keeps signed-in users away from guest-only pages', async () => {
    expect(
      await createAuthGuard(() => fakeAuth({ authenticated: true }))(
        route('guest')
      )
    ).toEqual({ path: '/' })
    expect(await createAuthGuard(() => fakeAuth())(route('guest'))).toBe(true)
  })

  it('keeps a frozen account on the deletion page', async () => {
    const frozen = () => fakeAuth({ authenticated: true, frozen: true })
    expect(
      await createAuthGuard(frozen)({ ...route('required'), path: '/puzzle' })
    ).toEqual({ path: FROZEN_PAGE })
    expect(
      await createAuthGuard(frozen)({ ...route('public'), path: '/' })
    ).toEqual({ path: FROZEN_PAGE })
    expect(
      await createAuthGuard(frozen)({ ...route('public'), path: FROZEN_PAGE })
    ).toBe(true)
    // Signed out right after the confirmation: the page says when, nothing else is blocked.
    expect(
      await createAuthGuard(() => fakeAuth({ frozen: true }))({
        ...route('public'),
        path: '/'
      })
    ).toBe(true)
  })

  it('opens the code page only while a login waits for its code', async () => {
    expect(await createAuthGuard(() => fakeAuth())(route('mfa'))).toEqual({
      path: '/login'
    })
    expect(
      await createAuthGuard(() => fakeAuth({ mfa: { pendingToken: 'p' } }))(
        route('mfa')
      )
    ).toBe(true)
  })
})

describe('safeRedirect', () => {
  it.each(['/profile', '/profile?tab=1', '/a/b#c'])(
    'keeps the in-app path %s',
    path => {
      expect(safeRedirect(path)).toBe(path)
    }
  )

  it.each([
    'https://evil.example',
    '//evil.example',
    '/\\evil.example',
    'javascript:alert(1)',
    '',
    undefined,
    ['/profile']
  ])('rejects %s (open redirect)', value => {
    expect(safeRedirect(value)).toBe('/')
  })
})

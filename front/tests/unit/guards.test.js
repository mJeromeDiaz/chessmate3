import { describe, expect, it, vi } from 'vitest'
import { createAuthGuard, safeRedirect } from '@/router/guards'

function fakeAuth({ authenticated = false, mfa = null } = {}) {
  return {
    isAuthenticated: authenticated,
    mfa,
    init: vi.fn(async () => {})
  }
}

const route = (auth, fullPath = '/somewhere') => ({
  meta: auth ? { auth } : {},
  fullPath
})

describe('createAuthGuard', () => {
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

  it('keeps signed-in users away from guest-only pages', async () => {
    expect(
      await createAuthGuard(() => fakeAuth({ authenticated: true }))(
        route('guest')
      )
    ).toEqual({ path: '/profile' })
    expect(await createAuthGuard(() => fakeAuth())(route('guest'))).toBe(true)
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
    expect(safeRedirect(value)).toBe('/profile')
  })
})

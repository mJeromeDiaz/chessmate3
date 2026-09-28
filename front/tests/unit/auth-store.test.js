import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/services/api', () => ({
  authApi: {
    login: vi.fn(),
    verifyMfa: vi.fn(),
    resendMfa: vi.fn(),
    refresh: vi.fn(),
    logout: vi.fn(),
    changePassword: vi.fn()
  },
  profileApi: {
    get: vi.fn(),
    addPassword: vi.fn(),
    unlinkIdentity: vi.fn(),
    startLink: vi.fn()
  }
}))

const { authApi, profileApi } = await import('@/services/api')
const { useAuthStore } = await import('@/stores/auth')

const PROFILE = { id: 'u1', email: 'alice@example.com', identities: [] }

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
    profileApi.get.mockResolvedValue(PROFILE)
  })

  it('keeps a login waiting for its code, without any access token', async () => {
    authApi.login.mockResolvedValue({
      mfaPendingToken: 'pending',
      expiresAt: '2026-01-01T00:00:00+00:00'
    })
    const auth = useAuthStore()

    expect(await auth.login('alice@example.com', 'pw')).toBe('mfa')

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.mfa).toEqual({
      pendingToken: 'pending',
      expiresAt: '2026-01-01T00:00:00+00:00',
      email: 'alice@example.com'
    })
  })

  it('opens the session once the code is verified', async () => {
    authApi.login.mockResolvedValue({ mfaPendingToken: 'pending' })
    authApi.verifyMfa.mockResolvedValue({ accessToken: 'jwt' })
    const auth = useAuthStore()
    await auth.login('alice@example.com', 'pw')

    await auth.verifyMfa('123456', true)

    expect(authApi.verifyMfa).toHaveBeenCalledWith('pending', '123456', true)
    expect(auth.accessToken).toBe('jwt')
    expect(auth.mfa).toBeNull()
    expect(auth.profile).toEqual(PROFILE)
  })

  it('keeps the pending login after a wrong code', async () => {
    authApi.login.mockResolvedValue({ mfaPendingToken: 'pending' })
    authApi.verifyMfa.mockRejectedValue({ response: { status: 401 } })
    const auth = useAuthStore()
    await auth.login('alice@example.com', 'pw')

    await expect(auth.verifyMfa('000000', false)).rejects.toBeTruthy()

    expect(auth.mfa?.pendingToken).toBe('pending')
    expect(auth.isAuthenticated).toBe(false)
  })

  it('signs in directly when a trusted device skips the code', async () => {
    authApi.login.mockResolvedValue({ accessToken: 'jwt' })
    const auth = useAuthStore()

    expect(await auth.login('alice@example.com', 'pw')).toBe('authenticated')
    expect(auth.accessToken).toBe('jwt')
  })

  it('never writes the access token to web storage', async () => {
    authApi.login.mockResolvedValue({ accessToken: 'secret-jwt' })
    const auth = useAuthStore()

    await auth.login('alice@example.com', 'pw')

    const stored =
      JSON.stringify({ ...localStorage }) +
      JSON.stringify({ ...sessionStorage })
    expect(stored).not.toContain('secret-jwt')
  })

  it('shares one refresh request between concurrent callers', async () => {
    let resolve
    authApi.refresh.mockReturnValue(new Promise(r => (resolve = r)))
    const auth = useAuthStore()

    const both = Promise.all([auth.refresh(), auth.refresh()])
    resolve({ accessToken: 'fresh' })

    expect(await both).toEqual(['fresh', 'fresh'])
    expect(authApi.refresh).toHaveBeenCalledTimes(1)
    expect(auth.accessToken).toBe('fresh')
  })

  it('clears the session when the refresh fails', async () => {
    authApi.login.mockResolvedValue({ accessToken: 'jwt' })
    authApi.refresh.mockRejectedValue({ response: { status: 401 } })
    const auth = useAuthStore()
    await auth.login('alice@example.com', 'pw')

    await expect(auth.refresh()).rejects.toBeTruthy()

    expect(auth.accessToken).toBeNull()
    expect(auth.profile).toBeNull()
  })

  it('restores the session from the refresh cookie once per page load', async () => {
    authApi.refresh.mockResolvedValue({ accessToken: 'restored' })
    const auth = useAuthStore()

    await Promise.all([auth.init(), auth.init()])

    expect(authApi.refresh).toHaveBeenCalledTimes(1)
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.profile).toEqual(PROFILE)
    expect(auth.initialized).toBe(true)
  })

  it('treats a missing refresh cookie as signed out, without failing', async () => {
    authApi.refresh.mockRejectedValue({ response: { status: 401 } })
    const auth = useAuthStore()

    await expect(auth.init()).resolves.toBeUndefined()

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.initialized).toBe(true)
  })

  it('signs out locally even when the API cannot be reached', async () => {
    authApi.login.mockResolvedValue({ accessToken: 'jwt' })
    authApi.logout.mockRejectedValue(new Error('network'))
    const auth = useAuthStore()
    await auth.login('alice@example.com', 'pw')

    await expect(auth.logout()).rejects.toThrow('network')

    expect(auth.accessToken).toBeNull()
    expect(auth.profile).toBeNull()
  })

  it('continues in the new session issued after a password change', async () => {
    authApi.login.mockResolvedValue({ accessToken: 'old' })
    authApi.changePassword.mockResolvedValue({ accessToken: 'new' })
    const auth = useAuthStore()
    await auth.login('alice@example.com', 'pw')

    await auth.changePassword('pw', 'new-passphrase')

    expect(auth.accessToken).toBe('new')
  })

  it('continues in the new session issued after unlinking an account', async () => {
    authApi.login.mockResolvedValue({ accessToken: 'old' })
    profileApi.unlinkIdentity.mockResolvedValue({
      accessToken: 'new',
      profile: { ...PROFILE, identities: [] }
    })
    const auth = useAuthStore()
    await auth.login('alice@example.com', 'pw')

    await auth.unlinkIdentity('identity-1')

    expect(profileApi.unlinkIdentity).toHaveBeenCalledWith('identity-1')
    expect(auth.accessToken).toBe('new')
  })

  it('reports whether an added password still waits for an email confirmation', async () => {
    profileApi.addPassword.mockResolvedValue({ status: 'verification_sent' })
    const auth = useAuthStore()

    expect(await auth.addPassword('passphrase', 'magnus@example.com')).toBe(
      'verification_sent'
    )
    expect(profileApi.addPassword).toHaveBeenCalledWith(
      'passphrase',
      'magnus@example.com'
    )
  })
})

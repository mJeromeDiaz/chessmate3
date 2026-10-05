import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi, profileApi } from '@/services/api'
import { browserTimezone } from '@/utils/timezone'

/**
 * Runs `task` while holding a lock shared by every tab of this origin (Web Locks API), when the
 * browser has it.
 *
 * Refresh tokens are single-use and reuse revokes the whole session: two tabs refreshing at the
 * same moment with the same cookie would log the user out everywhere. Serialized, the second tab
 * sends the cookie the first one just received.
 *
 * @template T
 * @param {() => Promise<T>} task
 * @returns {Promise<T>}
 */
function withCrossTabLock(task) {
  const locks = globalThis.navigator?.locks

  return locks ? locks.request('chessmate-auth-refresh', task) : task()
}

/**
 * Authentication state.
 *
 * The access token lives in memory only (never localStorage/sessionStorage, where any XSS could
 * read it). It is lost on reload; {@see init} gets a new one from the HttpOnly refresh cookie.
 */
export const useAuthStore = defineStore('auth', () => {
  /** @type {import('vue').Ref<string|null>} */
  const accessToken = ref(null)
  /** @type {import('vue').Ref<object|null>} The /api/profile payload. */
  const profile = ref(null)
  /** @type {import('vue').Ref<{pendingToken: string, expiresAt: string|null, email: string}|null>} */
  const mfa = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => accessToken.value !== null)
  /** A deletion is scheduled: the account is frozen until it is cancelled (docs/AUTH.md). */
  const isFrozen = computed(() => !!profile.value?.deletionScheduledAt)

  /** @type {Promise<void>|null} */
  let initPromise = null
  /** @type {Promise<string>|null} */
  let refreshPromise = null

  function clearSession() {
    accessToken.value = null
    profile.value = null
  }

  /**
   * Restores the session from the refresh cookie, once per page load. Never rejects: no valid
   * cookie just means "signed out".
   *
   * @returns {Promise<void>}
   */
  function init() {
    if (!initPromise) {
      initPromise = refresh()
        .then(() => fetchProfile())
        .catch(() => clearSession())
        .finally(() => {
          initialized.value = true
        })
    }

    return initPromise
  }

  /**
   * Trades the refresh cookie for a new access token (the API rotates the cookie at the same
   * time). Concurrent callers share one request; on failure the session is cleared.
   *
   * @returns {Promise<string>} the new access token
   */
  function refresh() {
    if (!refreshPromise) {
      refreshPromise = withCrossTabLock(() => authApi.refresh())
        .then(data => {
          accessToken.value = data.accessToken
          return data.accessToken
        })
        .catch(error => {
          clearSession()
          throw error
        })
        .finally(() => {
          refreshPromise = null
        })
    }

    return refreshPromise
  }

  /**
   * Step 1 of the password login.
   *
   * @param {string} email
   * @param {string} password
   * @returns {Promise<'authenticated'|'mfa'>} 'mfa' when a code was emailed (see {@see verifyMfa}),
   *   'authenticated' when a trusted device skipped that step
   */
  async function login(email, password) {
    const data = await authApi.login(email, password)

    if (data.accessToken) {
      await startSession(data.accessToken)
      return 'authenticated'
    }

    mfa.value = {
      pendingToken: data.mfaPendingToken,
      expiresAt: data.expiresAt ?? null,
      email
    }
    return 'mfa'
  }

  /**
   * Step 2: the emailed code. On a 401 the code was wrong, expired or used too many times; the
   * pending login is kept so the user can retry (the API decides when it's over).
   *
   * @param {string} code
   * @param {boolean} trustDevice
   */
  async function verifyMfa(code, trustDevice) {
    if (!mfa.value) {
      throw new Error('No login waiting for a code.')
    }

    const data = await authApi.verifyMfa(
      mfa.value.pendingToken,
      code,
      trustDevice
    )
    mfa.value = null
    await startSession(data.accessToken)
  }

  async function resendMfa() {
    if (!mfa.value) {
      throw new Error('No login waiting for a code.')
    }

    await authApi.resendMfa(mfa.value.pendingToken)
  }

  function cancelMfa() {
    mfa.value = null
  }

  /**
   * Ends this session on the API (revokes its refresh-token family, clears the cookie) and
   * forgets it locally, even if the API can't be reached.
   */
  async function logout() {
    try {
      await authApi.logout()
    } finally {
      clearSession()
      mfa.value = null
    }
  }

  async function fetchProfile() {
    profile.value = await profileApi.get()
    // Local dates (activity days, Woodpecker deadlines) need the user's timezone: report the
    // browser's once, at the first sign-in without one. Never blocks the session.
    if (profile.value && !profile.value.timezone) {
      const detected = browserTimezone()
      if (detected) setTimezone(detected).catch(() => {})
    }
    return profile.value
  }

  /**
   * Saves the display name, handle and avatar shown on the profile.
   *
   * @param {{displayName: string|null, handle: string|null, avatar: string|null}} info
   */
  async function setInfo(info) {
    profile.value = await profileApi.setInfo(info)
    return profile.value
  }

  /**
   * Changes some of the profile's preferences. Applied at once (boards follow the profile), put
   * back if the API refuses.
   *
   * @param {Partial<{boardTheme: string, moveSound: boolean, publicProfile: boolean}>} changes
   */
  async function setPreferences(changes) {
    const before = profile.value
    const next = {
      boardTheme: before.boardTheme,
      moveSound: before.moveSound,
      publicProfile: before.publicProfile,
      ...changes
    }
    profile.value = { ...before, ...next }
    try {
      profile.value = await profileApi.setPreferences(next)
    } catch (e) {
      profile.value = before
      throw e
    }
    return profile.value
  }

  /** @param {string} timezone IANA identifier */
  async function setTimezone(timezone) {
    profile.value = await profileApi.setTimezone(timezone)
    return profile.value
  }

  /**
   * Adopts a token obtained by any means (login, OAuth callback via refresh...) and loads the
   * profile.
   *
   * @param {string} token
   */
  async function startSession(token) {
    accessToken.value = token
    initialized.value = true
    await fetchProfile()
  }

  /**
   * Every other session is closed by the API; this one continues with a new token.
   *
   * @param {string} currentPassword
   * @param {string} newPassword
   */
  async function changePassword(currentPassword, newPassword) {
    const data = await authApi.changePassword(currentPassword, newPassword)
    await startSession(data.accessToken)
  }

  /**
   * @param {string} password
   * @param {string|null} email required when the account has no verified email
   * @returns {Promise<'added'|'verification_sent'>}
   */
  async function addPassword(password, email = null) {
    const data = await profileApi.addPassword(password, email)

    if (data.profile) {
      profile.value = data.profile
    } else {
      await fetchProfile()
    }

    return data.status
  }

  /**
   * Removes a linked account. The API ends every session; this one continues with a new token.
   *
   * @param {string} identityId
   */
  async function unlinkIdentity(identityId) {
    const data = await profileApi.unlinkIdentity(identityId)
    accessToken.value = data.accessToken
    profile.value = data.profile
  }

  /**
   * Starts linking a provider; the caller navigates to the returned URL.
   *
   * @param {'google'|'lichess'} provider
   * @returns {Promise<string>} the provider's authorization URL
   */
  async function startLink(provider) {
    const data = await profileApi.startLink(provider)
    return data.authorizationUrl
  }

  /**
   * Confirms the account deletion. The API closes every session, this one included: it is
   * forgotten here too.
   *
   * @param {string|null} code the emailed code, null for an account without a verified email
   * @returns {Promise<string>} when the account will be purged
   */
  async function confirmDeletion(code) {
    const data = await profileApi.confirmDeletion(code)
    clearSession()
    return data.deletionScheduledAt
  }

  /** Cancels the scheduled deletion: the account is usable again. */
  async function cancelDeletion() {
    await profileApi.cancelDeletion()
    await fetchProfile()
  }

  return {
    accessToken,
    profile,
    mfa,
    initialized,
    isAuthenticated,
    isFrozen,
    confirmDeletion,
    cancelDeletion,
    init,
    refresh,
    login,
    verifyMfa,
    resendMfa,
    cancelMfa,
    logout,
    clearSession,
    fetchProfile,
    setTimezone,
    setInfo,
    setPreferences,
    startSession,
    changePassword,
    addPassword,
    unlinkIdentity,
    startLink
  }
})

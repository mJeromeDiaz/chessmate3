import axios from 'axios'

/**
 * @typedef {object} AuthHandlers
 * @property {() => (string|null)} getAccessToken Current in-memory access token, if any.
 * @property {() => Promise<string>} refresh Gets a new access token from the refresh cookie.
 *   Must reject when the session can't be renewed.
 * @property {() => void} onAuthFailure Called once when a refresh fails: clear the session and
 *   send the user to the login page.
 */

/**
 * Creates the axios instance used for every API call.
 *
 * - Adds `Authorization: Bearer <access token>` when a token is in memory.
 * - On a 401, refreshes the access token once and replays the request. Concurrent 401s share
 *   the same refresh: requests failing while it runs wait for it, then replay with the new token.
 * - If the refresh fails, every waiting request is rejected and `onAuthFailure` runs once.
 *
 * Requests sent with `skipAuthRefresh: true` in their config (login, refresh itself, public auth
 * endpoints) never trigger a refresh: their 401 means what it says.
 *
 * @param {AuthHandlers} handlers
 * @param {import('axios').CreateAxiosDefaults} [config]
 * @returns {import('axios').AxiosInstance}
 */
export function createHttpClient(handlers, config = {}) {
  const client = axios.create({
    // The refresh cookie is HttpOnly and scoped to the API: the browser must send it.
    withCredentials: true,
    headers: { Accept: 'application/json' },
    ...config
  })

  /** @type {Promise<string>|null} */
  let refreshInFlight = null

  /**
   * One refresh at a time: every caller gets the same promise.
   *
   * @returns {Promise<string>}
   */
  function sharedRefresh() {
    if (!refreshInFlight) {
      refreshInFlight = handlers
        .refresh()
        .catch(error => {
          handlers.onAuthFailure()
          throw error
        })
        .finally(() => {
          refreshInFlight = null
        })
    }

    return refreshInFlight
  }

  client.interceptors.request.use(async request => {
    // A request started while a refresh runs waits for it, instead of failing with the old token.
    if (refreshInFlight && !request.skipAuthRefresh) {
      await refreshInFlight.catch(() => {})
    }

    const token = handlers.getAccessToken()
    if (token && !request.headers.has('Authorization')) {
      request.headers.set('Authorization', `Bearer ${token}`)
    }

    return request
  })

  client.interceptors.response.use(
    response => response,
    async error => {
      const request = error.config

      if (
        !request ||
        error.response?.status !== 401 ||
        request.skipAuthRefresh ||
        request._retried
      ) {
        throw error
      }

      const token = await sharedRefresh()
      request._retried = true
      request.headers.set('Authorization', `Bearer ${token}`)

      return client.request(request)
    }
  )

  return client
}

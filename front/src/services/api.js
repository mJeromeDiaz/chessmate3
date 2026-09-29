import { createHttpClient } from '@/services/http'

/** Base URL of the Symfony API (no trailing slash). */
export const API_URL = (
  import.meta.env.API_URL || 'http://localhost:8000'
).replace(/\/$/, '')

/** @type {import('@/services/http').AuthHandlers} */
const handlers = {
  getAccessToken: () => null,
  refresh: () => Promise.reject(new Error('Auth handlers not configured')),
  onAuthFailure: () => {}
}

/**
 * Plugs the auth store into the shared HTTP client (done once, in boot/axios.js). Kept separate
 * so this module doesn't import the store (which imports it).
 *
 * @param {Partial<import('@/services/http').AuthHandlers>} value
 */
export function setAuthHandlers(value) {
  Object.assign(handlers, value)
}

export const http = createHttpClient(
  {
    getAccessToken: () => handlers.getAccessToken(),
    refresh: () => handlers.refresh(),
    onAuthFailure: () => handlers.onAuthFailure()
  },
  { baseURL: API_URL }
)

/** Public auth calls: a 401 there is an answer, not an expired access token. */
const PUBLIC = { skipAuthRefresh: true }

/**
 * Every endpoint the front uses, in one place. Each returns the response body.
 */
export const authApi = {
  /** @returns {Promise<{accessToken?: string, mfaPendingToken?: string, expiresAt?: string}>} */
  login: (email, password) =>
    http.post('/api/auth/login', { email, password }, PUBLIC).then(r => r.data),
  verifyMfa: (pendingToken, code, trustDevice) =>
    http
      .post(
        '/api/auth/login/mfa/verify',
        { pendingToken, code, trustDevice },
        PUBLIC
      )
      .then(r => r.data),
  resendMfa: pendingToken =>
    http
      .post('/api/auth/login/mfa/resend', { pendingToken }, PUBLIC)
      .then(r => r.data),
  /** The CSRF defence of this endpoint is the custom header (a cross-site form can't send it). */
  refresh: () =>
    http
      .post('/api/auth/refresh', null, {
        ...PUBLIC,
        headers: { 'X-Refresh-Request': '1' }
      })
      .then(r => r.data),
  logout: () => http.post('/api/auth/logout', null, PUBLIC).then(r => r.data),
  register: (email, password, timezone = null) =>
    http
      .post('/api/auth/register', { email, password, timezone }, PUBLIC)
      .then(r => r.data),
  resendVerification: email =>
    http
      .post('/api/auth/verify-email/resend', { email }, PUBLIC)
      .then(r => r.data),
  forgotPassword: email =>
    http.post('/api/auth/forgot-password', { email }, PUBLIC).then(r => r.data),
  resetPassword: (token, newPassword) =>
    http
      .post('/api/auth/reset-password', { token, newPassword }, PUBLIC)
      .then(r => r.data),
  changePassword: (currentPassword, newPassword) =>
    http
      .post('/api/auth/password/change', { currentPassword, newPassword })
      .then(r => r.data),
  /** Full-page navigation target starting an OAuth login (not an XHR call). */
  oauthLoginUrl: provider =>
    `${API_URL}/api/auth/oauth/${encodeURIComponent(provider)}/redirect`
}

export const profileApi = {
  get: () => http.get('/api/profile').then(r => r.data),
  /** @param {string} timezone IANA identifier */
  setTimezone: timezone =>
    http.put('/api/profile/timezone', { timezone }).then(r => r.data),
  addPassword: (password, email) =>
    http
      .post('/api/profile/password', email ? { password, email } : { password })
      .then(r => r.data),
  unlinkIdentity: id =>
    http
      .delete(`/api/profile/identities/${encodeURIComponent(id)}`)
      .then(r => r.data),
  /** @returns {Promise<{authorizationUrl: string}>} */
  startLink: provider =>
    http
      .post(`/api/profile/identities/${encodeURIComponent(provider)}/link`)
      .then(r => r.data),
  trustedDevices: () =>
    http.get('/api/profile/trusted-devices').then(r => r.data.devices),
  revokeTrustedDevice: id =>
    http
      .delete(`/api/profile/trusted-devices/${encodeURIComponent(id)}`)
      .then(r => r.data)
}

/** API Platform resources speak JSON-LD (collections carry `member` and `totalItems`). */
const JSON_LD = {
  headers: {
    Accept: 'application/ld+json',
    'Content-Type': 'application/ld+json'
  }
}

export const puzzleApi = {
  /**
   * Next rated puzzle (or the pending one): creates a server-side attempt.
   *
   * @param {{themes?: string[], difficulty?: 'easier'|'normal'|'harder'}} criteria
   */
  start: (criteria = {}) =>
    http.post('/api/puzzles/attempts', criteria, JSON_LD).then(r => r.data),
  /** Unrated replay of a puzzle from the history (Lichess id). */
  replay: puzzleId =>
    http
      .post('/api/puzzles/attempts', { replayOf: puzzleId }, JSON_LD)
      .then(r => r.data),
  /**
   * Reports what happened; the server computes the result.
   *
   * @param {string} attemptId
   * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
   */
  submit: (attemptId, report) =>
    http
      .post(
        `/api/puzzles/attempts/${encodeURIComponent(attemptId)}/submission`,
        report,
        JSON_LD
      )
      .then(r => r.data),
  /** @param {{page?: number, result?: 'solved'|'failed', theme?: string}} params */
  history: (params = {}) =>
    http.get('/api/puzzles/attempts', { ...JSON_LD, params }).then(r => r.data),
  themes: () =>
    http.get('/api/puzzles/themes', JSON_LD).then(r => r.data.member),
  rating: () => http.get('/api/puzzles/rating', JSON_LD).then(r => r.data),
  importLichessRating: () =>
    http
      .post('/api/puzzles/rating/lichess-import', null, JSON_LD)
      .then(r => r.data)
}

export const woodpeckerApi = {
  /** @param {{archived?: boolean}} params */
  sets: (params = {}) =>
    http
      .get('/api/woodpecker/sets', {
        ...JSON_LD,
        params: params.archived ? { archived: 'true' } : {}
      })
      .then(r => r.data.member),
  set: id =>
    http
      .get(`/api/woodpecker/sets/${encodeURIComponent(id)}`, JSON_LD)
      .then(r => r.data),
  /** @param {object} payload see docs/WOODPECKER.md, "Creating a set" */
  create: payload =>
    http.post('/api/woodpecker/sets', payload, JSON_LD).then(r => r.data),
  /**
   * @param {string} id
   * @param {'pause'|'resume'|'abandon'|'archive'} action
   */
  act: (id, action) =>
    http
      .post(
        `/api/woodpecker/sets/${encodeURIComponent(id)}/${action}`,
        null,
        JSON_LD
      )
      .then(r => r.data),
  /** The next puzzle of the current cycle (or the pending one). */
  next: setId =>
    http
      .post(
        `/api/woodpecker/sets/${encodeURIComponent(setId)}/attempts`,
        null,
        JSON_LD
      )
      .then(r => r.data),
  /**
   * @param {string} attemptId
   * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
   */
  submit: (attemptId, report) =>
    http
      .post(
        `/api/woodpecker/attempts/${encodeURIComponent(attemptId)}/submission`,
        report,
        JSON_LD
      )
      .then(r => r.data),
  stubborn: setId =>
    http
      .get(
        `/api/woodpecker/sets/${encodeURIComponent(setId)}/stubborn`,
        JSON_LD
      )
      .then(r => r.data.member)
}

/** Timed runs of any module (docs/TRAINING.md). */
export const trainingApi = {
  /** @param {{module: string, subjectId: string, budgetSeconds: number}} payload */
  start: payload =>
    http.post('/api/training/runs', payload, JSON_LD).then(r => r.data),
  /** The active run, or null. */
  current: () =>
    http
      .get('/api/training/runs/current', JSON_LD)
      .then(r => r.data)
      .catch(e => {
        if (e?.response?.status === 404) return null
        throw e
      }),
  get: id =>
    http
      .get(`/api/training/runs/${encodeURIComponent(id)}`, JSON_LD)
      .then(r => r.data),
  /** The item to play; none once the run is closed. */
  next: id =>
    http
      .post(`/api/training/runs/${encodeURIComponent(id)}/next`, null, JSON_LD)
      .then(r => r.data),
  /**
   * @param {string} id
   * @param {{itemId: string, moves: string[], hintLevel: number, solutionShown: boolean}} report
   */
  submit: (id, report) =>
    http
      .post(
        `/api/training/runs/${encodeURIComponent(id)}/submission`,
        report,
        JSON_LD
      )
      .then(r => r.data),
  stop: id =>
    http
      .post(`/api/training/runs/${encodeURIComponent(id)}/stop`, null, JSON_LD)
      .then(r => r.data)
}

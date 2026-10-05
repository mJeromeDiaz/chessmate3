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
  /**
   * Every data of the account as a ZIP (JSON + PGN), 3 a day; allowed to a frozen account.
   *
   * @returns {Promise<{blob: Blob, fileName: string}>}
   */
  export: () =>
    http.get('/api/profile/export', { responseType: 'blob' }).then(r => ({
      blob: r.data,
      fileName:
        /filename="?([^";]+)"?/.exec(
          r.headers['content-disposition'] ?? ''
        )?.[1] ?? 'chessmate-export.zip'
    })),
  /**
   * Starts an account deletion: a code emailed (`email`), or, without a verified email, whether
   * this session signed in recently enough (`recent_sign_in`). Under /api/auth: the refresh
   * cookie tells when this session signed in.
   *
   * @returns {Promise<{method: 'email', expiresAt: string}|{method: 'recent_sign_in', recentSignIn: boolean}>}
   */
  startDeletion: () =>
    http.post('/api/auth/account-deletion').then(r => r.data),
  /**
   * Confirms it with the emailed code (null without an email): every session is closed.
   *
   * @param {string|null} code
   * @returns {Promise<{deletionScheduledAt: string}>}
   */
  confirmDeletion: code =>
    http
      .post('/api/auth/account-deletion/confirm', code ? { code } : {})
      .then(r => r.data),
  /** Cancels a scheduled deletion (idempotent). */
  cancelDeletion: () =>
    http.post('/api/auth/account-deletion/cancel').then(() => undefined),
  /** @param {string} timezone IANA identifier */
  setTimezone: timezone =>
    http.put('/api/profile/timezone', { timezone }).then(r => r.data),
  /** @param {'auto'|'light'|'dark'} theme */
  setTheme: theme =>
    http.put('/api/profile/theme', { theme }).then(r => r.data),
  /**
   * Display name, handle and avatar (each replaced; null or empty clears it).
   *
   * @param {{displayName: string|null, handle: string|null, avatar: string|null}} info
   */
  setInfo: info => http.put('/api/profile/info', info).then(r => r.data),
  /**
   * The active sessions, the asking one first (under /api/auth: the refresh cookie tells which).
   *
   * @returns {Promise<Array<{id: string, current: boolean, browser: string|null, os: string|null, form: 'phone'|'tablet'|'desktop'|null, ip: string|null, signedInAt: string|null, lastActiveAt: string|null}>>}
   */
  sessions: () => http.get('/api/auth/sessions').then(r => r.data.sessions),
  /** Closes another session (409 for the asking one). */
  closeSession: id =>
    http
      .delete(`/api/auth/sessions/${encodeURIComponent(id)}`)
      .then(r => r.data),
  /**
   * Board colours, move sounds and public profile flag (all three required).
   *
   * @param {{boardTheme: string, moveSound: boolean, publicProfile: boolean}} preferences
   */
  setPreferences: preferences =>
    http.put('/api/profile/preferences', preferences).then(r => r.data),
  /**
   * @param {string} handle
   * @returns {Promise<{handle: string, available: boolean, reason: null|'invalid'|'reserved'|'taken'}>}
   */
  handleAvailability: handle =>
    http
      .get('/api/profile/handle-availability', { params: { handle } })
      .then(r => r.data),
  addPassword: (password, email) =>
    http
      .post('/api/profile/password', email ? { password, email } : { password })
      .then(r => r.data),
  unlinkIdentity: id =>
    http
      .delete(`/api/profile/identities/${encodeURIComponent(id)}`)
      .then(r => r.data),
  /**
   * Asks the linked Lichess account for study:read (private study import).
   *
   * @returns {Promise<{authorizationUrl: string}>}
   */
  startGrant: provider =>
    http
      .post(`/api/profile/identities/${encodeURIComponent(provider)}/grant`)
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
  /** @param {{module: string, subjectId: string, budgetSeconds: number, config?: Record<string, any>}} payload */
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
   * @param {{itemId: string, moves: string[], hintLevel: number, solutionShown: boolean, thinkMs?: number}} report
   */
  submit: (id, report) =>
    http
      .post(
        `/api/training/runs/${encodeURIComponent(id)}/submission`,
        report,
        JSON_LD
      )
      .then(r => r.data),
  /** The items of a closed run, for its end-of-run review (409 while it is active). */
  review: id =>
    http
      .get(`/api/training/runs/${encodeURIComponent(id)}/review`, JSON_LD)
      .then(r => r.data),
  stop: id =>
    http
      .post(`/api/training/runs/${encodeURIComponent(id)}/stop`, null, JSON_LD)
      .then(r => r.data)
}

/** Training sessions: a program of modules played step by step (docs/TRAINING.md). */
export const sessionApi = {
  /**
   * @param {{title: string, description: string, steps: {module: string, minutes: number, notes: string, settings: Record<string, any>}[]}} payload
   */
  create: payload =>
    http.post('/api/training/sessions', payload, JSON_LD).then(r => r.data),
  /** The latest sessions, newest first. */
  list: () =>
    http.get('/api/training/sessions', JSON_LD).then(r => r.data.member),
  /** The active session, or null. */
  current: () =>
    http
      .get('/api/training/sessions/current', JSON_LD)
      .then(r => r.data)
      .catch(e => {
        if (e?.response?.status === 404) return null
        throw e
      }),
  get: id =>
    http
      .get(`/api/training/sessions/${encodeURIComponent(id)}`, JSON_LD)
      .then(r => r.data),
  /** Starts the current step: `{session, run}`. */
  next: id =>
    http
      .post(
        `/api/training/sessions/${encodeURIComponent(id)}/next`,
        null,
        JSON_LD
      )
      .then(r => r.data),
  skip: id =>
    http
      .post(
        `/api/training/sessions/${encodeURIComponent(id)}/skip`,
        null,
        JSON_LD
      )
      .then(r => r.data),
  abandon: id =>
    http
      .post(
        `/api/training/sessions/${encodeURIComponent(id)}/abandon`,
        null,
        JSON_LD
      )
      .then(r => r.data)
}

/** Web Push for this browser (docs/NOTIFICATIONS.md). */
export const notificationApi = {
  /** `{enabled, publicKey}`: whether the server sends browser notifications. */
  pushConfig: () =>
    http.get('/api/notifications/push', JSON_LD).then(r => r.data),
  /** @param {{endpoint: string, keys: {p256dh: string, auth: string}}} subscription */
  subscribe: subscription =>
    http
      .post('/api/notifications/push/subscriptions', subscription, JSON_LD)
      .then(r => r.data),
  /** @param {string} endpoint */
  status: endpoint =>
    http
      .post(
        '/api/notifications/push/subscription-status',
        { endpoint },
        JSON_LD
      )
      .then(r => r.data.subscribed === true),
  /** @param {string} endpoint */
  unsubscribe: endpoint =>
    http
      .post('/api/notifications/push/unsubscribe', { endpoint }, JSON_LD)
      .then(r => r.data)
}

/** Saved training sessions: program and settings, launched on demand (docs/TRAINING.md). */
export const planApi = {
  list: () => http.get('/api/training/plans', JSON_LD).then(r => r.data.member),
  get: id =>
    http
      .get(`/api/training/plans/${encodeURIComponent(id)}`, JSON_LD)
      .then(r => r.data),
  /** @param {Record<string, any>} payload see docs/TRAINING.md, saved sessions */
  create: payload =>
    http.post('/api/training/plans', payload, JSON_LD).then(r => r.data),
  update: (id, payload) =>
    http
      .put(`/api/training/plans/${encodeURIComponent(id)}`, payload, JSON_LD)
      .then(r => r.data),
  remove: id =>
    http.delete(`/api/training/plans/${encodeURIComponent(id)}`, JSON_LD),
  /** Launches a played session from it (its first step is started by sessionApi.next). */
  launch: id =>
    http
      .post(
        `/api/training/plans/${encodeURIComponent(id)}/launch`,
        null,
        JSON_LD
      )
      .then(r => r.data)
}

/** The calendar of saved sessions (docs/TRAINING.md, calendar). */
export const calendarApi = {
  /** `{url, webcalUrl}` of the private feed, both null without one. */
  address: () => http.get('/api/training/calendar').then(r => r.data),
  /** A new address: the previous one stops working. */
  regenerate: () => http.post('/api/training/calendar').then(r => r.data),
  revoke: () => http.delete('/api/training/calendar'),
  /** One repeated session as an .ics text. */
  planIcs: id =>
    http
      .get(`/api/training/plans/${encodeURIComponent(id)}/calendar.ics`, {
        responseType: 'text',
        transformResponse: [data => data],
        headers: { Accept: 'text/calendar' }
      })
      .then(r => r.data)
}

/** Opening repertoires (docs/REPERTOIRE.md). Changes answer a delta (RepertoireChange). */
export const repertoireApi = {
  list: () => http.get('/api/repertoires', JSON_LD).then(r => r.data.member),
  /** @param {{name: string, color: 'white'|'black'}} payload */
  create: payload =>
    http.post('/api/repertoires', payload, JSON_LD).then(r => r.data),
  rename: (id, name) =>
    http
      .post(
        `/api/repertoires/${encodeURIComponent(id)}/rename`,
        { name },
        JSON_LD
      )
      .then(r => r.data),
  /** Deletes the repertoire for good, with its statistics. */
  remove: id =>
    http
      .delete(`/api/repertoires/${encodeURIComponent(id)}`, JSON_LD)
      .then(r => r.data),
  graph: id =>
    http
      .get(`/api/repertoires/${encodeURIComponent(id)}/graph`, JSON_LD)
      .then(r => r.data),
  /**
   * @param {string} id
   * @param {{fromPositionId: string, uci: string, baseVersion?: number}} payload
   */
  addMove: (id, payload) =>
    http
      .post(
        `/api/repertoires/${encodeURIComponent(id)}/moves`,
        payload,
        JSON_LD
      )
      .then(r => r.data),
  /**
   * Another prepared move in place of the user's move (the former one goes to the trash).
   *
   * @param {string} id
   * @param {string} moveId
   * @param {{uci: string, baseVersion?: number}} payload
   */
  replaceMove: (id, moveId, payload) =>
    http
      .post(
        `/api/repertoires/${encodeURIComponent(id)}/moves/${encodeURIComponent(moveId)}/replace`,
        payload,
        JSON_LD
      )
      .then(r => r.data),
  /**
   * promote or delete a move.
   *
   * @param {string} id
   * @param {string} moveId
   * @param {'promote'|'delete'} action
   * @param {{baseVersion?: number}} payload
   */
  moveAction: (id, moveId, action, payload = {}) =>
    http
      .post(
        `/api/repertoires/${encodeURIComponent(id)}/moves/${encodeURIComponent(moveId)}/${action}`,
        payload,
        JSON_LD
      )
      .then(r => r.data),
  /**
   * @param {string} id
   * @param {string} moveId
   * @param {{comment: string|null, nags: number[], baseVersion?: number}} payload
   */
  annotate: (id, moveId, payload) =>
    http
      .post(
        `/api/repertoires/${encodeURIComponent(id)}/moves/${encodeURIComponent(moveId)}/annotation`,
        payload,
        JSON_LD
      )
      .then(r => r.data),
  /** @param {string} id @param {{baseVersion?: number}} payload */
  undo: (id, payload = {}) =>
    http
      .post(`/api/repertoires/${encodeURIComponent(id)}/undo`, payload, JSON_LD)
      .then(r => r.data),
  /** @param {string} id suites of the trash, newest first */
  trash: id =>
    http
      .get(`/api/repertoires/${encodeURIComponent(id)}/trash`, JSON_LD)
      .then(r => r.data.suites),
  /**
   * What restoring a suite would do with these choices.
   *
   * @param {string} id
   * @param {string} trashId
   * @param {Record<string, 'restored'|'current'>} choices normalized FEN => choice
   */
  trashPreview: (id, trashId, choices = {}) =>
    http
      .get(
        `/api/repertoires/${encodeURIComponent(id)}/trash/${encodeURIComponent(trashId)}`,
        { ...JSON_LD, params: { choices } }
      )
      .then(r => r.data),
  /**
   * @param {string} id
   * @param {string} trashId
   * @param {{choices: Record<string, 'restored'|'current'>, baseVersion?: number}} payload
   */
  restore: (id, trashId, payload) =>
    http
      .post(
        `/api/repertoires/${encodeURIComponent(id)}/trash/${encodeURIComponent(trashId)}/restore`,
        payload,
        JSON_LD
      )
      .then(r => r.data),
  /** Removes a suite from the trash for good. */
  discard: (id, trashId) =>
    http
      .delete(
        `/api/repertoires/${encodeURIComponent(id)}/trash/${encodeURIComponent(trashId)}`,
        JSON_LD
      )
      .then(r => r.data),
  /**
   * The repertoire as a file: PGN, or an OpenBook backup (JSON). Its text and the file name the
   * API gives.
   *
   * @param {string} id
   * @param {'pgn'|'openbook'} [format]
   * @returns {Promise<{text: string, fileName: string}>}
   */
  exportFile: (id, format = 'pgn') =>
    http
      .get(`/api/repertoires/${encodeURIComponent(id)}/export`, {
        responseType: 'text',
        // The text as sent: a JSON body is not parsed.
        transformResponse: [data => data],
        params: format === 'pgn' ? {} : { format },
        headers: {
          Accept:
            format === 'pgn' ? 'application/x-chess-pgn' : 'application/json'
        }
      })
      .then(r => ({
        text: r.data,
        fileName:
          /filename="?([^";]+)"?/.exec(
            r.headers?.['content-disposition'] ?? ''
          )?.[1] ?? (format === 'pgn' ? 'repertoire.pgn' : 'repertoire.json')
      })),
  /**
   * Starts an import: a PGN text (with its file name) or a Lichess study URL.
   *
   * @param {{pgn?: string, fileName?: string|null, studyUrl?: string}} payload
   */
  createImport: payload =>
    http.post('/api/repertoires/imports', payload, JSON_LD).then(r => r.data),
  /**
   * The import, with its preview against a destination once analysed.
   *
   * @param {string} id
   * @param {{repertoireId?: string, color?: 'white'|'black', choices?: Record<string, string>}} destination
   */
  getImport: (id, destination = {}) =>
    http
      .get(`/api/repertoires/imports/${encodeURIComponent(id)}`, {
        ...JSON_LD,
        params: destination
      })
      .then(r => r.data),
  /**
   * @param {string} id
   * @param {{repertoireId?: string, baseVersion?: number, name?: string, color?: string, choices: Record<string, string>}} payload
   */
  applyImport: (id, payload) =>
    http
      .post(
        `/api/repertoires/imports/${encodeURIComponent(id)}/apply`,
        payload,
        JSON_LD
      )
      .then(r => r.data),
  /**
   * Lichess opening explorer, through the API (which holds the token and caches the answers).
   *
   * @param {'masters'|'lichess'} source
   * @param {string} fen
   * @param {{speeds?: string[], ratings?: number[]}} filters lichess only
   * @param {AbortSignal} [signal]
   */
  explorer: (source, fen, filters = {}, signal) =>
    http
      .get(`/api/repertoires/explorer/${encodeURIComponent(source)}`, {
        ...JSON_LD,
        signal,
        params: {
          fen,
          ...(source === 'lichess' && filters.speeds?.length
            ? { speeds: filters.speeds.join(',') }
            : {}),
          ...(source === 'lichess' && filters.ratings?.length
            ? { ratings: filters.ratings.join(',') }
            : {})
        }
      })
      .then(r => r.data),
  /**
   * Lichess cloud evaluation (found: false when Lichess has none).
   *
   * @param {string} fen
   * @param {number} lines 1 to 5
   * @param {AbortSignal} [signal]
   */
  cloudEval: (fen, lines = 3, signal) =>
    http
      .get('/api/repertoires/cloud-eval', {
        ...JSON_LD,
        signal,
        params: { fen, lines }
      })
      .then(r => r.data),
  /** The user's repertoires at a glance: cards, tests, 7-day forecast (docs/REPERTOIRE.md § 15). */
  overview: () => http.get('/api/repertoires/stats', JSON_LD).then(r => r.data),
  /** Statistics of one repertoire: cards, tests, segments, fragile segments. */
  stats: id =>
    http
      .get(`/api/repertoires/${encodeURIComponent(id)}/stats`, JSON_LD)
      .then(r => r.data),
  /** A segment's last presentations (retries and merged segments included). */
  segmentHistory: (id, segmentId) =>
    http
      .get(
        `/api/repertoires/${encodeURIComponent(id)}/segments/${encodeURIComponent(segmentId)}`,
        JSON_LD
      )
      .then(r => r.data),
  /** What a timed repertoire test presented, unit by unit. */
  runReport: runId =>
    http
      .get(`/api/repertoires/runs/${encodeURIComponent(runId)}`, JSON_LD)
      .then(r => r.data)
}

/** Dashboard reads (docs/DASHBOARD.md): the signed-in user's data only. */
export const dashboardApi = {
  /** Exercises per local day over the last `days` days, all-time totals per exercise type. */
  activity: (days = 84) =>
    http
      .get('/api/dashboard/activity', { ...JSON_LD, params: { days } })
      .then(r => r.data),
  /** Puzzle rating, one point per local day with a change. */
  ratingHistory: (days = 90) =>
    http
      .get('/api/dashboard/rating-history', { ...JSON_LD, params: { days } })
      .then(r => r.data),
  /** Blitz / rapid / classical ratings of the linked Lichess account (503 when Lichess cannot answer). */
  lichessRatingHistory: (days = 90) =>
    http
      .get('/api/dashboard/lichess-rating-history', {
        ...JSON_LD,
        params: { days }
      })
      .then(r => r.data),
  /** Training time by local week and module, totals by module, sessions of the period. */
  training: (days = 30) =>
    http
      .get('/api/dashboard/training', { ...JSON_LD, params: { days } })
      .then(r => r.data),
  /** Strong and weak themes of the rated puzzles of the period. */
  themes: (days = 30) =>
    http
      .get('/api/dashboard/themes', { ...JSON_LD, params: { days } })
      .then(r => r.data),
  /** Cards due, tests of the period and fragile segments of every repertoire. */
  repertoire: (days = 30) =>
    http
      .get('/api/dashboard/repertoire', { ...JSON_LD, params: { days } })
      .then(r => r.data)
}

/** Gamification (docs/GAMIFICATION.md): XP, level, streaks, trophies, the weekly quest. */
export const gamificationApi = {
  /** XP, level, rank, module levels, streaks, today's exercise XP. */
  summary: () =>
    http.get('/api/gamification/summary', JSON_LD).then(r => r.data),
  /** Every trophy, won (with the date of the feat) or not (with its progress). */
  trophies: () =>
    http
      .get('/api/gamification/trophies', JSON_LD)
      .then(r => r.data.trophies),
  /** The quest of the week (drawn on its first read). */
  quest: () => http.get('/api/gamification/quest', JSON_LD).then(r => r.data)
}


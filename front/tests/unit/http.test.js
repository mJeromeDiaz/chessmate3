import { describe, expect, it, vi } from 'vitest'
import { createHttpClient } from '@/services/http'

/**
 * A fake API as an axios adapter: `handler(config)` returns [status, data]. Records every request
 * with the Authorization header it carried.
 */
function fakeServer(handler) {
  const calls = []
  const adapter = async config => {
    const auth = config.headers.get
      ? config.headers.get('Authorization')
      : config.headers.Authorization
    calls.push({ url: config.url, auth: auth ?? null })
    const [status, data] = await handler(config, calls.length)
    const response = {
      status,
      data,
      headers: {},
      config,
      statusText: String(status)
    }

    if (status >= 400) {
      const error = new Error(`HTTP ${status}`)
      error.config = config
      error.response = response
      error.isAxiosError = true
      throw error
    }
    return response
  }
  return { adapter, calls }
}

/** A server accepting only `validToken`. */
function tokenCheckingServer(state) {
  return fakeServer(config => {
    const auth = config.headers.get('Authorization')
    return auth === `Bearer ${state.validToken}`
      ? [200, { ok: config.url }]
      : [401, {}]
  })
}

function setup({ refresh, server, initialToken = 'old' }) {
  const state = { token: initialToken }
  const handlers = {
    getAccessToken: () => state.token,
    refresh: vi.fn(async () => {
      const token = await refresh()
      state.token = token
      return token
    }),
    onAuthFailure: vi.fn(() => {
      state.token = null
    })
  }
  const client = createHttpClient(handlers, { adapter: server.adapter })
  return { client, handlers, state }
}

describe('createHttpClient', () => {
  it('sends the in-memory access token as a Bearer header', async () => {
    const server = fakeServer(() => [200, {}])
    const { client } = setup({ refresh: async () => 'new', server })

    await client.get('/api/profile')

    expect(server.calls[0].auth).toBe('Bearer old')
  })

  it('sends no Authorization header without a token', async () => {
    const server = fakeServer(() => [200, {}])
    const { client } = setup({
      refresh: async () => 'new',
      server,
      initialToken: null
    })

    await client.get('/api/public')

    expect(server.calls[0].auth).toBeNull()
  })

  it('refreshes once on a 401 and replays the request with the new token', async () => {
    const state = { validToken: 'new' }
    const server = tokenCheckingServer(state)
    const { client, handlers } = setup({ refresh: async () => 'new', server })

    const response = await client.get('/api/profile')

    expect(response.data).toEqual({ ok: '/api/profile' })
    expect(handlers.refresh).toHaveBeenCalledTimes(1)
    expect(server.calls.map(c => c.auth)).toEqual(['Bearer old', 'Bearer new'])
  })

  it('shares a single refresh between concurrent 401s and replays them all', async () => {
    const state = { validToken: 'new' }
    const server = tokenCheckingServer(state)
    let release
    const gate = new Promise(resolve => (release = resolve))
    const { client, handlers } = setup({
      refresh: async () => {
        await gate
        return 'new'
      },
      server
    })

    const requests = [client.get('/a'), client.get('/b'), client.get('/c')]
    await vi.waitFor(() => expect(handlers.refresh).toHaveBeenCalledTimes(1))
    release()
    const responses = await Promise.all(requests)

    expect(responses.map(r => r.data.ok)).toEqual(['/a', '/b', '/c'])
    expect(handlers.refresh).toHaveBeenCalledTimes(1)
  })

  it('queues a request started while a refresh is running, then sends it with the new token', async () => {
    const state = { validToken: 'new' }
    const server = tokenCheckingServer(state)
    let release
    const gate = new Promise(resolve => (release = resolve))
    const { client, handlers } = setup({
      refresh: async () => {
        await gate
        return 'new'
      },
      server
    })

    const first = client.get('/a')
    await vi.waitFor(() => expect(handlers.refresh).toHaveBeenCalledTimes(1))
    const second = client.get('/b')
    release()
    await Promise.all([first, second])

    // /b never went out with the stale token.
    expect(server.calls.filter(c => c.url === '/b').map(c => c.auth)).toEqual([
      'Bearer new'
    ])
  })

  it('rejects every waiting request and signs out once when the refresh fails', async () => {
    const server = fakeServer(() => [401, {}])
    const { client, handlers } = setup({
      refresh: async () => {
        throw new Error('refresh rejected')
      },
      server
    })

    const results = await Promise.allSettled([
      client.get('/a'),
      client.get('/b')
    ])

    expect(results.every(r => r.status === 'rejected')).toBe(true)
    expect(handlers.refresh).toHaveBeenCalledTimes(1)
    expect(handlers.onAuthFailure).toHaveBeenCalledTimes(1)
  })

  it('does not loop when the replayed request is refused again', async () => {
    const server = fakeServer(() => [401, {}])
    const { client, handlers } = setup({ refresh: async () => 'new', server })

    await expect(client.get('/api/profile')).rejects.toMatchObject({
      response: { status: 401 }
    })

    expect(handlers.refresh).toHaveBeenCalledTimes(1)
    expect(server.calls).toHaveLength(2)
  })

  it('never refreshes for requests marked skipAuthRefresh (login, refresh itself...)', async () => {
    const server = fakeServer(() => [401, {}])
    const { client, handlers } = setup({ refresh: async () => 'new', server })

    await expect(
      client.post('/api/auth/login', {}, { skipAuthRefresh: true })
    ).rejects.toMatchObject({
      response: { status: 401 }
    })

    expect(handlers.refresh).not.toHaveBeenCalled()
    expect(handlers.onAuthFailure).not.toHaveBeenCalled()
  })

  it('leaves other errors alone', async () => {
    const server = fakeServer(() => [403, {}])
    const { client, handlers } = setup({ refresh: async () => 'new', server })

    await expect(client.get('/x')).rejects.toMatchObject({
      response: { status: 403 }
    })
    expect(handlers.refresh).not.toHaveBeenCalled()
  })

  it('sends cookies (withCredentials) so the refresh cookie reaches the API', () => {
    const { client } = setup({
      refresh: async () => 'new',
      server: fakeServer(() => [200, {}])
    })

    expect(client.defaults.withCredentials).toBe(true)
  })
})

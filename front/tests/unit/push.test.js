import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/services/api', () => ({ notificationApi: {} }))

const { keyBytes, pushState } = await import('@/utils/push')

/**
 * A browser with service workers: permission and the current subscription.
 *
 * @param {NotificationPermission} permission
 * @param {{endpoint: string}|null} subscription
 */
function browser(permission, subscription) {
  vi.stubGlobal('Notification', { permission })
  vi.stubGlobal('navigator', {
    serviceWorker: {
      register: vi.fn().mockResolvedValue({
        pushManager: {
          getSubscription: vi.fn().mockResolvedValue(subscription)
        }
      })
    }
  })
}

const api = (config, subscribed = false) => ({
  pushConfig: vi.fn().mockResolvedValue(config),
  status: vi.fn().mockResolvedValue(subscribed)
})

describe('Web Push', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('turns the base64url VAPID key into bytes', () => {
    expect([...keyBytes('AQID_-8')]).toEqual([1, 2, 3, 255, 239])
  })

  it('tells where this browser stands', async () => {
    const supported = () => true
    const on = { enabled: true, publicKey: 'k' }

    expect(await pushState({ supported: () => false, api: api(on) })).toBe(
      'unsupported'
    )
    expect(
      await pushState({
        supported,
        api: api({ enabled: false, publicKey: null })
      })
    ).toBe('unavailable')

    browser('denied', null)
    expect(await pushState({ supported, api: api(on) })).toBe('denied')

    browser('default', null)
    expect(await pushState({ supported, api: api(on) })).toBe('unsubscribed')

    browser('granted', { endpoint: 'https://fcm.googleapis.com/x' })
    const known = api(on, true)
    expect(await pushState({ supported, api: known })).toBe('subscribed')
    expect(known.status).toHaveBeenCalledWith('https://fcm.googleapis.com/x')

    // Subscribed for another account signed in on this browser before.
    expect(await pushState({ supported, api: api(on, false) })).toBe(
      'unsubscribed'
    )
  })
})

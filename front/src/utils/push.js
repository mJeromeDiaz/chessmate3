import { notificationApi } from '@/services/api'

/** The service worker that shows Web Push notifications (front/public/push-sw.js). */
export const PUSH_WORKER = `${import.meta.env.BASE_URL ?? '/'}push-sw.js`

/**
 * Where this browser stands with Web Push:
 * - `unsupported`: no service worker or Push API (an iPhone needs the app on its home screen);
 * - `unavailable`: the server sends none (no VAPID keys);
 * - `denied`: the user blocked notifications for the site;
 * - `subscribed` / `unsubscribed`: for the account signed in.
 *
 * @typedef {'unsupported'|'unavailable'|'denied'|'subscribed'|'unsubscribed'} PushState
 */

/** @returns {boolean} */
export function pushSupported() {
  return (
    typeof window !== 'undefined' &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window
  )
}

/**
 * The VAPID public key (base64url) as the bytes `pushManager.subscribe` takes.
 *
 * @param {string} base64url
 * @returns {Uint8Array}
 */
export function keyBytes(base64url) {
  const padded = base64url + '='.repeat((4 - (base64url.length % 4)) % 4)
  const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(binary, c => c.charCodeAt(0))
}

/** @returns {Promise<ServiceWorkerRegistration>} */
function registration() {
  return navigator.serviceWorker.register(PUSH_WORKER)
}

/**
 * @param {object} [deps] injected in tests
 * @returns {Promise<PushState>}
 */
export async function pushState(deps = {}) {
  const { api = notificationApi, supported = pushSupported } = deps
  if (!supported()) return 'unsupported'
  const config = await api.pushConfig()
  if (!config.enabled) return 'unavailable'
  if (Notification.permission === 'denied') return 'denied'
  const existing = await (await registration()).pushManager.getSubscription()
  if (!existing) return 'unsubscribed'
  return (await api.status(existing.endpoint)) ? 'subscribed' : 'unsubscribed'
}

/**
 * Asks for the permission (call it from a click), subscribes this browser and tells the server.
 *
 * @returns {Promise<PushState>}
 */
export async function subscribePush() {
  if (!pushSupported()) return 'unsupported'
  const config = await notificationApi.pushConfig()
  if (!config.enabled || !config.publicKey) return 'unavailable'
  const permission = await Notification.requestPermission()
  if (permission !== 'granted')
    return permission === 'denied' ? 'denied' : 'unsubscribed'
  const reg = await registration()
  let subscription = await reg.pushManager.getSubscription()
  if (!subscription) {
    subscription = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: keyBytes(config.publicKey)
    })
  }
  const json = subscription.toJSON()
  await notificationApi.subscribe({
    endpoint: subscription.endpoint,
    keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' }
  })
  return 'subscribed'
}

/**
 * Stops the notifications on this browser (for every account: the subscription is the browser's).
 *
 * @returns {Promise<PushState>}
 */
export async function unsubscribePush() {
  if (!pushSupported()) return 'unsupported'
  const subscription = await (
    await registration()
  ).pushManager.getSubscription()
  if (subscription) {
    await notificationApi.unsubscribe(subscription.endpoint).catch(() => {})
    await subscription.unsubscribe()
  }
  return 'unsubscribed'
}

/** @type {Record<PushState, string>} */
export const PUSH_STATE_TEXT = {
  unsupported:
    'Ce navigateur ne reçoit pas de notifications (sur iPhone, ajoute d’abord ChessMate à l’écran d’accueil).',
  unavailable:
    'Les notifications du navigateur ne sont pas disponibles pour le moment.',
  denied:
    'Les notifications sont bloquées pour ce site : autorise-les dans les réglages du navigateur.',
  subscribed: 'Activées sur cet appareil.',
  unsubscribed: 'Désactivées sur cet appareil.'
}

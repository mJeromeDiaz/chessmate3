/** The alert played before the end of a session module and at the end of free study. */
export const ALERT_SOUND = `${import.meta.env.BASE_URL ?? '/'}media/son/alert.mp3`

/**
 * Plays the alert sound. Silent failures: no file, autoplay refused (no user gesture yet).
 *
 * @param {string} [src]
 */
export function playAlert(src = ALERT_SOUND) {
  try {
    const audio = new Audio(src)
    audio.play()?.catch(() => {})
  } catch {
    // No audio support.
  }
}

/** @returns {boolean} whether this browser can show notifications */
export function notificationsSupported() {
  return typeof window !== 'undefined' && 'Notification' in window
}

/**
 * Asks for the permission to notify, once (call it from a click: browsers require a gesture).
 *
 * @returns {Promise<NotificationPermission|'unsupported'>}
 */
export async function requestNotifications() {
  if (!notificationsSupported()) return 'unsupported'
  if (Notification.permission !== 'default') return Notification.permission
  try {
    return await Notification.requestPermission()
  } catch {
    return Notification.permission
  }
}

/**
 * Shows a browser notification when allowed (nothing otherwise).
 *
 * @param {string} title
 * @param {string} body
 */
export function notify(title, body) {
  if (!notificationsSupported() || Notification.permission !== 'granted') return
  try {
    new Notification(title, { body, tag: 'chessmate-run' })
  } catch {
    // Some mobile browsers only notify through a service worker.
  }
}

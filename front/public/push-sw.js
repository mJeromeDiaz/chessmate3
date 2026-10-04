/*
 * ChessMate's service worker, for Web Push only (docs/NOTIFICATIONS.md): shows the notifications
 * the server sends (session reminders) and opens the app on click. No cache, no offline mode.
 */

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', event =>
  event.waitUntil(self.clients.claim())
)

self.addEventListener('push', event => {
  /** @type {{title?: string, body?: string, url?: string, tag?: string}} */
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' }
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'ChessMate', {
      body: data.body || '',
      tag: data.tag || 'chessmate',
      icon: new URL('icons/favicon-128x128.png', self.registration.scope).href,
      data: { url: new URL(data.url || '/', self.registration.scope).href }
    })
  )
})

self.addEventListener('notificationclick', event => {
  event.notification.close()
  const url = event.notification.data?.url || self.registration.scope
  event.waitUntil(
    self.clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then(windows => {
        const open = windows.find(w =>
          w.url.startsWith(self.registration.scope)
        )
        if (open) {
          return open.navigate(url).then(w => (w ?? open).focus())
        }
        return self.clients.openWindow(url)
      })
  )
})

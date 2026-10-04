/*
 * Lex PH service worker: makes the app installable, keeps it opening on a
 * weak signal, and shows phone notifications.
 *
 * - The app shell and the hashed build assets are cached.
 * - A few read-only screens a lawyer needs in court (today's hearings,
 *   deadlines, tasks, the matter opened) are answered from the network,
 *   and from the last saved copy when there is no connection. The copy is
 *   marked so the page can say how old it is. Nothing else is stored, and
 *   saved copies are deleted when the user signs out.
 * - Push messages become notifications; tapping one opens its page.
 */
const VERSION = 'v1'
const SHELL = `lexph-shell-${VERSION}`
const ASSETS = `lexph-assets-${VERSION}`
const API = `lexph-api-${VERSION}`

const API_SAVED = [
  /^\/api\/v1\/auth\/me$/,
  /^\/api\/v1\/lookups$/,
  /^\/api\/v1\/court-day(\?|$)/,
  /^\/api\/v1\/deadlines(\?|$)/,
  /^\/api\/v1\/tasks(\?|$)/,
  /^\/api\/v1\/matters\/\d+$/,
  /^\/api\/v1\/matters\/options(\?|$)/,
  /^\/api\/v1\/notifications$/,
  // The open matter's lists a lawyer checks in court.
  /^\/api\/v1\/(time-entries|expenses|documents)\?(.*&)?matter_id=\d+/,
  /^\/api\/v1\/matters\/\d+\/(files|contacts|emails|exhibits)$/,
]

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(['/', '/manifest.webmanifest', '/icon-192.png'])).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('lexph-') && ![SHELL, ASSETS, API].includes(k)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  // Signed out (or a different user signed in): forget saved screens.
  if (event.data?.type === 'clear-saved') event.waitUntil(caches.delete(API))
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request, SHELL, '/'))
  } else if (url.pathname.startsWith('/assets/')) {
    event.respondWith(cacheFirst(request, ASSETS))
  } else if (API_SAVED.some((pattern) => pattern.test(url.pathname + url.search))) {
    event.respondWith(networkFirst(request, API))
  }
})

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request)
  if (cached) return cached
  const response = await fetch(request)
  if (response.ok) (await caches.open(cacheName)).put(request, response.clone())
  return response
}

/** The network when it answers (within a few seconds), else the last saved copy. */
async function networkFirst(request, cacheName, fallbackKey) {
  const cache = await caches.open(cacheName)
  try {
    const response = await withTimeout(fetch(request), 6000)
    if (response.ok) cache.put(fallbackKey ?? request, response.clone())
    return response
  } catch (error) {
    const cached = await cache.match(fallbackKey ?? request)
    if (!cached) throw error
    if (cacheName !== API) return cached
    // Tell the page this is a saved copy, and from when.
    const headers = new Headers(cached.headers)
    headers.set('X-Lexph-Saved-Copy', cached.headers.get('date') ?? 'unknown')
    return new Response(await cached.blob(), { status: cached.status, headers })
  }
}

function withTimeout(promise, ms) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout')), ms)
    promise.then((v) => { clearTimeout(timer); resolve(v) }, (e) => { clearTimeout(timer); reject(e) })
  })
}

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: event.data?.text() }
  }
  event.waitUntil(self.registration.showNotification(data.title || 'Lex PH', {
    body: data.body || undefined,
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    tag: data.kind || undefined,
    renotify: Boolean(data.kind),
    data: { url: data.url || '/' },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || '/', self.location.origin).href
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    const open = windows.find((w) => new URL(w.url).origin === self.location.origin)
    if (open) {
      await open.focus()
      return open.navigate(target)
    }
    return self.clients.openWindow(target)
  })())
})

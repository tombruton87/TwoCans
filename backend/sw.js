/*
 * twocans service worker — what makes "Add to Home Screen" an app, and shows
 * the notifications twocans sends (Web Push — see backend/src/WebPush.php).
 *
 * Deliberately modest. Pages are always fetched from the box, never from a
 * cache: they show who is allowed to call the children, and a stale copy of
 * that is worse than none. Only the look of the app — CSS, JS, fonts, icons —
 * is kept, so it opens quickly, and when the box can't be reached at all a
 * small "can't reach your line" page is shown instead of the browser's error.
 */
const VERSION = 'twocans-v2';
const OFFLINE = '/assets/pwa/offline.html';
const SHELL = [OFFLINE, '/assets/pwa/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Pages: always from the box; the offline page only when it can't be reached.
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
    return;
  }

  // The app's look: straight from the cache, refreshed in the background.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.open(VERSION).then((cache) =>
        cache.match(request).then((cached) => {
          const fresh = fetch(request).then((response) => {
            if (response.ok) cache.put(request, response.clone());
            return response;
          }).catch(() => cached);
          return cached || fresh;
        })
      )
    );
  }
  // Everything else — audio, downloads, the app's own requests — straight through.
});

/* A notification from twocans: what happened, and the page it's about.
   Decrypted by the browser before it gets here. An emergency stays on screen
   until it's dealt with. */
self.addEventListener('push', (event) => {
  let message = { title: 'twocans', body: 'Something needs a look.', url: '/' };
  try {
    if (event.data) message = Object.assign(message, event.data.json());
  } catch (e) { /* show the plain one */ }
  event.waitUntil(self.registration.showNotification(message.title, {
    body: message.body,
    icon: '/assets/pwa/icon-192.png',
    badge: '/assets/pwa/icon-192.png',
    tag: message.tag || 'twocans',
    renotify: true,
    requireInteraction: !!message.urgent,
    data: { url: message.url || '/' },
  }));
});

/* Tapped: the page it's about, in the app if it's open, else a new window. */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          return client.navigate(target).then((c) => (c || client).focus());
        }
      }
      return self.clients.openWindow(target);
    })
  );
});


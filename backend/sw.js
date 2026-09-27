/*
 * twocans service worker — what makes "Add to Home Screen" an app.
 *
 * Deliberately modest. Pages are always fetched from the box, never from a
 * cache: they show who is allowed to call the children, and a stale copy of
 * that is worse than none. Only the look of the app — CSS, JS, fonts, icons —
 * is kept, so it opens quickly, and when the box can't be reached at all a
 * small "can't reach your line" page is shown instead of the browser's error.
 */
const VERSION = 'twocans-v1';
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

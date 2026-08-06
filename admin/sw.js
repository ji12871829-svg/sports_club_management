/* Apex Athletic Club — Admin Console Service Worker
 *
 * Deliberately conservative:
 *  - Admin pages carry CSRF tokens, session data and live figures, so they are
 *    NEVER served from cache. Navigations are network-first, with an offline
 *    fallback page only when the network is genuinely unreachable.
 *  - Only a tiny set of static admin assets (manifest, icons, offline page)
 *    are precached so the installed app can boot its shell offline.
 *  - Scope is ./ (the /admin/ directory) — public pages are untouched.
 */

var CACHE_NAME = 'apex-admin-v1';
var PRECACHE_URLS = [
  './offline.html',
  './manifest.json',
  './favicon.png',
  './apple-touch-icon.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      // Precache each item independently so a single transient failure
      // never blocks SW installation entirely.
      return Promise.all(PRECACHE_URLS.map(function (url) {
        return cache.add(url).catch(function () { /* best-effort */ });
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== CACHE_NAME; })
            .map(function (k) { return caches.delete(k); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return; // never touch POSTs (CSRF/session)
  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return; // CDNs pass through

  // Navigations: network-first, offline fallback.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(function () {
        return caches.match('./offline.html');
      })
    );
    return;
  }

  // Static admin assets: cache-first, populate cache on first hit.
  if (/\.(png|svg|jpg|jpeg|gif|webp|css|js|woff2?|json)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then(function (cached) {
        if (cached) return cached;
        return fetch(request).then(function (response) {
          if (response && response.status === 200) {
            var copy = response.clone();
            caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
          }
          return response;
        });
      })
    );
  }
  // Everything else: default network behavior.
});

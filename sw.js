/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * Service Worker - በስልክና በኮምፒውተር ከመስመር ውጭ እንዲሰራ (Offline-First Cache)
 */

const CACHE_NAME = 'teklu-food-cache-v8';
// NOTE: do NOT precache anything under ./icons/ — those URLs 404 live
// (Debian Apache's /icons/ Alias shadows the app's own icons dir), and
// cache.addAll() is atomic: one 404 failed the whole install, so the
// worker never activated and an offline reload showed Chrome's
// "no internet" page. Only assets verified to return 200 live are listed.
const ASSETS_TO_CACHE = [
  './',
  './index.html',
  './manifest.json',
  './css/app.css',
  './js/i18n.js',
  './js/db.js',
  './js/api.js',
  './js/app.js',
  './img/logo.svg'
];

// 1. መጫን እና ፋይሎችን መሸጎጥ (Install Event)
// Resilient install: cache.addAll() is atomic — a single 404 (or any
// other failed asset) rejects the whole install and the worker is
// discarded, so the app cannot open offline at all. Cache each asset
// on its own instead: one failure is logged and skipped, the rest
// still install and the worker activates.
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return Promise.all(
        ASSETS_TO_CACHE.map((url) =>
          cache.add(url).catch((err) => {
            console.warn('[SW] precache skipped (not cached):', url, err);
          })
        )
      );
    }).then(() => self.skipWaiting())
  );
});

// 2. አሮጌ መሸጎጫዎችን ማጽዳት (Activate Event)
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((name) => {
          if (name !== CACHE_NAME) {
            return caches.delete(name);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// 3. ጥያቄዎችን ማስተናገድ (Fetch Event: Stale-While-Revalidate for Assets, Network-First for API)
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // 1. API ጥያቄዎች
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(event.request).catch(() => {
        // ከመስመር ውጭ ሲሆን የተዘጋጀ JSON መልስ
        return new Response(JSON.stringify({
          success: false,
          offline: true,
          error: 'ኔትወርክ የለም (Offline Mode): ክንውኑ በአካባቢው ዳታቤዝ ይከናወናል'
        }), {
          headers: { 'Content-Type': 'application/json; charset=utf-8' }
        });
      })
    );
    return;
  }

  // 2. ቋሚ ፋይሎች (Assets: Cache First, fallback to Network with Cache update)
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        // በስተጀርባ አዲሱ ፋይል ካለ አድስ
        fetch(event.request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200) {
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, networkResponse));
          }
        }).catch(() => {/* ከመስመር ውጭ ቢሆን ችግር የለውም */});
        return cachedResponse;
      }

      // መሸጎጫ ውስጥ ካልተገኘ ከኔትወርክ አምጣና ሸጉጠው
      return fetch(event.request).then((networkResponse) => {
        if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
          return networkResponse;
        }
        const responseToCache = networkResponse.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, responseToCache);
        });
        return networkResponse;
      }).catch(() => {
        // ገጹ ከመስመር ውጭ ሲከፈት እና ምንም ኔትወርክ ከሌለ ወደ index.html ምራው
        if (event.request.mode === 'navigate') {
          return caches.match('./index.html');
        }
      });
    })
  );
});

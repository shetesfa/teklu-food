/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * Service Worker - በስልክና በኮምፒውተር ከመስመር ውጭ እንዲሰራ (Offline-First Cache)
 */

const CACHE_NAME = 'teklu-food-cache-v6';
const ASSETS_TO_CACHE = [
  './',
  './index.html',
  './manifest.json',
  './css/app.css',
  './js/i18n.js',
  './js/db.js',
  './js/api.js',
  './js/app.js',
  './icons/icon.svg',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/apple-touch-icon.png',
  './icons/favicon-32.png'
];

// 1. መጫን እና ፋይሎችን መሸጎጥ (Install Event)
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE);
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

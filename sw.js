/**
 * ተክሉ ጌታቸው ቢዝነስ - Service Worker
 * ከመስመር ውጭ እንዲሰራ እና ፋይሎችን እንዲሸጎጥ (Offline-First Cache)
 */

const CACHE_NAME = 'teklu-food-cache-v2';
const ASSETS_TO_CACHE = [
  './',
  './index.html',
  './manifest.json',
  './css/app.css',
  './js/i18n.js',
  './js/db.js',
  './js/api.js',
  './js/app.js',
  './icons/icon.svg'
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

// 3. ጥያቄዎችን ማስተናገድ (Fetch Event: Cache First for assets, Network first for APIs)
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // API ጥያቄዎች በ Network እንዲሄዱ ማድረግ
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(event.request).catch(() => {
        // ከመስመር ውጭ ሲሆን የተዘጋጀ JSON መልስ
        return new Response(JSON.stringify({
          success: false,
          offline: true,
          error: 'ኔትወርክ የለም (Offline Mode): ክንውኑ በአካባቢው ዳታቤዝ ተይዟል'
        }), {
          headers: { 'Content-Type': 'application/json; charset=utf-8' }
        });
      })
    );
    return;
  }

  // ቋሚ ፋይሎች (Assets): Cache First, fallback to Network
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }
      return fetch(event.request).then((networkResponse) => {
        if (!networkResponse || networkResponse.status !== 200 || networkResponse.type !== 'basic') {
          return networkResponse;
        }
        const responseToCache = networkResponse.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, responseToCache);
        });
        return networkResponse;
      });
    })
  );
});

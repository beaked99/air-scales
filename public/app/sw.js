// public/app/sw.js

// 📦 Versioned cache name — bump this when you change what's cached
const CACHE_NAME = 'air-scales-cache-v0.02';

// 📁 Files to cache for offline usage
const FILES_TO_CACHE = [
  '/app/',
  '/app/index.html',
  '/app/sw.js',
  '/app/manifest.webmanifest',
  '/app/icon-192.png',
  '/app/icon-512.png',
  '/app/favicon.ico'
];


// 📥 Install event: pre-cache all essential files
self.addEventListener('install', event => {
  console.log('[Service Worker] Install');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      console.log('[Service Worker] Pre-caching offline resources');
      return cache.addAll(FILES_TO_CACHE);
    })
  );
  self.skipWaiting(); // 👈 Activate this SW immediately
});

// 🔄 Activate event: delete any old caches
self.addEventListener('activate', event => {
  console.log('[Service Worker] Activate');
  event.waitUntil(
    caches.keys().then(keyList =>
      Promise.all(
        keyList.map(key => {
          if (key !== CACHE_NAME) {
            console.log('[Service Worker] Removing old cache:', key);
            return caches.delete(key);
          }
        })
      )
    )
  );
  self.clients.claim(); // 👈 Start controlling all clients immediately
});

// 🌐 Fetch handler: use cache-first strategy with offline fallback
self.addEventListener('fetch', event => {
  event.respondWith(
    fetch(event.request)
      .then(response => {
        return response; // ✅ Online? Just return fresh response
      })
      .catch(() => {
        // ❌ Offline? Try the cache
        return caches.match(event.request).then(response => {
          if (response) {
            return response; // 🧠 Serve from cache if available
          }

          // 👇 Fallback to offline page for navigations
          if (event.request.mode === 'navigate') {
            return caches.match('/app/index.html');
          }
        });
      })
  );
});

// public/app/sw.js

const CACHE_NAME = 'air-scales-cache-v0.05';

const FILES_TO_CACHE = [
  '/app/',
  '/app/index.html',
  '/app/sw.js',
  '/app/manifest.webmanifest',
  '/app/icon-192.png',
  '/app/icon-512.png',
  '/app/favicon.ico',
  '/homepage/index.html',
  '/dashboard/index.html'
];

// Install
self.addEventListener('install', event => {
  console.log('[Service Worker] Install');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      console.log('[Service Worker] Pre-caching offline resources');
      return cache.addAll(FILES_TO_CACHE);
    })
  );
  self.skipWaiting();
});

// Activate
self.addEventListener('activate', event => {
  console.log('[Service Worker] Activate');
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME) {
            console.log('[Service Worker] Removing old cache:', key);
            return caches.delete(key);
          }
        })
      )
    )
  );
  self.clients.claim();
});

// Fetch
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  const isAppFile = url.pathname.startsWith('/app/') || FILES_TO_CACHE.includes(url.pathname);

  if (isAppFile) {
    event.respondWith(
      caches.match(event.request).then(response => {
        if (response) {
          console.log('[SW] Cache hit:', url.pathname);
          return response;
        }

        return fetch(event.request).then(response => {
          if (!response || response.status !== 200) {
            return response;
          }

          const cloned = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, cloned));
          return response;
        }).catch(() => {
          if (event.request.mode === 'navigate') {
            const pathname = url.pathname;
            if (pathname.startsWith('/dashboard')) {
              return caches.match('/dashboard/index.html');
            } else if (pathname.startsWith('/homepage')) {
              return caches.match('/homepage/index.html');
            } else {
              return caches.match('/app/index.html');
            }
          }
        });
      })
    );
  } else {
    // NETWORK-FIRST fallback
    event.respondWith(
      fetch(event.request).catch(() =>
        caches.match(event.request).then(response => {
          if (response) return response;
          if (event.request.mode === 'navigate') {
            const pathname = url.pathname;
            if (pathname.startsWith('/dashboard')) {
              return caches.match('/dashboard/index.html');
            } else if (pathname.startsWith('/homepage')) {
              return caches.match('/homepage/index.html');
            } else {
              return caches.match('/app/index.html');
            }
          }
        })
      )
    );
  }
});

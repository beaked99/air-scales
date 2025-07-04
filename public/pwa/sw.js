const CACHE_NAME = 'airscales-cache-v1';
const urlsToCache = [
  '/pwa/index.html',
  '/pwa/styles.css',
  '/pwa/ws.js',
  '/pwa/icon-192.png',
  '/pwa/icon-512.png'
];

self.addEventListener('install', event => {
  console.log('[SW] Install');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  console.log('[SW] Activate');
  event.waitUntil(
    caches.keys().then(keyList =>
      Promise.all(keyList.map(key => key !== CACHE_NAME && caches.delete(key)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request).then(response =>
      response || fetch(event.request).catch(() => caches.match('/pwa/index.html'))
    )
  );
});

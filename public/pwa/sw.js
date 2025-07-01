// /public/pwa/sw.js

self.addEventListener('install', event => {
  console.log('[Service Worker] Installed');
  self.skipWaiting(); // Activate worker immediately
});

self.addEventListener('activate', event => {
  console.log('[Service Worker] Activated');
  // Cleanup logic for old caches can go here later
});

self.addEventListener('fetch', event => {
  event.respondWith(fetch(event.request));
});

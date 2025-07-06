console.log('Service Worker: Loading...');

const CACHE_NAME = 'airscales-v1';

// Install - skip waiting to activate immediately
self.addEventListener('install', event => {
  console.log('Service Worker: Installing...');
  self.skipWaiting();
});

// Activate - claim clients immediately  
self.addEventListener('activate', event => {
  console.log('Service Worker: Activating...');
  event.waitUntil(clients.claim());
});

// Fetch - minimal handling
self.addEventListener('fetch', event => {
  // Just pass through, don't intercept for now
  console.log('Service Worker: Fetch request for:', event.request.url);
});

console.log('Service Worker: Script loaded successfully');
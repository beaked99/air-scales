// public/app/sw.js

// public/app/sw.js
let CACHE_NAME = 'air-scales-cache-v0.07'; // Set default version

// Only fetch version if we're not in the service worker install phase
if (typeof importScripts === 'function') {
    // We're in service worker context, fetch version
    fetch('/app/version.json')
        .then(response => {
            if (response.ok) {
                return response.json();
            }
            throw new Error('Version fetch failed');
        })
        .then(data => {
            CACHE_NAME = `air-scales-cache-v${data.version}`;
            console.log('[SW] Using dynamic cache name:', CACHE_NAME);
        })
        .catch(err => {
            console.warn('[SW] Failed to fetch version.json, using default cache name.', err);
        });
}

// Rest of your service worker code...

// 📋 Files to cache for offline usage
const FILES_TO_CACHE = [
  '/app/index.offline.html',  // Only cache the offline version
  '/app/sw.js',
  '/app/manifest.webmanifest',
  '/app/icon-192.png',
  '/app/icon-512.png',
  '/app/favicon.ico'
];

// 🔥 Install event: pre-cache all essential files
self.addEventListener('install', event => {
  console.log('[Service Worker] Install v0.06');
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      console.log('[Service Worker] Pre-caching offline resources');
      return cache.addAll(FILES_TO_CACHE).catch(error => {
        console.error('[Service Worker] Failed to cache files:', error);
        throw error;
      });
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

// 🌐 Fetch handler: CACHE-FIRST strategy for app files, network-first for API calls
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // Check if this is a request to your main app route
  const isAppRoute = url.pathname === '/app/' || url.pathname === '/app';
  const isStaticAppFile = url.pathname.startsWith('/app/') && 
    (url.pathname.endsWith('.offline.html') ||
     url.pathname.endsWith('.js') || 
     url.pathname.endsWith('.css') || 
     url.pathname.endsWith('.png') || 
     url.pathname.endsWith('.ico') || 
     url.pathname.endsWith('.webmanifest'));
  
  if (isAppRoute) {
    // 🎯 ALWAYS NETWORK-FIRST for main app route - NEVER cache this
    console.log('[Service Worker] Handling app route:', event.request.url);
    event.respondWith(
      fetch(event.request, { cache: 'no-store' }) // Force fresh request
        .then(response => {
          console.log('[Service Worker] Online - serving Symfony template, status:', response.status);
          if (!response.ok) {
            console.error('[Service Worker] Bad response from server:', response.status, response.statusText);
          }
          // DO NOT CACHE the main app route response
          return response;
        })
        .catch(error => {
          // ❌ Offline? Serve static offline page
          console.error('[Service Worker] Network error for app route:', error);
          console.log('[Service Worker] Offline - serving static offline page');
          return caches.match('/app/index.offline.html')
            .then(response => {
              if (response) {
                console.log('[Service Worker] Serving offline page from cache');
                return response;
              }
              console.error('[Service Worker] No offline page found in cache!');
              // If offline page not cached, create a basic fallback
              return new Response(`
                <!DOCTYPE html>
                <html><head><title>Offline</title></head>
                <body>
                <h1>You're offline</h1>
                <p>Connect to internet to use the app</p>
                <script>
                  console.log('Fallback offline page served');
                  setTimeout(() => window.location.reload(), 5000);
                </script>
                </body></html>
              `, {
                headers: { 'Content-Type': 'text/html' }
              });
            });
        })
    );
  } else if (isStaticAppFile) {
    // 🧠 CACHE-FIRST for static app files (CSS, JS, images)
    event.respondWith(
      caches.match(event.request).then(response => {
        if (response) {
          console.log('[Service Worker] Serving from cache:', event.request.url);
          return response; // ✅ Return cached version immediately
        }
        
        // Not in cache, try to fetch and cache it
        return fetch(event.request).then(response => {
          // Don't cache non-successful responses
          if (!response || response.status !== 200 || response.type !== 'basic') {
            return response;
          }
          
          // Clone the response before caching
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseToCache);
          });
          
          return response;
        }).catch(() => {
          // If it's a navigation request and we can't fetch, serve the offline page
          if (event.request.mode === 'navigate') {
            return caches.match('/app/index.offline.html');
          }
        });
      })
    );
  } else {
    // 🌐 NETWORK-FIRST for API calls and external resources
    event.respondWith(
      fetch(event.request)
        .then(response => {
          return response; // ✅ Online? Just return fresh response
        })
        .catch(() => {
          // ❌ Offline? Try the cache as fallback
          return caches.match(event.request).then(response => {
            if (response) {
              return response; // 🧠 Serve from cache if available
            }
            
            // 👇 Fallback to offline page for navigations
            if (event.request.mode === 'navigate') {
              return caches.match('/app/index.offline.html');
            }
          });
        })
    );
  }
});
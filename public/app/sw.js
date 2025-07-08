// public/app/sw.js

// 📦 Versioned cache name — bump this when you change what's cached
const CACHE_NAME = 'air-scales-cache-v0.05'; // Bump version to force update

// 📋 Files to cache for offline usage
const FILES_TO_CACHE = [
  '/app/',
  '/app/index.html',
  '/app/index.offline.html',  // Add offline version
  '/app/sw.js',
  '/app/manifest.webmanifest',
  '/app/icon-192.png',
  '/app/icon-512.png',
  '/app/favicon.ico'
];

// 🔥 Install event: pre-cache all essential files
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

// 🌐 Fetch handler: CACHE-FIRST strategy for app files, network-first for API calls
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // Check if this is a request to your main app route
  const isAppRoute = url.pathname === '/app/' || url.pathname === '/app';
  const isAppFile = url.pathname.startsWith('/app/') || FILES_TO_CACHE.includes(url.pathname);
  
  if (isAppRoute) {
    // 🎯 SPECIAL HANDLING for main app route - online/offline detection
    event.respondWith(
      fetch(event.request)
        .then(response => {
          console.log('[Service Worker] Online - serving Symfony template');
          return response; // ✅ Online? Serve Symfony's dynamic template
        })
        .catch(() => {
          // ❌ Offline? Serve static offline page
          console.log('[Service Worker] Offline - serving static offline page');
          return caches.match('/app/index.offline.html')
            .then(response => {
              if (response) {
                return response;
              }
              // Fallback to regular index if offline page not available
              return caches.match('/app/index.html');
            });
        })
    );
  } else if (isAppFile) {
    // 🧠 CACHE-FIRST for other app files (CSS, JS, images)
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
            return caches.match('/app/index.offline.html') || caches.match('/app/index.html');
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
              return caches.match('/app/index.offline.html') || caches.match('/app/index.html');
            }
          });
        })
    );
  }
});
const CACHE_NAME = 'ab-scales-v1';

// Install event
self.addEventListener('install', event => {
    console.log('Service Worker installing...');
    self.skipWaiting();
});

// Activate event
self.addEventListener('activate', event => {
    console.log('Service Worker activating...');
    event.waitUntil(self.clients.claim());
});

// Fetch event - with better filtering
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }
    
    // Skip chrome-extension, moz-extension, and other non-http(s) schemes
    if (!url.protocol.startsWith('http')) {
        return;
    }
    
    // Skip cross-origin requests (optional - keeps it simple)
    if (url.origin !== location.origin) {
        return;
    }
    
    event.respondWith(
        caches.match(request)
            .then(response => {
                if (response) {
                    return response;
                }
                
                return fetch(request).then(response => {
                    // Only cache successful responses
                    if (!response || response.status !== 200) {
                        return response;
                    }
                    
                    const responseToCache = response.clone();
                    
                    caches.open(CACHE_NAME)
                        .then(cache => {
                            cache.put(request, responseToCache);
                        })
                        .catch(err => {
                            console.log('Cache put failed:', err);
                        });
                    
                    return response;
                });
            })
    );
});
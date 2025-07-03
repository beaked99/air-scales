// /public/pwa/sw.js

const CACHE_NAME = 'air-scales-v1';
const ESP32_IP = '192.168.4.1';

// Cache essential files for offline use
const STATIC_ASSETS = [
  '/pwa/offline-dashboard.html',
  '/pwa/offline-dashboard.js',
  '/pwa/offline-dashboard.css',
  '/pwa/icon-192.png',
  '/pwa/icon-512.png'
];

self.addEventListener('install', event => {
  console.log('[SW] Installing...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  console.log('[SW] Activating...');
  event.waitUntil(
    caches.keys()
      .then(cacheNames => {
        return Promise.all(
          cacheNames.map(cacheName => {
            if (cacheName !== CACHE_NAME) {
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // If requesting dashboard and we're on ESP32 network, redirect to ESP32
  if (url.pathname === '/dashboard' && isESP32Network()) {
    event.respondWith(
      Response.redirect(`http://${ESP32_IP}/dashboard`, 302)
    );
    return;
  }
  
  // For ESP32 requests, pass through directly
  if (url.hostname === ESP32_IP) {
    event.respondWith(fetch(event.request));
    return;
  }
  
  // For other requests, try network first, then cache
  event.respondWith(
    fetch(event.request)
      .catch(() => {
        // If offline, serve cached offline dashboard for dashboard requests
        if (url.pathname === '/dashboard') {
          return caches.match('/pwa/offline-dashboard.html');
        }
        // For other requests, try cache
        return caches.match(event.request);
      })
  );
});

// Background sync for uploading cached data
self.addEventListener('sync', event => {
  if (event.tag === 'upload-sensor-data') {
    event.waitUntil(uploadCachedSensorData());
  }
});

function isESP32Network() {
  // Check if we're on ESP32 network by trying to detect the IP range
  // This is a best guess - you might need to refine this detection
  return navigator.onLine === false || 
         (typeof navigator !== 'undefined' && navigator.connection && 
          navigator.connection.effectiveType === 'none');
}

async function uploadCachedSensorData() {
  try {
    // Get cached sensor data from IndexedDB or localStorage
    const cachedData = await getCachedSensorData();
    
    if (cachedData && cachedData.length > 0) {
      for (const dataPoint of cachedData) {
        try {
          const response = await fetch('https://beaker.ca/api/microdata', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
            },
            body: JSON.stringify(dataPoint)
          });
          
          if (response.ok) {
            await removeCachedDataPoint(dataPoint.id);
            console.log('[SW] Uploaded cached sensor data:', dataPoint.id);
          }
        } catch (error) {
          console.log('[SW] Failed to upload cached data:', error);
        }
      }
    }
  } catch (error) {
    console.log('[SW] Error in background sync:', error);
  }
}

async function getCachedSensorData() {
  // Implementation depends on your storage choice
  // This is a placeholder - you'll need to implement based on your storage
  return [];
}

async function removeCachedDataPoint(id) {
  // Remove successfully uploaded data point
  // Implementation depends on your storage choice
}
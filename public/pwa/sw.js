// /public/pwa/sw.js - Revised for ESP32 Integration

const CACHE_NAME = 'air-scales-v1';
const BEAKER_API = 'https://beaker.ca/api';

// Cache essential files for offline use
const STATIC_ASSETS = [
  '/pwa/offline-dashboard.html',
  '/pwa/offline-dashboard.js',
  '/pwa/offline-dashboard.css',
  '/pwa/setup-instructions.html', // New setup page
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
  
  // Handle setup requests differently
  if (url.pathname.includes('/setup-device')) {
    // For setup, we need to exit PWA mode
    event.respondWith(
      Response.redirect('/setup-device?exit_pwa=true', 302)
    );
    return;
  }
  
  // Cache-first strategy for API requests to enable offline functionality
  if (url.origin === BEAKER_API || url.pathname.startsWith('/api/')) {
    event.respondWith(
      caches.match(event.request)
        .then(cachedResponse => {
          if (cachedResponse) {
            // Return cached response and update in background
            fetch(event.request).then(networkResponse => {
              if (networkResponse.ok) {
                caches.open(CACHE_NAME).then(cache => {
                  cache.put(event.request, networkResponse.clone());
                });
              }
            });
            return cachedResponse;
          }
          
          // If not cached, fetch from network
          return fetch(event.request).then(networkResponse => {
            if (networkResponse.ok) {
              caches.open(CACHE_NAME).then(cache => {
                cache.put(event.request, networkResponse.clone());
              });
            }
            return networkResponse;
          });
        })
        .catch(() => {
          // If completely offline, return cached offline response
          return caches.match('/pwa/offline-dashboard.html');
        })
    );
    return;
  }
  
  // For other requests, try network first, then cache
  event.respondWith(
    fetch(event.request)
      .catch(() => {
        // If offline, serve cached content
        if (url.pathname === '/dashboard') {
          return caches.match('/pwa/offline-dashboard.html');
        }
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

// Handle push notifications from ESP32 devices
self.addEventListener('push', event => {
  if (event.data) {
    const data = event.data.json();
    
    // Store sensor data for offline sync
    event.waitUntil(
      storeSensorDataForSync(data)
        .then(() => {
          // Show notification if needed
          if (data.alert) {
            return self.registration.showNotification('Air Scales Alert', {
              body: data.message,
              icon: '/pwa/icon-192.png',
              badge: '/pwa/icon-192.png'
            });
          }
        })
    );
  }
});

// Handle notification clicks
self.addEventListener('notificationclick', event => {
  event.notification.close();
  
  event.waitUntil(
    clients.openWindow('/dashboard')
  );
});

// Message handler for communication with main app
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  
  if (event.data && event.data.type === 'SYNC_SENSOR_DATA') {
    // Trigger background sync
    self.registration.sync.register('upload-sensor-data');
  }
});

async function uploadCachedSensorData() {
  try {
    const cachedData = await getCachedSensorData();
    
    if (cachedData && cachedData.length > 0) {
      for (const dataPoint of cachedData) {
        try {
          const response = await fetch(`${BEAKER_API}/microdata`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${await getAuthToken()}`
            },
            body: JSON.stringify(dataPoint)
          });
          
          if (response.ok) {
            await removeCachedDataPoint(dataPoint.id);
            console.log('[SW] Uploaded cached sensor data:', dataPoint.id);
          }
        } catch (error) {
          console.log('[SW] Failed to upload cached data:', error);
          // Keep data for next sync attempt
        }
      }
    }
  } catch (error) {
    console.log('[SW] Error in background sync:', error);
  }
}

async function getCachedSensorData() {
  // Get data from IndexedDB
  return new Promise((resolve) => {
    const request = indexedDB.open('air-scales-data', 1);
    
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction(['sensor-data'], 'readonly');
      const store = transaction.objectStore('sensor-data');
      const getAllRequest = store.getAll();
      
      getAllRequest.onsuccess = () => {
        resolve(getAllRequest.result);
      };
    };
    
    request.onerror = () => {
      resolve([]);
    };
  });
}

async function removeCachedDataPoint(id) {
  return new Promise((resolve) => {
    const request = indexedDB.open('air-scales-data', 1);
    
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction(['sensor-data'], 'readwrite');
      const store = transaction.objectStore('sensor-data');
      const deleteRequest = store.delete(id);
      
      deleteRequest.onsuccess = () => {
        resolve();
      };
    };
    
    request.onerror = () => {
      resolve(); // Don't fail the sync if we can't delete
    };
  });
}

async function storeSensorDataForSync(data) {
  return new Promise((resolve) => {
    const request = indexedDB.open('air-scales-data', 1);
    
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('sensor-data')) {
        db.createObjectStore('sensor-data', { keyPath: 'id' });
      }
    };
    
    request.onsuccess = () => {
      const db = request.result;
      const transaction = db.transaction(['sensor-data'], 'readwrite');
      const store = transaction.objectStore('sensor-data');
      
      const dataWithId = {
        ...data,
        id: Date.now() + Math.random(),
        timestamp: new Date().toISOString()
      };
      
      store.add(dataWithId);
      resolve();
    };
    
    request.onerror = () => {
      resolve(); // Don't fail if we can't store
    };
  });
}

async function getAuthToken() {
  // Get auth token from your Symfony session or storage
  // This is a placeholder - implement based on your auth system
  return localStorage.getItem('auth_token') || '';
}
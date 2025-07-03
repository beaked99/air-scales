// /public/pwa/sw.js - Clean PWA Service Worker

const CACHE_NAME = 'air-scales-v1';

// What to cache for offline use
const STATIC_FILES = [
  '/dashboard',           // Main dashboard page
  '/pwa/offline.html',    // Offline fallback page  
  '/pwa/icon-192.png',
  '/pwa/icon-512.png'
];

// Install: Cache essential files
self.addEventListener('install', event => {
  console.log('[SW] Installing...');
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(STATIC_FILES))
      .then(() => self.skipWaiting())
  );
});

// Activate: Clean old caches
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

// Fetch: Handle requests
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  
  // Handle dashboard requests
  if (url.pathname === '/dashboard') {
    event.respondWith(handleDashboardRequest(event.request));
    return;
  }
  
  // Handle other requests
  event.respondWith(
    fetch(event.request)
      .catch(() => {
        // If network fails, try cache
        return caches.match(event.request);
      })
  );
});

async function handleDashboardRequest(request) {
  try {
    // Try network first
    const response = await fetch(request);
    
    if (response.ok) {
      // Cache successful response
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
      return response;
    }
    
    throw new Error('Network response not ok');
    
  } catch (error) {
    console.log('[SW] Network failed, serving cached dashboard');
    
    // Network failed - serve cached version
    const cachedResponse = await caches.match('/dashboard');
    
    if (cachedResponse) {
      return cachedResponse;
    }
    
    // No cache available - serve offline page
    return caches.match('/pwa/offline.html') || new Response(
      getOfflineHTML(),
      { headers: { 'Content-Type': 'text/html' } }
    );
  }
}

// Background sync for data upload
self.addEventListener('sync', event => {
  if (event.tag === 'upload-data') {
    event.waitUntil(uploadCachedData());
  }
});

async function uploadCachedData() {
  try {
    // Get cached sensor data from IndexedDB
    const data = await getCachedSensorData();
    
    for (const item of data) {
      try {
        const response = await fetch('https://beaker.ca/api/microdata', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(item)
        });
        
        if (response.ok) {
          await markDataAsSynced(item.id);
          console.log('[SW] Synced data:', item.id);
        }
      } catch (error) {
        console.log('[SW] Sync failed for:', item.id);
      }
    }
  } catch (error) {
    console.log('[SW] Background sync error:', error);
  }
}

// Store sensor data for offline sync
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'CACHE_SENSOR_DATA') {
    cacheSensorData(event.data.data);
  }
});

function cacheSensorData(data) {
  // Store in IndexedDB for later sync
  const request = indexedDB.open('air-scales', 1);
  
  request.onupgradeneeded = () => {
    const db = request.result;
    if (!db.objectStoreNames.contains('sensor-data')) {
      db.createObjectStore('sensor-data', { keyPath: 'id' });
    }
  };
  
  request.onsuccess = () => {
    const db = request.result;
    const tx = db.transaction(['sensor-data'], 'readwrite');
    const store = tx.objectStore('sensor-data');
    
    store.add({
      ...data,
      id: Date.now() + Math.random(),
      timestamp: new Date().toISOString(),
      synced: false
    });
  };
}

async function getCachedSensorData() {
  return new Promise((resolve) => {
    const request = indexedDB.open('air-scales', 1);
    
    request.onsuccess = () => {
      const db = request.result;
      const tx = db.transaction(['sensor-data'], 'readonly');
      const store = tx.objectStore('sensor-data');
      const getAll = store.getAll();
      
      getAll.onsuccess = () => {
        const unsynced = getAll.result.filter(item => !item.synced);
        resolve(unsynced);
      };
    };
    
    request.onerror = () => resolve([]);
  });
}

async function markDataAsSynced(id) {
  const request = indexedDB.open('air-scales', 1);
  
  request.onsuccess = () => {
    const db = request.result;
    const tx = db.transaction(['sensor-data'], 'readwrite');
    const store = tx.objectStore('sensor-data');
    
    const getRequest = store.get(id);
    getRequest.onsuccess = () => {
      const data = getRequest.result;
      if (data) {
        data.synced = true;
        store.put(data);
      }
    };
  };
}

function getOfflineHTML() {
  return `
<!DOCTYPE html>
<html>
<head>
    <title>Air Scales - Offline</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial; background: #0f172a; color: #e2e8f0; padding: 40px; text-align: center; }
        h1 { color: #22c55e; }
        .status { background: #1e293b; padding: 20px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
    <h1>🏷️ Air Scales</h1>
    <div class="status">
        <h2>📴 Working Offline</h2>
        <p>Connect to ESP32 AP network to view live sensor data</p>
        <button onclick="location.reload()" style="background: #22c55e; color: #1a1a1a; padding: 10px 20px; border: none; border-radius: 4px;">
            Retry Connection
        </button>
    </div>
</body>
</html>
  `;
}
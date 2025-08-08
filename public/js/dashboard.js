// Dashboard Live Updates + Bluetooth Device Discovery (Turbo-safe)
// File: public/js/dashboard.js

/****************************
 * Config & globals
 ****************************/
const CONFIG = {
  updateIntervalMs: 5000,
  debug: true,
  maxRetries: 3,
  retryDelay: 2000,
};

let API_URL = null;
let retryCount = 0;
let updateInterval = null;

// Turbo boot guard so we don't re-init on every navigation
let dashBooted = false;

// Simple BLE state holder
const BLE = {
  device: null,
  server: null,
  isConnected() {
    try {
      return !!this.device && !!this.device.gatt && this.device.gatt.connected;
    } catch {
      return false;
    }
  },
};

// =============== TRANSFERRED FROM OLD WORKING CODE ===============

// Unified device data management (from old code)
let allDeviceData = new Map(); // Combined data from all sources
let dataSourcePriority = {
    'bluetooth': 3,    // Highest priority (most current)
    'websocket': 2,    // Medium priority
    'server': 1        // Lowest priority (cached data)
};

// BLE Configuration (from old code)
const BLE_SERVICE_UUID = '12345678-1234-1234-1234-123456789abc';
const BLE_SENSOR_CHAR_UUID = '87654321-4321-4321-4321-cba987654321';
const BLE_COEFFS_CHAR_UUID = '11111111-2222-3333-4444-555555555555';

// BLE State Management (from old code)
let bleDevices = new Map(); // Connected BLE devices
let dbInstance = null;
let lastServerSync = 0;

/****************************
 * API URL init (Twig calls this)
 ****************************/
function initializeApiUrl(url) {
  API_URL = url;
  if (CONFIG.debug) console.log('API URL set to:', API_URL);
}
window.initializeApiUrl = initializeApiUrl;

/****************************
 * Live data fetching
 ****************************/
async function fetchLiveData() {
  if (!API_URL) {
    console.error('API URL not set');
    return null;
  }

  try {
    if (CONFIG.debug) console.log(`Fetching live data from: ${API_URL}`);

    const response = await fetch(API_URL, {
      method: 'GET',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error('HTTP Error:', {
        status: response.status,
        statusText: response.statusText,
        body: errorText,
      });
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const data = await response.json();
    retryCount = 0;

    if (CONFIG.debug) {
      console.log('✅ Live data received:', data);
      console.log(`📊 Total weight: ${data.total_weight} lbs, Devices: ${data.devices.length}`);
    }

    updateDashboardWithLiveData(data);
    return data;
  } catch (error) {
    retryCount++;
    console.error(`❌ API Error (attempt ${retryCount}/${CONFIG.maxRetries}):`, error.message);
    if (retryCount < CONFIG.maxRetries) {
      setTimeout(fetchLiveData, CONFIG.retryDelay);
    }
    return null;
  }
}

function updateDashboardWithLiveData(data) {
  if (CONFIG.debug) console.log('🔄 Updating dashboard DOM with live data...');
  data.devices.forEach(updateDeviceDisplay);
  updateTotalWeight(data.total_weight, data.device_count);
  if (CONFIG.debug) console.log('✅ Dashboard DOM updated successfully');
}

function updateDeviceDisplay(device) {
  const deviceElement = document.querySelector(`[data-device-id="${device.device_id}"]`);
  if (!deviceElement) {
    if (CONFIG.debug) console.warn(`Device element not found for ID: ${device.device_id}`);
    return;
  }

  if (CONFIG.debug) {
    console.log(`🔧 Updating device ${device.device_name}:`, {
      status: device.status,
      weight: device.weight,
      lastSeen: device.last_seen,
    });
  }

  updateConnectionStatusDot(deviceElement, device.status);
  updateLastSeenText(deviceElement, device.last_seen, device.status);
  updateWeightAndPressure(deviceElement, device);
}

function updateConnectionStatusDot(deviceElement, status) {
  const dotElement = deviceElement.parentElement.querySelector('.w-3.h-3.rounded-full');
  if (!dotElement) return;

  dotElement.classList.remove(
    'bg-green-400',
    'animate-pulse',
    'bg-orange-400',
    'bg-red-500',
    'bg-purple-500',
    'bg-gray-500',
  );

  switch (status) {
    case 'online':
      dotElement.classList.add('bg-green-400', 'animate-pulse');
      break;
    case 'recent':
      dotElement.classList.add('bg-orange-400');
      break;
    case 'offline':
    default:
      dotElement.classList.add('bg-red-500');
      break;
  }

  if (CONFIG.debug) console.log(`🔴 Updated status dot for device to: ${status}`);
}

function updateLastSeenText(deviceElement, lastSeen, status) {
  const textElement = deviceElement.querySelector('.text-sm');
  if (!textElement) return;

  textElement.textContent = lastSeen;
  textElement.classList.remove('text-green-400', 'text-orange-400', 'text-red-500', 'text-purple-400', 'text-gray-500');

  switch (status) {
    case 'online':
      textElement.classList.add('text-green-400');
      break;
    case 'recent':
      textElement.classList.add('text-orange-400');
      break;
    case 'offline':
      textElement.classList.add('text-red-500');
      break;
    case 'old':
    default:
      textElement.classList.add('text-gray-500');
      break;
  }
  if (CONFIG.debug) console.log(`📝 Updated last seen text: "${lastSeen}" with status: ${status}`);
}

function updateWeightAndPressure(deviceElement, device) {
  const deviceContainer = deviceElement.closest('.flex.items-center.justify-between.p-4');
  if (!deviceContainer) return;

  const weightEl = deviceContainer.querySelector('.text-right .font-bold');
  const psiEl = deviceContainer.querySelector('.text-right .text-sm.text-gray-400');

  if (weightEl) {
    weightEl.textContent = `${Math.round(device.weight).toLocaleString()} lbs`;
    if (CONFIG.debug) console.log(`⚖️ Updated weight: ${device.weight} lbs`);
  }
  if (psiEl) {
    const psi = Number(device.main_air_pressure);
    psiEl.textContent = `${isFinite(psi) ? psi.toFixed(1) : '--'} psi`;
    if (CONFIG.debug) console.log(`🫁 Updated pressure: ${psi} psi`);
  }
}

function updateTotalWeight(totalWeight, deviceCount) {
  const totalWeightElement = document.querySelector('.text-5xl.font-bold.text-white');
  if (totalWeightElement) {
    totalWeightElement.textContent = Math.round(totalWeight).toLocaleString();
    if (CONFIG.debug) console.log(`🏋️ Updated total weight: ${totalWeight} lbs from ${deviceCount} devices`);
  }
}

/****************************
 * Update cycle control
 ****************************/
function startUpdates() {
  if (updateInterval) return; // guard against double start
  if (CONFIG.debug) console.log(`🚀 Starting live updates every ${CONFIG.updateIntervalMs / 1000} seconds`);
  fetchLiveData();
  updateInterval = setInterval(fetchLiveData, CONFIG.updateIntervalMs);
}

function stopUpdates() {
  if (updateInterval) {
    clearInterval(updateInterval);
    updateInterval = null;
  }
  if (CONFIG.debug) console.log('⏹️ Stopped live updates');
}

/****************************
 * Bluetooth discovery / connect
 ****************************/
function initializeDeviceScanning() {
  const scanBtn = document.getElementById('scan-devices-btn');
  if (!scanBtn) return;

  // ensure we don't bind twice
  scanBtn.replaceWith(scanBtn.cloneNode(true));
  const freshBtn = document.getElementById('scan-devices-btn');
  freshBtn.addEventListener('click', scanForBluetoothDevices, { once: true });
  if (CONFIG.debug) console.log('🔍 Device scanning initialized');
}

async function scanForBluetoothDevices() {
  if (!navigator.bluetooth) {
    showBluetoothError('Bluetooth is not supported in this browser. Use Chrome/Edge.');
    return;
  }

  const scanBtn = document.getElementById('scan-devices-btn');
  const scanningIndicator = document.getElementById('scanning-indicator');
  const discoveredDevices = document.getElementById('discovered-devices');
  const noDevicesFound = document.getElementById('no-devices-found');
  const devicesList = document.getElementById('discovered-devices-list');

  try {
    scanBtn.disabled = true;
    scanBtn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> <span>Scanning...</span>';
    scanningIndicator.classList.remove('hidden');
    discoveredDevices.classList.add('hidden');
    noDevicesFound.classList.add('hidden');
    devicesList.innerHTML = '';

    // fallback-friendly single device selection (most reliable across browsers)
    const device = await navigator.bluetooth.requestDevice({
      filters: [{ namePrefix: 'AirScales' }, { namePrefix: 'AS25-' }, { namePrefix: 'ESP32' }],
      optionalServices: ['battery_service', '12345678-1234-1234-1234-1234567890ab'],
    });

    // display one result
    displayDiscoveredDevices([{ device, rssi: null }]);
  } catch (err) {
    if (err?.name === 'NotFoundError') {
      noDevicesFound.classList.remove('hidden');
    } else {
      showBluetoothError(`Bluetooth error: ${err.message}`);
    }
  } finally {
    scanningIndicator.classList.add('hidden');
    scanBtn.disabled = false;
    scanBtn.innerHTML = '<i class="fas fa-bluetooth-b"></i> <span>Scan for Devices</span>';
  }
}

function displayDiscoveredDevices(items) {
  const discoveredDevices = document.getElementById('discovered-devices');
  const devicesList = document.getElementById('discovered-devices-list');
  discoveredDevices.classList.remove('hidden');
  devicesList.innerHTML = '';

  items.forEach(({ device }) => {
    const card = document.createElement('div');
    card.className = 'p-3 transition-all border border-gray-600 rounded-lg bg-gray-600/20';
    card.innerHTML = `
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3">
          <div class="w-3 h-3 bg-gray-400 rounded-full"></div>
          <div>
            <div class="flex items-center gap-2">
              <span class="font-medium text-white">${device.name || 'Air Scales Device'}</span>
            </div>
            <div class="text-sm text-gray-300">Bluetooth ID: ${device.id}</div>
          </div>
        </div>
        <div class="flex flex-col items-end gap-2">
          <button class="connect-device-btn px-3 py-1 text-sm text-white transition-colors rounded bg-green-600 hover:bg-green-700">
            Connect
          </button>
        </div>
      </div>
    `;
    const btn = card.querySelector('.connect-device-btn');
    btn.addEventListener('click', (e) => connectToBLEDevice(device, null, e.currentTarget));
    devicesList.appendChild(card);
  });

  if (CONFIG.debug) console.log(`📱 Displayed ${items.length} device(s)`);
}

// =============== TRANSFERRED OLD WORKING BLE FUNCTIONS ===============

// BLE Functions (from old working code)
async function initDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('AirScalesDB', 1);
        
        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            dbInstance = request.result;
            resolve(dbInstance);
        };
        
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            
            if (!db.objectStoreNames.contains('sensorData')) {
                const store = db.createObjectStore('sensorData', { 
                    keyPath: 'id', 
                    autoIncrement: true 
                });
                store.createIndex('mac_address', 'mac_address', { unique: false });
                store.createIndex('timestamp', 'timestamp', { unique: false });
            }
            
            if (!db.objectStoreNames.contains('devices')) {
                const deviceStore = db.createObjectStore('devices', { 
                    keyPath: 'mac_address' 
                });
            }
        };
    });
}

function isBluetoothSupported() {
    return 'bluetooth' in navigator;
}

async function connectToBLEDevice(device) {
    try {
        console.log(`Connecting to ${device.name}...`);
        
        const server = await device.gatt.connect();
        const service = await server.getPrimaryService(BLE_SERVICE_UUID);
        const sensorCharacteristic = await service.getCharacteristic(BLE_SENSOR_CHAR_UUID);
        const coeffsCharacteristic = await service.getCharacteristic(BLE_COEFFS_CHAR_UUID);
        
        const deviceInfo = {
            device,
            server,
            sensorCharacteristic,
            coeffsCharacteristic,
            mac_address: device.id, // USE DEVICE.ID AS MAC (the working approach!)
            lastSeen: new Date(),
            dataCount: 0
        };
        
        bleDevices.set(device.id, deviceInfo);
        
        await sensorCharacteristic.startNotifications();
        sensorCharacteristic.addEventListener('characteristicvaluechanged', 
            (event) => handleBLEData(event, deviceInfo));
        
        device.addEventListener('gattserverdisconnected', 
            () => handleBLEDisconnection(device));
        
        console.log('BLE device connected:', device.name);
        showSuccessToast(`Connected to ${device.name}`);
        hideDiscoverySection();
        
        // Notify server of connection (from old working code)
        if (!deviceInfo.serverNotified) {
            deviceInfo.serverNotified = true;
            console.log('Notifying server with MAC:', deviceInfo.mac_address);
            notifyServerOfBLEConnection(deviceInfo.mac_address);
        }
        
    } catch (error) {
        console.error('BLE connection error:', error);
        showBluetoothError('Connection failed: ' + error.message);
    }
}

// TRANSFERRED: BLE Data Handler (from old working code)
function handleBLEData(event, deviceInfo) {
    const decoder = new TextDecoder();
    const jsonString = decoder.decode(event.target.value);
    
    try {
        const data = JSON.parse(jsonString);
        console.log('BLE data received:', data);
        
        deviceInfo.lastSeen = new Date();
        deviceInfo.dataCount++;
        
        // Check if this is aggregated mesh data
        if (data.role === 'master' && data.slave_devices) {
            console.log('Received aggregated mesh data from master device');
            handleMeshAggregatedData(data, deviceInfo);
        } else {
            // Single device data
            handleSingleDeviceData(data, deviceInfo);
        }
        
        bufferDataForSync(data);
        storeDataInDB(data);
        
    } catch (error) {
        console.error('Error parsing BLE data:', error);
    }
}

// TRANSFERRED: Mesh aggregated data handler (from old working code)
function handleMeshAggregatedData(data, deviceInfo) {
    // Add master device to unified data with ORIGINAL name (no changes!)
    const masterMac = data.mac_address;
    const originalName = deviceInfo.device.name;
    
    allDeviceData.set(masterMac, {
        mac_address: masterMac,
        device_name: originalName, // KEEP ORIGINAL NAME FOREVER
        main_air_pressure: data.master_device.main_air_pressure,
        temperature: data.master_device.temperature,
        weight: data.master_device.weight,
        source: 'bluetooth',
        last_updated: new Date(),
        priority: dataSourcePriority.bluetooth,
        mesh_role: 'master',
        device_count: data.device_count,
        total_weight: data.total_weight
    });
    
    // Add slave devices to unified data
    if (data.slave_devices && data.slave_devices.length > 0) {
        data.slave_devices.forEach(slave => {
            const existingSlave = allDeviceData.get(slave.mac_address);
            const originalSlaveName = existingSlave?.device_name || slave.device_name;
            
            allDeviceData.set(slave.mac_address, {
                mac_address: slave.mac_address,
                device_name: originalSlaveName, // KEEP ORIGINAL NAME
                main_air_pressure: slave.main_air_pressure,
                temperature: slave.temperature,
                weight: slave.weight,
                source: 'bluetooth_mesh',
                last_updated: new Date(),
                priority: dataSourcePriority.bluetooth,
                mesh_role: 'slave',
                last_seen_ms: slave.last_seen
            });
        });
    }
}

// TRANSFERRED: Single device data handler (from old working code)  
function handleSingleDeviceData(data, deviceInfo) {
    // ALWAYS use the original BLE device name - NEVER change it
    const originalName = deviceInfo.device.name;
    
    allDeviceData.set(data.mac_address, {
        ...data,
        device_name: originalName, // ORIGINAL BLE NAME ONLY
        source: 'bluetooth',
        last_updated: new Date(),
        priority: dataSourcePriority.bluetooth
    });
}

// TRANSFERRED: Server notification (from old working code)
async function notifyServerOfBLEConnection(macAddress) {
    const userId = getCurrentUserId();
    console.log('User ID:', userId);
    
    if (!userId) {
        console.error('No user ID available!');
        return;
    }
    console.log('Sending MAC to server:', macAddress);
    try {
        const response = await fetch('/api/bridge/connect', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                mac_address: macAddress,
                user_id: userId,
                device_name: BLE.device?.name || 'Unknown Device'
            })
        });
        console.log('Response status:', response.status);

        const responseText = await response.text();
        console.log('Raw response:', responseText);

        if (responseText.startsWith('{')) {
            const result = JSON.parse(responseText);
            console.log('Parsed JSON:', result);
            if (result.status === 'connected') {
                console.log('✅ Server notified of BLE connection successfully');
                // Refresh dashboard data
                setTimeout(() => fetchLiveData(), 1000);
            }
        } else {
            console.log('Response is HTML/text, not JSON');
        }
        
    } catch (error) {
        console.error('Error notifying server of BLE connection:', error);
    }
}

// TRANSFERRED: Data sync functions (from old working code)
function bufferDataForSync(data) {
    const now = Date.now();
    
    if (now - lastServerSync > 30000) {
        sendBLEDataToServer(data);
        lastServerSync = now;
    }
}

async function sendBLEDataToServer(data) {
    try {
        const response = await fetch('/api/bridge/data', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                ...data,
                source: 'bluetooth_pwa'
            })
        });
        
        if (response.ok) {
            const result = await response.json();
            console.log('BLE data sent to server:', result);
            
            if (result.regression_coefficients) {
                await sendCoefficientsViaBLE(data.mac_address, result.regression_coefficients);
            }
        } else {
            console.error('Server sync failed:', response.status);
        }
    } catch (error) {
        console.error('Server sync error:', error);
    }
}

async function sendCoefficientsViaBLE(macAddress, coefficients) {
    const deviceInfo = Array.from(bleDevices.values())
        .find(d => d.mac_address === macAddress);
    
    if (!deviceInfo || !deviceInfo.coeffsCharacteristic) return;
    
    try {
        const encoder = new TextEncoder();
        const data = encoder.encode(JSON.stringify(coefficients));
        
        await deviceInfo.coeffsCharacteristic.writeValue(data);
        console.log('Coefficients sent to ESP32:', coefficients);
    } catch (error) {
        console.error('Error sending coefficients:', error);
    }
}

async function storeDataInDB(data) {
    if (!dbInstance) return;
    
    const transaction = dbInstance.transaction(['sensorData'], 'readwrite');
    const store = transaction.objectStore('sensorData');
    
    const record = {
        ...data,
        timestamp: new Date().toISOString(),
        synced: false
    };
    
    await store.add(record);
}

function handleBLEDisconnection(device) {
    console.log('BLE device disconnected:', device.name);
    
    bleDevices.delete(device.id);
    
    // Remove from unified data
    allDeviceData.forEach((deviceInfo, mac) => {
        if (deviceInfo.source === 'bluetooth' && deviceInfo.device_name.includes(device.name)) {
            allDeviceData.delete(mac);
        }
    });
    
    showBluetoothError('Device disconnected');
}

// HELPER FUNCTION: Get current user ID
function getCurrentUserId() {
    return window.currentUserId;
}

// Initialize BLE integration (from old working code)
async function initBLEIntegration() {
    if (!isBluetoothSupported()) {
        console.log('Web Bluetooth not supported');
        return;
    }
    
    await initDB();
    console.log('BLE integration initialized');
}

/****************************
 * UI helpers
 ****************************/
function showBluetoothError(message) {
  const toast = document.createElement('div');
  toast.className = 'fixed z-50 p-4 text-white bg-red-600 rounded-lg shadow-lg top-4 right-4';
  toast.innerHTML = `<div class="flex items-center"><i class="fas fa-exclamation-triangle mr-2"></i><span>${message}</span></div>`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}

function showSuccessToast(message) {
  const toast = document.createElement('div');
  toast.className = 'fixed z-50 p-4 text-white bg-green-600 rounded-lg shadow-lg top-4 right-4';
  toast.innerHTML = `<div class="flex items-center"><i class="fas fa-check-circle mr-2"></i><span>${message}</span></div>`;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}

function hideDiscoverySection() {
  const discoverySection = document.querySelector('#discovered-devices');
  if (discoverySection) discoverySection.classList.add('hidden');

  const scanBtn = document.getElementById('scan-devices-btn');
  if (scanBtn) {
    scanBtn.innerHTML = '<i class="fas fa-check text-green-400"></i> <span>Device Connected</span>';
    scanBtn.disabled = true;
    scanBtn.className = 'flex items-center gap-2 px-4 py-2 text-white bg-green-600 rounded-lg';
    setTimeout(() => {
      scanBtn.innerHTML = '<i class="fas fa-bluetooth-b"></i> <span>Scan for Devices</span>';
      scanBtn.disabled = false;
      scanBtn.className =
        'flex items-center gap-2 px-4 py-2 text-white transition-colors rounded-lg bg-sky-600 hover:bg-sky-700';
    }, 5000);
  }
}

function guardDeviceLinksWhileConnected() {
  // Prevent navigation that would drop BLE when connected
  const links = document.querySelectorAll('a[href^="/device/"]');
  links.forEach((a) => {
    a.addEventListener('click', (e) => {
      if (BLE.isConnected()) {
        e.preventDefault();
        showBluetoothError("You're connected over Bluetooth. Disconnect first, or open device details in a new tab.");
      }
    });
  });
}

/****************************
 * Turbo lifecycle hooks
 ****************************/
document.addEventListener('turbo:load', () => {
  if (dashBooted) return; // prevent double boot
  dashBooted = true;

  if (CONFIG.debug) console.log('📄 Dashboard boot (turbo:load)');
  initializeDeviceScanning();
  initBLEIntegration(); // Initialize BLE from old working code

  // wait a tick for Twig to call initializeApiUrl()
  setTimeout(() => {
    if (API_URL) startUpdates();
    else console.error('❌ API URL not initialized');
  }, 100);
});

// Stop timers before Turbo snapshots the page
document.addEventListener('turbo:before-cache', () => {
  stopUpdates();
});

// Extra safety on unload/pagehide
window.addEventListener('pagehide', stopUpdates);
window.addEventListener('beforeunload', stopUpdates);

/****************************
 * Debug helpers
 ****************************/
window.DashboardDebug = {
  start: startUpdates,
  stop: stopUpdates,
  fetch: fetchLiveData,
  config: CONFIG,
  status: () => ({
    apiUrl: API_URL,
    intervalId: updateInterval,
    retryCount,
    bleConnected: BLE.isConnected(),
  }),
  testBluetooth: () => {
    console.log('Bluetooth supported:', !!navigator.bluetooth);
    if (navigator.bluetooth) scanForBluetoothDevices();
  },
};
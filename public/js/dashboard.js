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
    btn.addEventListener('click', (e) => connectToDevice(device, null, e.currentTarget));
    devicesList.appendChild(card);
  });

  if (CONFIG.debug) console.log(`📱 Displayed ${items.length} device(s)`);
}
async function connectToDevice(device, rssi, btnEl) {
  if (!btnEl) return;
  const connectBtn = btnEl;
  const originalHTML = connectBtn.innerHTML;

  try {
    // All the connection code goes here
    connectBtn.disabled = true;
    connectBtn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> Connecting...';

    console.log('🔗 Starting connection to device:', device.name);

    const server = await device.gatt.connect();
    BLE.device = device;
    BLE.server = server;
    
    console.log('✅ GATT connected successfully');

    device.addEventListener('gattserverdisconnected', () => {
      console.log('❌ Bluetooth disconnected');
      showBluetoothError('Bluetooth disconnected.');
      BLE.device = null;
      BLE.server = null;
    });

    let macAddress = device.id;
    if (device.name && device.name.includes('AirScales-')) {
      macAddress = device.name.replace('AirScales-', '');
    }
    console.log('📱 Using MAC address:', macAddress);

    const userId = window.currentUserId;
    console.log('👤 Using user ID:', userId);
    
    if (!userId) {
      throw new Error('No user ID available');
    }

    console.log('📡 Notifying server...');
    const response = await fetch('/api/bridge/connect', {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        mac_address: macAddress,
        user_id: userId,
        device_name: device.name
      })
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error('❌ Server error:', errorText);
      throw new Error(`Server responded with ${response.status}`);
    }

    const result = await response.json();
    console.log('✅ Server response:', result);

    if (result.status !== 'connected') {
      throw new Error(result.error || 'Connection failed');
    }

    connectBtn.innerHTML = '✓ Connected';
    showSuccessToast(`Connected to ${device.name}`);
    hideDiscoverySection();

    setTimeout(() => fetchLiveData(), 1000);

  } catch (err) {
    // Error handling code goes here
    console.error('❌ Connection failed:', err);
    
    if (BLE.device?.gatt?.connected) {
      BLE.device.gatt.disconnect();
    }
    BLE.device = null;
    BLE.server = null;
    
    connectBtn.innerHTML = 'Failed';
    showBluetoothError(`Connection failed: ${err.message}`);
    
    setTimeout(() => {
      connectBtn.disabled = false;
      connectBtn.innerHTML = originalHTML;
    }, 3000);
  }
}


async function getCurrentUserId() {
  try {
    const response = await fetch('/api/current-user', { method: 'GET', credentials: 'same-origin' });
    if (!response.ok) throw new Error('Not authenticated');
    const userData = await response.json();
    return userData.id;
  } catch (error) {
    console.error('Failed to get current user:', error);
    return null;
  }
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
        showBluetoothError('You’re connected over Bluetooth. Disconnect first, or open device details in a new tab.');
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

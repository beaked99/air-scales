// Dashboard Live Updates + Bluetooth Device Discovery
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
let dashBooted = false;

// Unified device data management
let allDeviceData = new Map();
let dataSourcePriority = {
  bluetooth: 3,
  websocket: 2,
  server: 1,
};

// BLE Configuration
const BLE_SERVICE_UUID = '12345678-1234-1234-1234-123456789abc';

// IndexedDB
let dbInstance = null;
let lastServerSync = 0;

// Server notification tracking - only notify once per session
let serverNotifiedForSession = false;

// Mesh device tracking (for separate BLE messages)
let hubDevice = null;
let meshDevices = new Map();

// Track which devices are getting live BLE data (skip server updates for these)
let bleConnectedMACs = new Set();

// Track last upload time per device (for throttling uploads)
let lastUploadTime = new Map(); // MAC address -> timestamp
const UPLOAD_THROTTLE_MS = 30000; // 30 seconds

// Mesh topology tracking for server registration
let hubMacAddress = null;
let connectedSlaves = new Set();
let lastMeshRegistration = 0;
const MESH_REGISTRATION_INTERVAL = 60000; // 60 seconds

// BLE listener cleanup
let bleListenerRemover = null;

/****************************
 * API URL init (Twig calls this)
 ****************************/
function initializeApiUrl(url) {
  API_URL = url;
  if (CONFIG.debug) console.log('API URL set to:', API_URL);
  if (dashBooted && !updateInterval) startUpdates();
}
window.initializeApiUrl = initializeApiUrl;

/****************************
 * Live data fetching from server
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
      console.log('✅ Live data received from server:', data);
      console.log(`📊 Server total weight: ${data.total_weight} lbs, Devices: ${data.devices.length}`);
    }

    updateDashboardWithServerData(data);
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

function updateDashboardWithServerData(data) {
  if (CONFIG.debug) console.log('🔄 Updating dashboard DOM with server data...');
  data.devices.forEach(updateDeviceDisplayFromServer);
  updateTotalWeightFromServer(data.total_weight, data.device_count);
  if (CONFIG.debug) console.log('✅ Dashboard DOM updated from server');
}

function updateDeviceDisplayFromServer(device) {
  // Skip if this device is getting live BLE data - BLE takes priority
  const deviceMac = device.mac_address?.toUpperCase();
  if (deviceMac && bleConnectedMACs.has(deviceMac)) {
    if (CONFIG.debug) console.log(`⏭️ Skipping server update for BLE device: ${deviceMac}`);
    return;
  }

  // Try to find by device ID first
  let deviceRow = document.querySelector(`[data-device-id="${device.device_id}"]`);
  if (deviceRow) {
    deviceRow = deviceRow.closest('.device-row') || deviceRow.closest('a');
  }

  // Also try by MAC address
  if (!deviceRow && deviceMac) {
    deviceRow = document.querySelector(`[data-mac-address="${deviceMac}"]`);
  }

  if (!deviceRow) {
    if (CONFIG.debug) console.warn(`Device element not found for ID: ${device.device_id}, MAC: ${deviceMac}`);
    return;
  }

  if (CONFIG.debug) {
    console.log(`🔧 Updating device ${device.device_name} from server:`, {
      status: device.status,
      weight: device.weight,
      lastSeen: device.last_seen,
    });
  }

  // Update status dot
  const statusDot = deviceRow.querySelector('.device-status-dot');
  if (statusDot) {
    statusDot.className = 'w-3 h-3 rounded-full device-status-dot';
    switch (device.status) {
      case 'online':
        statusDot.classList.add('bg-green-400', 'animate-pulse');
        break;
      case 'recent':
        statusDot.classList.add('bg-orange-400');
        break;
      case 'offline':
      default:
        statusDot.classList.add('bg-red-500');
        break;
    }
  }

  // Update status text
  const statusText = deviceRow.querySelector('.device-status-text');
  if (statusText) {
    statusText.textContent = device.last_seen;
    statusText.className = 'text-sm device-status-text';
    switch (device.status) {
      case 'online':
        statusText.classList.add('text-green-400');
        break;
      case 'recent':
        statusText.classList.add('text-orange-400');
        break;
      case 'offline':
      default:
        statusText.classList.add('text-red-500');
        break;
    }
  }

  // Update weight (with masking for free users)
  const weightEl = deviceRow.querySelector('.device-weight');
  if (weightEl) {
    const hasSubscription = window.hasActiveSubscription || false;
    weightEl.textContent = maskWeight(Math.round(device.weight), hasSubscription);
    weightEl.className = 'font-bold device-weight';
    if (device.status === 'online' || device.status === 'recent') {
      weightEl.classList.add('text-white');
    } else {
      weightEl.classList.add('text-gray-400');
    }
  }

  // Update pressure
  const pressureEl = deviceRow.querySelector('.device-pressure');
  if (pressureEl) {
    const psi = Number(device.main_air_pressure);
    pressureEl.textContent = `${isFinite(psi) ? psi.toFixed(1) : '--'} psi`;
  }

  // Update per-channel data if available
  if (device.channels && Array.isArray(device.channels)) {
    device.channels.forEach(channelData => {
      const channelPressureEl = deviceRow.querySelector(`.channel-pressure[data-channel="${channelData.channel_index}"]`);
      if (channelPressureEl) {
        const psi = Number(channelData.air_pressure);
        channelPressureEl.textContent = `${isFinite(psi) ? psi.toFixed(1) : '--'} psi`;
      }

      // Note: Weight per channel would be updated here if calibration data is available
      // const channelWeightEl = deviceRow.querySelector(`.channel-weight[data-channel="${channelData.channel_index}"]`);
    });
  }
}

function updateTotalWeightFromServer(totalWeight, deviceCount) {
  // Skip if we have active BLE connections - BLE data takes priority
  if (bleConnectedMACs.size > 0) {
    if (CONFIG.debug) console.log('⏭️ Skipping server total weight update - BLE active');
    return;
  }

  const totalWeightElement = document.getElementById('total-weight-value');
  if (totalWeightElement) {
    const hasSubscription = window.hasActiveSubscription || false;
    // For total weight, just show the number without "lbs" suffix
    const masked = maskWeight(Math.round(totalWeight), hasSubscription);
    totalWeightElement.textContent = masked.replace(' lbs', '');
    if (CONFIG.debug) console.log(`🏋️ Updated total weight from server: ${totalWeight} lbs from ${deviceCount} devices`);
  }
}

/****************************
 * Update cycle control
 ****************************/
function startUpdates() {
  if (updateInterval) return;
  if (CONFIG.debug) console.log(`🚀 Starting live updates every ${CONFIG.updateIntervalMs / 1000} seconds`);
  fetchLiveData();
  updateInterval = setInterval(fetchLiveData, CONFIG.updateIntervalMs);
}

function stopUpdates() {
  if (updateInterval) {
    clearInterval(updateInterval);
    updateInterval = null;
  }

  // Remove BLE listener when leaving page
  if (bleListenerRemover) {
    bleListenerRemover();
    bleListenerRemover = null;
    if (CONFIG.debug) console.log('🔄 Cleaned up BLE listener on page exit');
  }

  if (CONFIG.debug) console.log('⏹️ Stopped live updates');
}

/****************************
 * Bluetooth (Capacitor)
 ****************************/
function initializeDeviceScanning() {
  const scanBtn = document.getElementById('scan-devices-btn');
  if (!scanBtn) {
    if (CONFIG.debug) console.warn('[dashboard] Scan button not found');
    return false;
  }

  if (scanBtn.dataset.bound === '1') {
    if (CONFIG.debug) console.log('[dashboard] Scan button already bound');
    return true;
  }

  scanBtn.dataset.bound = '1';
  scanBtn.addEventListener('click', scanForBluetoothDevices);

  // Update button state if already connected
  if (window.AirScalesBLE && window.AirScalesBLE.isConnected()) {
    updateScanButtonConnected();
  }

  if (CONFIG.debug) console.log('🔍 Device scanning initialized');
  return true;
}

async function scanForBluetoothDevices() {
  // Check if Capacitor BLE is available
  if (!window.AirScalesBLE || !window.AirScalesBLE.isAvailable()) {
    showBluetoothError('Bluetooth is only available in the Air Scales app. Please download the app.');
    return;
  }

  const scanBtn = document.getElementById('scan-devices-btn');
  const scanningIndicator = document.getElementById('scanning-indicator');

  try {
    if (scanBtn) {
      scanBtn.disabled = true;
      scanBtn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> <span>Scanning...</span>';
    }
    if (scanningIndicator) scanningIndicator.classList.remove('hidden');

    // Use Capacitor BLE
    const device = await window.AirScalesBLE.scanAndConnect();

    console.log('✅ Connected to device:', device);
    showSuccessToast(`Connected to ${device.name || 'Air Scales Device'}`);
    updateScanButtonConnected();

  } catch (err) {
    if (err?.message?.includes('cancelled') || err?.message?.includes('canceled')) {
      console.log('Scan cancelled by user');
    } else {
      showBluetoothError(`Bluetooth error: ${err?.message || 'Unknown error'}`);
    }
  } finally {
    if (scanningIndicator) scanningIndicator.classList.add('hidden');
    if (scanBtn && (!window.AirScalesBLE || !window.AirScalesBLE.isConnected())) {
      scanBtn.disabled = false;
      scanBtn.innerHTML = '<i class="fas fa-bluetooth-b"></i> <span>Scan for Devices</span>';
    }
  }
}

function updateScanButtonConnected() {
  const scanBtn = document.getElementById('scan-devices-btn');
  if (!scanBtn) return;

  const savedDevice = window.AirScalesBLE?.getSavedDevice() || {};
  const deviceName = savedDevice.deviceName || 'Device';

  scanBtn.innerHTML = `<i class="fas fa-check text-green-400"></i> <span>Connected: ${deviceName}</span>`;
  scanBtn.disabled = false;
  scanBtn.className = 'flex items-center gap-2 px-4 py-2 text-white bg-green-600 rounded-lg hover:bg-green-700';

  // Change behavior to disconnect on click
  scanBtn.removeEventListener('click', scanForBluetoothDevices);
  scanBtn.addEventListener('click', handleDisconnectClick);
}

function updateScanButtonDisconnected() {
  const scanBtn = document.getElementById('scan-devices-btn');
  if (!scanBtn) return;

  scanBtn.innerHTML = '<i class="fas fa-bluetooth-b"></i> <span>Scan for Devices</span>';
  scanBtn.disabled = false;
  scanBtn.className = 'flex items-center gap-2 px-4 py-2 text-white transition-colors rounded-lg bg-sky-600 hover:bg-sky-700';

  scanBtn.removeEventListener('click', handleDisconnectClick);
  scanBtn.addEventListener('click', scanForBluetoothDevices);
}

async function handleDisconnectClick() {
  if (confirm('Disconnect from this device?')) {
    if (window.AirScalesBLE) {
      window.AirScalesBLE.forgetDevice();
    }
    updateScanButtonDisconnected();
    showSuccessToast('Device disconnected');

    // Clear BLE tracking
    hubDevice = null;
    meshDevices.clear();
    bleConnectedMACs.clear();
    hubMacAddress = null;
    connectedSlaves.clear();
    lastMeshRegistration = 0;

    // Force a server refresh to get current data
    fetchLiveData();
  }
}

/****************************
 * BLE Event Handling
 ****************************/
function setupBLEListeners() {
  if (!window.AirScalesBLE) {
    if (CONFIG.debug) console.log('⚠️ AirScalesBLE not available, skipping listener setup');
    return;
  }

  // Remove existing listener if any to prevent stacking listeners
  if (bleListenerRemover) {
    bleListenerRemover();
    if (CONFIG.debug) console.log('🔄 Removed existing BLE listener');
  }

  bleListenerRemover = window.AirScalesBLE.addListener((event, data) => {
    switch (event) {
      case 'connected':
        console.log('📱 BLE Connected:', data);
        updateScanButtonConnected();
        break;

      case 'disconnected':
        console.log('📱 BLE Disconnected:', data);
        updateScanButtonDisconnected();

        // Reset all BLE tracking
        serverNotifiedForSession = false;
        hubDevice = null;
        meshDevices.clear();
        bleConnectedMACs.clear();
        hubMacAddress = null;
        connectedSlaves.clear();
        lastMeshRegistration = 0;

        showBluetoothError('Device disconnected');

        // Resume server updates
        fetchLiveData();
        break;

      case 'discovered':
        // Device found during scan - feed to scanner modal
        console.log('📡 BLE Device discovered:', data);
        if (window.onBLEDeviceDiscovered && data.mac_address) {
          window.onBLEDeviceDiscovered({
            mac_address: data.mac_address,
            name: data.name || data.device_name || data.serial_number,
            rssi: data.rssi || null,
            role: data.role
          });
        }
        break;

      case 'data':
        handleBLEData(data);
        break;
    }
  });

  if (CONFIG.debug) console.log('✅ BLE listeners set up');
}

function handleBLEData(data) {
  if (!data) return;

  console.log('📡 BLE data received:', data);

  // Feed to device scanner if it's open
  if (window.onBLEDeviceDiscovered && data.mac_address) {
    window.onBLEDeviceDiscovered({
      mac_address: data.mac_address,
      name: data.device_name || data.serial_number,
      rssi: data.rssi || null,
      role: data.role
    });
  }

  // Feed to configuration page "Add Device" modal if it's open
  if (window.onBLEDeviceForConfig && data.mac_address) {
    window.onBLEDeviceForConfig({
      mac_address: data.mac_address,
      device_name: data.device_name || data.serial_number,
      rssi: data.rssi || null,
      role: data.role
    });
  }

  if (data.role === 'hub' || data.role === 'master') {
    // This is the hub/master device
    hubDevice = data;
    const mac = data.mac_address.toUpperCase();
    bleConnectedMACs.add(mac);

    // Track hub MAC for mesh registration
    if (!hubMacAddress) {
      hubMacAddress = mac;
      console.log(`📡 Hub identified: ${hubMacAddress}`);
    }

    meshDevices.set(mac, {
      mac_address: mac,
      device_name: data.device_name || 'Hub',
      main_air_pressure: data.main_air_pressure,
      atmospheric_pressure: data.atmospheric_pressure,
      temperature: data.temperature,
      elevation: data.elevation,
      weight: data.weight,
      ch1_air_pressure: data.ch1_air_pressure,
      ch1_weight: data.ch1_weight,
      ch2_air_pressure: data.ch2_air_pressure,
      ch2_weight: data.ch2_weight,
      mesh_role: 'master',
      source: 'bluetooth',
      last_updated: new Date(),
      priority: dataSourcePriority.bluetooth,
    });

    if (CONFIG.debug) {
      console.log('🌟 Hub device data received:', mac);
    }

  } else if (data.role === 'device' || data.role === 'slave') {
    // This is a slave device (received via ESP-NOW, forwarded over BLE)
    const mac = data.mac_address.toUpperCase();
    bleConnectedMACs.add(mac);

    // Track slave for mesh registration
    if (!connectedSlaves.has(mac)) {
      connectedSlaves.add(mac);
      console.log(`📡 Slave discovered: ${mac}`);

      // Register mesh topology immediately when new slave is found
      if (hubMacAddress) {
        registerMeshTopology(hubMacAddress, Array.from(connectedSlaves));
        lastMeshRegistration = Date.now();
      }
    }

    meshDevices.set(mac, {
      mac_address: mac,
      device_name: data.device_name || 'Slave',
      main_air_pressure: data.main_air_pressure,
      atmospheric_pressure: data.atmospheric_pressure,
      temperature: data.temperature,
      elevation: data.elevation,
      weight: data.weight,
      ch1_air_pressure: data.ch1_air_pressure,
      ch1_weight: data.ch1_weight,
      ch2_air_pressure: data.ch2_air_pressure,
      ch2_weight: data.ch2_weight,
      mesh_role: 'slave',
      source: 'bluetooth_mesh',
      last_updated: new Date(),
      priority: dataSourcePriority.bluetooth,
    });

    if (CONFIG.debug) {
      console.log('📡 Slave device data received:', mac);
    }

  } else if (data.slave_devices) {
    // Aggregated mesh data format
    handleMeshAggregatedData(data);

  } else {
    // Single device (no mesh role specified)
    handleSingleDeviceData(data);
  }

  // Update allDeviceData with collected mesh devices
  meshDevices.forEach((deviceData, mac) => {
    allDeviceData.set(mac, deviceData);
  });

  // Update the UI with BLE data
  updateUIFromBLEData();

  // NOTE: BLE data upload is now handled globally by app.js
  // uploadBLEDataToServer(data);

  // Log mesh status
  if (CONFIG.debug && meshDevices.size > 0) {
    console.log(`📊 Mesh devices tracked: ${meshDevices.size}`);
    let totalWeight = 0;
    meshDevices.forEach((d, mac) => {
      // Calculate correct weight for multi-channel devices
      let deviceWeight = d.weight || 0;
      if (deviceWeight === 0 && (d.ch1_weight || d.ch2_weight)) {
        deviceWeight = (d.ch1_weight || 0) + (d.ch2_weight || 0);
      }
      console.log(`   - ${mac}: ${deviceWeight} lbs (${d.mesh_role})`);
      totalWeight += deviceWeight;
    });
    console.log(`   Total mesh weight: ${totalWeight} lbs`);
  }

  // Notify server of MAC address ONCE per session
  const macToReport = hubDevice?.mac_address || data.mac_address;
  if (macToReport && !serverNotifiedForSession) {
    notifyServerOfBLEConnection(macToReport);
    serverNotifiedForSession = true;
  }

  // Periodic mesh registration (every 60 seconds)
  if (hubMacAddress && connectedSlaves.size > 0) {
    const now = Date.now();
    if (now - lastMeshRegistration > MESH_REGISTRATION_INTERVAL) {
      registerMeshTopology(hubMacAddress, Array.from(connectedSlaves));
      lastMeshRegistration = now;
    }
  }

  // Buffer and store
  bufferDataForSync(data);
  storeDataInDB(data);
}

function handleMeshAggregatedData(data) {
  const masterMac = data.mac_address.toUpperCase();
  bleConnectedMACs.add(masterMac);

  meshDevices.set(masterMac, {
    mac_address: masterMac,
    device_name: data.device_name || 'Master',
    main_air_pressure: data.main_air_pressure || data.master_device?.main_air_pressure,
    temperature: data.temperature || data.master_device?.temperature,
    weight: data.weight || data.master_device?.weight,
    source: 'bluetooth',
    last_updated: new Date(),
    priority: dataSourcePriority.bluetooth,
    mesh_role: 'master',
    device_count: data.device_count,
    total_weight: data.total_weight,
  });

  if (data.slave_devices) {
    data.slave_devices.forEach((slave) => {
      const slaveMac = slave.mac_address.toUpperCase();
      bleConnectedMACs.add(slaveMac);

      meshDevices.set(slaveMac, {
        mac_address: slaveMac,
        device_name: slave.device_name || 'Slave',
        main_air_pressure: slave.main_air_pressure,
        temperature: slave.temperature,
        weight: slave.weight,
        source: 'bluetooth_mesh',
        last_updated: new Date(),
        priority: dataSourcePriority.bluetooth,
        mesh_role: 'slave',
      });
    });
  }
}

function handleSingleDeviceData(data) {
  const mac = data.mac_address.toUpperCase();
  bleConnectedMACs.add(mac);

  meshDevices.set(mac, {
    ...data,
    mac_address: mac,
    source: 'bluetooth',
    last_updated: new Date(),
    priority: dataSourcePriority.bluetooth,
  });
}

/****************************
 * UI Updates from BLE Data
 ****************************/
function updateUIFromBLEData() {
  // Update each mesh device in the UI
  meshDevices.forEach((deviceData, mac) => {
    // Find element by MAC address
    const deviceRow = document.querySelector(`[data-mac-address="${mac}"]`);
    if (!deviceRow) {
      if (CONFIG.debug) console.log(`No UI element for MAC: ${mac}`);
      return;
    }

    // Update status dot - BLE connected = green pulsing
    const statusDot = deviceRow.querySelector('.device-status-dot');
    if (statusDot) {
      statusDot.className = 'w-3 h-3 bg-green-400 rounded-full device-status-dot animate-pulse';
    }

    // Update status text
    const statusText = deviceRow.querySelector('.device-status-text');
    if (statusText) {
      const role = deviceData.mesh_role === 'master' ? 'Hub' : 'Mesh';
      statusText.textContent = `${role} - Live`;
      statusText.className = 'text-sm text-green-400 device-status-text';
    }

    // Update weight
    const weightEl = deviceRow.querySelector('.device-weight');
    if (weightEl) {
      // For multi-channel devices, sum ch1_weight + ch2_weight if main weight is 0
      let weight = deviceData.weight || 0;
      if (weight === 0 && (deviceData.ch1_weight || deviceData.ch2_weight)) {
        weight = (deviceData.ch1_weight || 0) + (deviceData.ch2_weight || 0);
      }
      weightEl.textContent = `${Math.round(weight).toLocaleString()} lbs`;
      weightEl.className = 'font-bold text-white device-weight';
    }

    // Update pressure
    const pressureEl = deviceRow.querySelector('.device-pressure');
    if (pressureEl) {
      const psi = deviceData.main_air_pressure || 0;
      pressureEl.textContent = `${psi.toFixed(1)} psi`;
    }

    // Update per-channel data from BLE
    // Channel 1
    if (deviceData.ch1_air_pressure !== undefined) {
      const ch1PressureEl = deviceRow.querySelector('.channel-pressure[data-channel="1"]');
      if (ch1PressureEl) {
        ch1PressureEl.textContent = `${deviceData.ch1_air_pressure.toFixed(1)} psi`;
      }
    }
    if (deviceData.ch1_weight !== undefined) {
      const ch1WeightEl = deviceRow.querySelector('.channel-weight[data-channel="1"]');
      if (ch1WeightEl) {
        const weight = Math.round(deviceData.ch1_weight);
        ch1WeightEl.textContent = `${weight.toLocaleString()} lbs`;
      }
    }

    // Channel 2
    if (deviceData.ch2_air_pressure !== undefined) {
      const ch2PressureEl = deviceRow.querySelector('.channel-pressure[data-channel="2"]');
      if (ch2PressureEl) {
        ch2PressureEl.textContent = `${deviceData.ch2_air_pressure.toFixed(1)} psi`;
      }
    }
    if (deviceData.ch2_weight !== undefined) {
      const ch2WeightEl = deviceRow.querySelector('.channel-weight[data-channel="2"]');
      if (ch2WeightEl) {
        const weight = Math.round(deviceData.ch2_weight);
        ch2WeightEl.textContent = `${weight.toLocaleString()} lbs`;
      }
    }
  });

  // Update total weight from BLE data
  updateTotalWeightFromBLE();
}

function updateTotalWeightFromBLE() {
  if (meshDevices.size === 0) return;

  let totalWeight = 0;

  // Sum up all channel weights from all devices
  meshDevices.forEach((deviceData) => {
    // Add channel 1 weight
    if (deviceData.ch1_weight !== undefined) {
      totalWeight += deviceData.ch1_weight;
    }
    // Add channel 2 weight
    if (deviceData.ch2_weight !== undefined) {
      totalWeight += deviceData.ch2_weight;
    }
    // Add virtual steer weight if present
    if (deviceData.virtual_steer_weight !== undefined) {
      totalWeight += deviceData.virtual_steer_weight;
    }
  });

  // Update the configuration total weight display
  const totalWeightEl = document.querySelector('.configuration-total-weight');
  if (totalWeightEl) {
    const formattedWeight = Math.round(totalWeight).toLocaleString();
    totalWeightEl.textContent = `${formattedWeight} lbs`;
  }

  if (CONFIG.debug) {
    console.log(`🏋️ Updated configuration total weight from BLE: ${totalWeight} lbs`);
  }
}

/****************************
 * Server Communication
 ****************************/

/**
 * Register mesh topology with server
 * Server needs to know which slaves are connected to the hub
 * so it can return slave coefficients when hub requests them
 */
async function registerMeshTopology(hubMac, slaveMacs) {
  if (!hubMac || slaveMacs.length === 0) {
    if (CONFIG.debug) console.log('⏭️ Skipping mesh registration: no slaves');
    return null;
  }

  if (CONFIG.debug) {
    console.log('📡 Registering mesh topology:', {
      hub: hubMac,
      slaves: slaveMacs
    });
  }

  try {
    const response = await fetch('/api/bridge/mesh/register', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        mac_address: hubMac.toUpperCase(),
        role: 'master',
        connected_slaves: slaveMacs.map(mac => mac.toUpperCase()),
        device_type: 'ESP32',
        signal_strength: null
      })
    });

    if (!response.ok) {
      console.error('❌ Mesh registration failed:', response.status);
      return null;
    }

    const result = await response.json();
    console.log('✅ Mesh topology registered:', result);
    return result;

  } catch (error) {
    console.error('❌ Mesh registration error:', error);
    return null;
  }
}

/**
 * Upload BLE sensor data to server for persistent storage
 * Throttled to once per 30 seconds per device
 */
async function uploadBLEDataToServer(data) {
  // Only upload if we have channel data
  if (!data.ch1_air_pressure && !data.ch2_air_pressure) {
    return;
  }

  // Check throttle - only upload every 30 seconds per device
  const mac = data.mac_address;
  const now = Date.now();
  const lastUpload = lastUploadTime.get(mac) || 0;

  if (now - lastUpload < UPLOAD_THROTTLE_MS) {
    if (CONFIG.debug) {
      const timeRemaining = Math.ceil((UPLOAD_THROTTLE_MS - (now - lastUpload)) / 1000);
      console.log(`⏱️ Throttling upload for ${mac}, next upload in ${timeRemaining}s`);
    }
    return;
  }

  // Transform BLE format to API format
  const channels = [];

  if (data.ch1_air_pressure !== undefined || data.ch1_weight !== undefined) {
    channels.push({
      channel_index: 1,
      air_pressure: data.ch1_air_pressure || 0,
      weight: data.ch1_weight || 0
    });
  }

  if (data.ch2_air_pressure !== undefined || data.ch2_weight !== undefined) {
    channels.push({
      channel_index: 2,
      air_pressure: data.ch2_air_pressure || 0,
      weight: data.ch2_weight || 0
    });
  }

  const payload = {
    mac_address: data.mac_address,
    device_name: data.device_name,
    firmware_version: data.firmware_version,
    firmware_date: data.firmware_date,
    request_coefficients: true, // Request coefficients for hub AND slaves
    data_points: [{
      timestamp: Math.floor(Date.now() / 1000),
      channels: channels,
      atmospheric_pressure: data.atmospheric_pressure,
      temperature: data.temperature,
      elevation: data.elevation,
      gps_lat: data.gps_lat || null,
      gps_lng: data.gps_lng || null
    }]
  };

  if (CONFIG.debug) {
    console.log('📤 Uploading payload to server:', JSON.stringify(payload, null, 2));
  }

  try {
    const response = await fetch('/api/bridge/data', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    if (!response.ok) {
      console.error('Failed to upload BLE data to server:', response.statusText);
      return;
    }

    const result = await response.json();

    // Update last upload time on success
    lastUploadTime.set(mac, now);

    if (CONFIG.debug) {
      console.log('✅ BLE data uploaded to server for', mac);
    }

    // Handle slave device coefficients from server response
    if (result.slave_coefficients && Object.keys(result.slave_coefficients).length > 0) {
      console.log('📊 Received slave coefficients from server:', Object.keys(result.slave_coefficients));
      await sendSlaveCoefficientsToHub(result.slave_coefficients);
    }

  } catch (error) {
    console.error('Error uploading BLE data:', error);
  }
}

/**
 * Send slave device coefficients to hub via BLE
 * Hub will forward these to slaves via ESP-NOW
 */
async function sendSlaveCoefficientsToHub(slaveCoefficients) {
  if (!window.AirScalesBLE || !window.AirScalesBLE.isConnected()) {
    console.error('❌ BLE not connected, cannot send slave coefficients');
    return;
  }

  for (const [slaveMac, slaveData] of Object.entries(slaveCoefficients)) {
    const channelCalibrations = slaveData.channel_calibrations;

    for (const [channelIndex, coeffs] of Object.entries(channelCalibrations)) {
      const coeffPayload = {
        intercept: coeffs.intercept,
        air_pressure_coeff: coeffs.air_pressure_coeff,
        ambient_pressure_coeff: coeffs.ambient_pressure_coeff || 0,
        air_temp_coeff: coeffs.air_temp_coeff || 0
      };

      try {
        // Send coefficients to hub via BLE
        // Hub will forward to slave device via ESP-NOW
        // sendCoefficients(coefficients, channel, targetMac)
        await window.AirScalesBLE.sendCoefficients(coeffPayload, parseInt(channelIndex), slaveMac);
        console.log(`✅ Sent CH${channelIndex} coefficients to ${slaveMac} via hub`);

        // Small delay between writes to avoid overwhelming BLE
        await new Promise(resolve => setTimeout(resolve, 100));
      } catch (error) {
        console.error(`❌ Failed to send coefficients to ${slaveMac}:`, error);
      }
    }
  }
}

async function notifyServerOfBLEConnection(macAddress) {
  const userId = getCurrentUserId();
  if (!userId) {
    console.error('No user ID available');
    return;
  }

  // Get device name from saved device or hub
  const savedDevice = window.AirScalesBLE?.getSavedDevice() || {};
  const deviceName = savedDevice.deviceName || hubDevice?.device_name || 'Unknown Device';

  console.log('📤 Notifying server of BLE connection:', macAddress, 'Device name:', deviceName);

  try {
    const response = await fetch('/api/bridge/connect', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        mac_address: macAddress,
        user_id: userId,
        device_name: deviceName,
      }),
    });

    if (response.ok) {
      const result = await response.json();
      if (result.status === 'connected') {
        console.log('✅ Server notified of BLE connection, device_id:', result.device_id);

        // Handle device-vehicle assignment
        if (result.needs_assignment) {
          // Device has no vehicle assigned - show assignment modal
          console.log('📋 Device needs vehicle assignment');
          showVehicleAssignmentModal(result.device_id, result.user_vehicles);
        } else if (result.vehicle_info) {
          // Device already assigned to a vehicle - auto-connect user to that vehicle
          console.log('🚗 Device assigned to vehicle:', result.vehicle_info.name);
          showVehicleConnectedNotification(result.vehicle_info);
        }
      }
    } else {
      const errorText = await response.text();
      console.error('Server notification failed:', response.status, errorText);
    }
  } catch (error) {
    console.error('Error notifying server:', error);
  }
}

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
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        ...data,
        source: 'bluetooth_app',
        request_coefficients: true,
      }),
    });

    if (response.ok) {
      const result = await response.json();
      console.log('✅ BLE data synced to server:', result);

      // Send coefficients back to device if provided (new multi-channel format)
      if (result.channel_calibrations && window.AirScalesBLE) {
        console.log('📊 Channel calibrations received from server:', result.channel_calibrations);
        // channel_calibrations is now an object keyed by channel_index {1: {...}, 2: {...}}
        for (const [channelIndex, channelCal] of Object.entries(result.channel_calibrations)) {
          const coefficients = {
            intercept: channelCal.intercept,
            air_pressure_coeff: channelCal.air_pressure_coeff,
            ambient_pressure_coeff: channelCal.ambient_pressure_coeff,
            air_temp_coeff: channelCal.air_temp_coeff
          };
          await window.AirScalesBLE.sendCoefficients(coefficients, parseInt(channelIndex));
          console.log('📊 Coefficients sent to device for channel', channelIndex);
        }
      }
      // DEPRECATED: Legacy single-channel coefficients for backward compatibility
      else if (result.regression_coefficients && window.AirScalesBLE) {
        console.log('📊 Legacy regression coefficients received from server:', result.regression_coefficients);
        await window.AirScalesBLE.sendCoefficients(result.regression_coefficients, 1);
        console.log('📊 Legacy coefficients sent to device');
      }
    }
  } catch (error) {
    console.error('Server sync error:', error);
  }
}

function getCurrentUserId() {
  return window.currentUserId;
}

/****************************
 * IndexedDB
 ****************************/
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
          autoIncrement: true,
        });
        store.createIndex('mac_address', 'mac_address', { unique: false });
        store.createIndex('timestamp', 'timestamp', { unique: false });
      }

      if (!db.objectStoreNames.contains('devices')) {
        db.createObjectStore('devices', { keyPath: 'mac_address' });
      }
    };
  });
}

async function storeDataInDB(data) {
  if (!dbInstance) return;

  try {
    const transaction = dbInstance.transaction(['sensorData'], 'readwrite');
    const store = transaction.objectStore('sensorData');

    await store.add({
      ...data,
      timestamp: new Date().toISOString(),
      synced: false,
    });
  } catch (error) {
    console.error('DB store error:', error);
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

/****************************
 * Initialization
 ****************************/
async function bootDashboard(reason = 'unknown') {
  if (dashBooted) {
    if (CONFIG.debug) console.log(`[dashboard] boot skipped (${reason})`);
    return;
  }

  if (CONFIG.debug) console.log(`📄 Dashboard booting (${reason})...`);
  dashBooted = true;

  // BLE is initialized globally in app.js - don't init here
  // Just check if it's available
  if (window.AirScalesBLE && window.AirScalesBLE.isAvailable()) {
    console.log('✅ Bluetooth available (initialized in app.js)');
  } else {
    console.log('⚠️ Bluetooth not available');
  }

  // Bind UI
  initializeDeviceScanning();

  // Set up BLE listeners
  setupBLEListeners();

  // Init DB
  initDB().catch((err) => console.error('[dashboard] DB init failed:', err));

  // Start server polling when API_URL is ready
  if (API_URL) {
    startUpdates();
  } else {
    let tries = 0;
    const t = setInterval(() => {
      tries++;
      if (API_URL) {
        clearInterval(t);
        startUpdates();
      } else if (tries >= 30) {
        clearInterval(t);
        console.error('❌ API URL not initialized after waiting');
      }
    }, 100);
  }

  if (CONFIG.debug) console.log('✅ Dashboard boot complete');
}

document.addEventListener('DOMContentLoaded', () => bootDashboard('DOMContentLoaded'));

window.addEventListener('pageshow', (e) => {
  if (CONFIG.debug) console.log(`[dashboard] pageshow (persisted=${e.persisted})`);
  // Only re-boot if page was restored from bfcache (back/forward navigation)
  if (e.persisted) {
    dashBooted = false;
    bootDashboard('pageshow-bfcache');
  }
});

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
  ble: () => window.AirScalesBLE,
  status: () => ({
    apiUrl: API_URL,
    intervalId: updateInterval,
    retryCount,
    bleConnected: window.AirScalesBLE?.isConnected() || false,
    dashBooted,
    serverNotified: serverNotifiedForSession,
    meshDeviceCount: meshDevices.size,
    hubDevice: hubDevice?.mac_address || null,
    bleConnectedMACs: Array.from(bleConnectedMACs),
  }),
  meshDevices: () => {
    const devices = [];
    meshDevices.forEach((d, mac) => {
      devices.push({ mac, role: d.mesh_role, weight: d.weight, pressure: d.main_air_pressure });
    });
    return devices;
  },
  allDevices: () => {
    const devices = [];
    allDeviceData.forEach((d, mac) => {
      devices.push({ mac, source: d.source, weight: d.weight });
    });
    return devices;
  },
  clearBLE: () => {
    hubDevice = null;
    meshDevices.clear();
    bleConnectedMACs.clear();
    hubMacAddress = null;
    connectedSlaves.clear();
    lastMeshRegistration = 0;
    serverNotifiedForSession = false;
    console.log('🧹 BLE tracking cleared');
  },
  meshTopology: () => ({
    hub: hubMacAddress,
    slaves: Array.from(connectedSlaves),
    lastRegistration: new Date(lastMeshRegistration).toISOString(),
  }),
};

/****************************
 * Vehicle Assignment UI
 ****************************/
function showVehicleAssignmentModal(deviceId, userVehicles) {
  const modalHtml = `
    <div id="vehicle-assignment-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
        <h2 class="text-2xl font-bold text-white mb-4">Assign Device to Vehicle</h2>
        <p class="text-gray-300 mb-4">This device is not assigned to a vehicle. Would you like to:</p>

        ${userVehicles.length > 0 ? `
          <div class="mb-4">
            <label class="block text-gray-300 text-sm font-semibold mb-2">Assign to Existing Vehicle</label>
            <select id="vehicle-select" class="w-full bg-gray-700 text-white px-4 py-2 rounded border border-gray-600">
              <option value="">-- Select a Vehicle --</option>
              ${userVehicles.map(v => `<option value="${v.id}">${v.name}</option>`).join('')}
            </select>
          </div>
        ` : ''}

        <div class="flex gap-4">
          <button id="assign-existing-btn" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded" ${userVehicles.length === 0 ? 'disabled' : ''}>
            Assign to Selected
          </button>
          <button id="create-new-vehicle-btn" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded">
            Create New Vehicle
          </button>
          <button id="cancel-assignment-btn" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2 px-4 rounded">
            Skip
          </button>
        </div>
      </div>
    </div>
  `;

  document.body.insertAdjacentHTML('beforeend', modalHtml);

  const modal = document.getElementById('vehicle-assignment-modal');

  // Assign to existing vehicle
  document.getElementById('assign-existing-btn')?.addEventListener('click', async () => {
    const vehicleId = document.getElementById('vehicle-select')?.value;
    if (!vehicleId) {
      alert('Please select a vehicle');
      return;
    }

    try {
      const response = await fetch(`/api/vehicle/assign-device/${deviceId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ vehicle_id: vehicleId })
      });

      const result = await response.json();
      if (result.success) {
        alert(`Device assigned to ${result.vehicle_name} successfully!`);
        modal.remove();
        location.reload();
      } else {
        alert('Error: ' + (result.message || 'Failed to assign device'));
      }
    } catch (error) {
      console.error('Error:', error);
      alert('Failed to assign device');
    }
  });

  // Create new vehicle
  document.getElementById('create-new-vehicle-btn')?.addEventListener('click', () => {
    modal.remove();
    window.location.href = '/vehicles';
  });

  // Cancel
  document.getElementById('cancel-assignment-btn')?.addEventListener('click', () => {
    modal.remove();
  });
}

function showVehicleConnectedNotification(vehicleInfo) {
  const notificationHtml = `
    <div id="vehicle-connected-notification" class="fixed top-4 right-4 bg-green-700 text-white px-6 py-4 rounded-lg shadow-lg z-50 max-w-md">
      <div class="flex items-start">
        <div class="flex-shrink-0">
          <svg class="h-6 w-6 text-green-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="ml-3 flex-1">
          <h3 class="text-sm font-medium">Connected to Vehicle</h3>
          <p class="mt-1 text-sm text-green-200">${vehicleInfo.name}</p>
          <p class="mt-1 text-xs text-green-300">Owner: ${vehicleInfo.owner}</p>
          <p class="mt-2 text-xs text-green-200">This vehicle has been added to your vehicles list.</p>
        </div>
        <button id="close-notification-btn" class="ml-4 flex-shrink-0 text-green-300 hover:text-white">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div class="mt-3">
        <a href="/vehicles" class="text-xs text-green-200 hover:text-white underline">View My Vehicles →</a>
      </div>
    </div>
  `;

  document.body.insertAdjacentHTML('beforeend', notificationHtml);

  // Auto-dismiss after 10 seconds
  setTimeout(() => {
    document.getElementById('vehicle-connected-notification')?.remove();
  }, 10000);

  // Manual dismiss
  document.getElementById('close-notification-btn')?.addEventListener('click', () => {
    document.getElementById('vehicle-connected-notification')?.remove();
  });
}
// assets/app.js
// Import main CSS (Tailwind only - Flowbite styles come via plugin)
import './styles/app.css';

// Import Flowbite JavaScript for interactive components
import 'flowbite';

// Optional: import Stimulus if you're using it
import './bootstrap.js';

// Import Bluetooth module - initializes on every page
import './js/ble.js';

// NOTE: Initialization happens in DOMContentLoaded below - DO NOT init here

if (window.location.pathname.startsWith('/app')) {
  import('./app/register-sw.js');
}

console.log('Tailwind + Flowbite loaded via Encore ✅');

// Global BLE data upload tracking
let lastUploadTime = new Map(); // MAC address -> timestamp
const UPLOAD_THROTTLE_MS = 30000; // 30 seconds

// Global mesh topology tracking
let hubMacAddress = null;
let connectedSlaves = new Set();
let lastMeshRegistration = 0;
const MESH_REGISTRATION_INTERVAL = 60000; // 60 seconds

/**
 * Register mesh topology with server (global)
 */
async function registerMeshTopology(hubMac, slaveMacs) {
  if (!hubMac || slaveMacs.length === 0) {
    console.log('⏭️ Skipping mesh registration: no slaves');
    return null;
  }

  console.log('📡 Registering mesh topology:', {hub: hubMac, slaves: slaveMacs});

  try {
    const response = await fetch('/api/bridge/mesh/register', {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
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
 * Upload BLE sensor data to server (global)
 */
async function uploadBLEDataToServer(data) {
  const mac = data.mac_address;

  // Build channels array - support both formats
  let channels = [];

  // Format 1: ESP32 sends 'channels' array directly
  if (data.channels && Array.isArray(data.channels)) {
    channels = data.channels;
  }
  // Format 2: Dashboard format with ch1_air_pressure, ch2_air_pressure
  else {
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
  }

  // Skip if no channel data
  if (channels.length === 0) {
    return;
  }

  const now = Date.now();
  const lastUpload = lastUploadTime.get(mac) || 0;

  if (now - lastUpload < UPLOAD_THROTTLE_MS) {
    const timeRemaining = Math.ceil((UPLOAD_THROTTLE_MS - (now - lastUpload)) / 1000);
    console.log(`⏱️ Throttling upload for ${mac}, next upload in ${timeRemaining}s`);
    return;
  }

  const payload = {
    mac_address: data.mac_address,
    device_name: data.device_name,
    firmware_version: data.firmware_version,
    firmware_date: data.firmware_date,
    request_coefficients: true,
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

  console.log('📤 Uploading BLE data to server:', mac);

  try {
    const response = await fetch('/api/bridge/data', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });

    if (!response.ok) {
      console.error('Failed to upload BLE data to server:', response.statusText);
      return;
    }

    const result = await response.json();
    lastUploadTime.set(mac, now);
    console.log('✅ BLE data uploaded to server for', mac);

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
 * Send slave device coefficients to hub via BLE (global)
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
        // sendCoefficients(coefficients, channel, targetMac)
        await window.AirScalesBLE.sendCoefficients(coeffPayload, parseInt(channelIndex), slaveMac);
        console.log(`✅ Sent CH${channelIndex} coefficients to ${slaveMac} via hub`);
        await new Promise(resolve => setTimeout(resolve, 100));
      } catch (error) {
        console.error(`❌ Failed to send coefficients to ${slaveMac}:`, error);
      }
    }
  }
}

/**
 * Handle incoming BLE data (global listener)
 */
function handleGlobalBLEData(data) {
  console.log('📡 Global BLE data received:', data);

  // Track hub and slaves for mesh registration
  if (data.role === 'hub' || data.role === 'master') {
    if (!hubMacAddress) {
      hubMacAddress = data.mac_address.toUpperCase();
      console.log(`📡 Hub identified: ${hubMacAddress}`);
    }
  } else if (data.role === 'device' || data.role === 'slave') {
    const slaveMac = data.mac_address.toUpperCase();
    if (!connectedSlaves.has(slaveMac)) {
      connectedSlaves.add(slaveMac);
      console.log(`📡 Slave discovered: ${slaveMac}`);

      // Register mesh topology immediately when new slave is found
      if (hubMacAddress) {
        registerMeshTopology(hubMacAddress, Array.from(connectedSlaves));
        lastMeshRegistration = Date.now();
      }
    }
  }

  // Periodic mesh registration (every 60 seconds)
  if (hubMacAddress && connectedSlaves.size > 0) {
    const now = Date.now();
    if (now - lastMeshRegistration > MESH_REGISTRATION_INTERVAL) {
      registerMeshTopology(hubMacAddress, Array.from(connectedSlaves));
      lastMeshRegistration = now;
    }
  }

  // Upload BLE data to server
  uploadBLEDataToServer(data);
}

// Ensure BLE is initialized exactly once
let bleInitialized = false;

// Initialize BLE when running as native app
document.addEventListener('DOMContentLoaded', async () => {
  // Guard against multiple invocations
  if (bleInitialized) {
    console.log('↩️ BLE already initialized - skipping DOMContentLoaded init');
    return;
  }

  if (window.Capacitor && window.Capacitor.isNativePlatform() && window.AirScalesBLE) {
    bleInitialized = true;
    console.log('🔵 Initializing AirScalesBLE...');

    const success = await window.AirScalesBLE.init();

    if (success) {
      console.log('✅ Bluetooth ready');

      // IMPORTANT: Add only ONE global BLE data listener
      window.AirScalesBLE.addListener((event, data) => {
        if (event === 'data') {
          handleGlobalBLEData(data);
        }
      });

      console.log('📡 Global BLE data handler active');
    } else {
      console.log('⚠️ Bluetooth not available (web browser or init failed)');
    }
  }
});
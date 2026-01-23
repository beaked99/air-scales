// assets/js/ble.js
// Capacitor Bluetooth Module for Air Scales

import { BleClient } from '@capacitor-community/bluetooth-le';

const BLE_SERVICE_UUID = '12345678-1234-1234-1234-123456789abc';
const BLE_SENSOR_CHAR_UUID = '87654321-4321-4321-4321-cba987654321';
const BLE_COEFFS_CHAR_UUID = '11111111-2222-3333-4444-555555555555';
const BLE_OTA_CHAR_UUID = '22222222-3333-4444-5555-666666666666';

// OTA Command bytes
const OTA_CMD_START = 0x01;
const OTA_CMD_DATA = 0x02;
const OTA_CMD_END = 0x03;
const OTA_CMD_ABORT = 0x04;

const AirScalesBLE = {
  isCapacitor: false,
  isInitialized: false,
  connectedDeviceId: null,
  listeners: [],
  currentRssi: null, // Store current connection's RSSI
  lastSensorData: null, // Store last received sensor data (includes firmware_version)
  negotiatedMtu: 23, // Default BLE MTU, updated after connect
  autoSwitchEnabled: true, // Default enabled, can be toggled by user
  backgroundScanInterval: null,
  lastSwitchTime: 0, // Prevent rapid switching
  reconnectAttempts: 0, // Track reconnection attempts
  maxReconnectAttempts: 3, // Maximum reconnection attempts before giving up
  autoDiscoveryPaused: false, // Pause auto-discovery during manual scanning
  isAutoSwitching: false, // Flag to prevent disconnect callback from interfering during auto-switch

  // Scan management - single-owner pattern to prevent overlapping scans
  scanStopTimer: null,
  scanInProgress: false,
  scanToken: 0, // Increments every time we start a scan

  // Initialize BLE - call this on app start
  async init() {
    // Guard against double initialization
    if (this.isInitialized) {
      console.log('↩️ BLE already initialized - skipping');
      return true;
    }

    // Check if we're in Capacitor
    if (!window.Capacitor || !window.Capacitor.isNativePlatform()) {
      console.log('⚠️ Not in Capacitor app - Bluetooth disabled');
      return false;
    }

    try {
      console.log('🔵 Initializing BLE Client...');

      // Initialize BLE - this also requests necessary permissions
      // IMPORTANT: androidNeverForLocation: false allows full scan results with device names
      // Setting to true causes Android to filter out advertisement data including device names
      await BleClient.initialize({ androidNeverForLocation: false });

      this.isCapacitor = true;
      this.isInitialized = true;
      console.log('✅ Capacitor Bluetooth initialized');

      // Load auto-switch preference from database (async)
      await this.getAutoSwitchEnabled();
      console.log('🔧 Auto-switching:', this.autoSwitchEnabled ? 'enabled' : 'disabled');

      // Check if Bluetooth is enabled
      const isEnabled = await BleClient.isEnabled();
      console.log('📡 Bluetooth enabled:', isEnabled);

      if (!isEnabled) {
        console.log('⚠️ Bluetooth is disabled on device - please enable it');
        // Optionally prompt user to enable Bluetooth
        // await BleClient.requestEnable();
      }

      // Try auto-reconnect if we have a saved device
      const reconnected = await this.autoReconnect();

      // If auto-reconnect failed, start auto-discovery
      if (!reconnected) {
        console.log('🔍 Starting auto-discovery for AirScales devices...');
        await this.startAutoDiscovery();
      }

      // Start background scanning for better signal devices
      this.startBackgroundScanning();

      return true;
    } catch (error) {
      console.error('❌ Bluetooth init failed:', error);
      return false;
    }
  },

  // Check if Bluetooth is available
  isAvailable() {
    return this.isCapacitor && this.isInitialized;
  },

  // Check if connected
  isConnected() {
    return !!this.connectedDeviceId;
  },

  // Stop any active scan and clear timers
  async stopScan(reason = '') {
    if (this.scanStopTimer) {
      clearTimeout(this.scanStopTimer);
      this.scanStopTimer = null;
    }

    try {
      await BleClient.stopLEScan();
      if (reason) console.log(`🛑 stopLEScan (${reason})`);
    } catch (e) {
      // Ignore errors when stopping scan
    }

    this.scanInProgress = false;
  },

  // Start a scan safely (single-owner pattern)
  async startScan({ options, onResult, durationMs, label }) {
    // Kill any previous scan + timers
    await this.stopScan(`pre-start: ${label}`);

    this.scanInProgress = true;
    const token = ++this.scanToken;

    await BleClient.requestLEScan(options, (result) => {
      // Ignore results from stale scans
      if (token !== this.scanToken) {
        console.log(`⏭️ Ignoring result from stale scan (token ${token} vs ${this.scanToken})`);
        return;
      }
      onResult(result);
    });

    this.scanStopTimer = setTimeout(async () => {
      // Only stop if this scan is still the active one
      if (token !== this.scanToken) return;
      await this.stopScan(`timeout: ${label}`);
    }, durationMs);

    console.log(`✅ Scan started (${label}) for ${durationMs / 1000}s`);
  },

  // Scan and show device picker
async scanAndConnect() {
  if (!this.isAvailable()) {
    throw new Error('Bluetooth not available. Please use the Air Scales app.');
  }

  try {
    const device = await BleClient.requestDevice({
      namePrefix: 'AirScale',
    });

    console.log('📱 Device selected:', device);

    // Extract WiFi MAC from device name (e.g., "AirScale-9C:13:9E:BA:DC:90")
    const wifiMac = this.extractMacFromName(device.name);

    // Note: requestDevice doesn't provide RSSI, will be captured on first scan
    this.currentRssi = null; // Will be updated by background scanning

    await this.connectToDevice(device.deviceId, device.name, wifiMac);
    return device;
  } catch (error) {
    console.error('❌ Scan failed:', error);
    throw error;
  }
},

// Add this new function
extractMacFromName(name) {
  if (!name) return null;
  // Match pattern like "AirScale-XX:XX:XX:XX:XX:XX"
  const match = name.match(/AirScale-([0-9A-Fa-f:]{17})/);
  return match ? match[1].toUpperCase() : null;
},

  // Connect to a specific device
  async connectToDevice(deviceId, deviceName = 'Unknown', wifiMac = null) {
  if (!this.isAvailable()) {
    throw new Error('Bluetooth not available');
  }

  try {
    if (this.connectedDeviceId) {
      await this.disconnect();
    }

    await BleClient.connect(deviceId, (disconnectedDeviceId) => {
      console.log('⚡ Device disconnected:', disconnectedDeviceId);
      this.connectedDeviceId = null;
      this.currentRssi = null; // Reset RSSI on disconnect
      this.lastSensorData = null; // Clear stale sensor data (including firmware version)
      this.notifyListeners('disconnected', { deviceId: disconnectedDeviceId });

      // Check if we're in the middle of an auto-switch operation
      if (this.isAutoSwitching) {
        console.log('⏸️ Auto-reconnect skipped - auto-switching in progress');
        return;
      }

      // Check if auto-discovery is paused (manual scanning in progress)
      if (this.autoDiscoveryPaused) {
        console.log('⏸️ Auto-reconnect skipped - auto-discovery is paused');
        return;
      }

      // Check if we should attempt reconnection
      this.reconnectAttempts++;
      if (this.reconnectAttempts > this.maxReconnectAttempts) {
        console.log(`⚠️ Max reconnect attempts (${this.maxReconnectAttempts}) reached. Clearing saved device.`);
        localStorage.removeItem('airscales_ble_device_id');
        localStorage.removeItem('airscales_ble_device_name');
        localStorage.removeItem('airscales_ble_wifi_mac');
        this.reconnectAttempts = 0;
        return;
      }

      // Auto-reconnect: Try to reconnect to the same device first, then start discovery
      console.log(`🔄 Auto-reconnecting... (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
      setTimeout(async () => {
        const reconnected = await this.autoReconnect();
        if (!reconnected) {
          console.log('🔍 Starting auto-discovery after disconnect...');
          await this.startAutoDiscovery();
        }
      }, 2000);
    });

    this.connectedDeviceId = deviceId;

    // Get the negotiated MTU for faster OTA transfers
    // Android negotiates MTU automatically after connect; we just need to read it
    // Default BLE MTU is 23 bytes, but with ESP32's esp_ble_gatt_set_local_mtu(247)
    // we should get ~185-247 depending on the phone
    try {
      if (typeof BleClient.getMtu === 'function') {
        const mtu = await BleClient.getMtu(deviceId);
        this.negotiatedMtu = mtu;
        console.log('📏 MTU negotiated:', mtu);
      } else {
        // Plugin doesn't support getMtu - assume default
        console.log('⚠️ getMtu not available in this plugin version');
        this.negotiatedMtu = 23;
      }
    } catch (e) {
      console.log('⚠️ getMtu failed (using default 23):', e?.message || e);
      this.negotiatedMtu = 23;
    }

    // Save for auto-reconnect
    localStorage.setItem('airscales_ble_device_id', deviceId);
    localStorage.setItem('airscales_ble_device_name', deviceName);
    if (wifiMac) {
      localStorage.setItem('airscales_ble_wifi_mac', wifiMac);
    }

    await this.startSensorNotifications();

    // Reset reconnect counter on successful connection
    this.reconnectAttempts = 0;

    // Start background scanning for better signal devices (if enabled)
    this.startBackgroundScanning();

    console.log('✅ Connected to:', deviceName, 'WiFi MAC:', wifiMac);
    this.notifyListeners('connected', { deviceId, deviceName, wifiMac });

    return true;
  } catch (error) {
    console.error('❌ Connection failed:', error);
    localStorage.removeItem('airscales_ble_device_id');
    localStorage.removeItem('airscales_ble_device_name');
    localStorage.removeItem('airscales_ble_wifi_mac');
    throw error;
  }
},

  // Auto-reconnect to saved device
  async autoReconnect() {
    // Skip if already connected - prevents reconnect loops
    if (this.connectedDeviceId) {
      console.log('↩️ Already connected - skipping auto-reconnect');
      return true;
    }

    const savedDeviceId = localStorage.getItem('airscales_ble_device_id');
    const savedDeviceName = localStorage.getItem('airscales_ble_device_name');

    if (!savedDeviceId) {
      console.log('📱 No saved device to reconnect');
      return false;
    }

    console.log('🔄 Attempting auto-reconnect to:', savedDeviceName, 'ID:', savedDeviceId);

    try {
      await this.connectToDevice(savedDeviceId, savedDeviceName);
      console.log('✅ Auto-reconnect successful');
      return true;
    } catch (error) {
      console.log('⚠️ Auto-reconnect failed (device may be out of range or ID changed):', error.message);
      // Clear stale device ID to force fresh discovery
      localStorage.removeItem('airscales_ble_device_id');
      localStorage.removeItem('airscales_ble_device_name');
      localStorage.removeItem('airscales_ble_wifi_mac');
      console.log('🗑️ Cleared stale device IDs - will discover fresh');
      return false;
    }
  },

  // Auto-discovery: Scan for and automatically connect to ANY AirScales device
  async startAutoDiscovery() {
    if (!this.isAvailable()) {
      console.log('❌ BLE not available for auto-discovery');
      return false;
    }

    if (this.autoDiscoveryPaused) {
      console.log('⏸️ Auto-discovery paused - skipping');
      return false;
    }

    if (this.connectedDeviceId) {
      console.log('↩️ Already connected - skipping auto-discovery');
      return true;
    }

    if (this.scanInProgress) {
      console.log('↩️ Scan already in progress - not stacking scans');
      return true;
    }

    console.log('🔍 Auto-discovery scanning (AirScale)...');

    try {
      await this.startScan({
        label: 'auto-discovery',
        durationMs: 10000,
        options: { namePrefix: 'AirScale' },
        onResult: (result) => {
          const rssi = result.rssi ?? -65;
          console.log('✨ Found:', result.device.name, result.device.deviceId, 'RSSI:', rssi);

          // Stop scan immediately when device found
          this.stopScan('auto-discovery found device');

          const wifiMac = this.extractMacFromName(result.device.name);
          this.currentRssi = rssi;

          this.connectToDevice(result.device.deviceId, result.device.name, wifiMac)
            .then(() => console.log('🎉 Auto-connected to:', result.device.name))
            .catch((error) => {
              console.error('❌ Auto-connect failed:', error);
              setTimeout(() => this.startAutoDiscovery(), 5000);
            });
        }
      });

      // If nothing found after timeout, retry later
      setTimeout(() => {
        if (!this.connectedDeviceId && !this.autoDiscoveryPaused) {
          console.log('🔄 Auto-discovery retry in 10s...');
          this.startAutoDiscovery();
        }
      }, 11000); // Slightly longer than scan duration

      return true;
    } catch (error) {
      console.error('❌ Auto-discovery failed:', error);
      setTimeout(() => this.startAutoDiscovery(), 15000);
      return false;
    }
  },

  // Start sensor notifications
  async startSensorNotifications() {
    if (!this.connectedDeviceId) return;

    try {
      await BleClient.startNotifications(
        this.connectedDeviceId,
        BLE_SERVICE_UUID,
        BLE_SENSOR_CHAR_UUID,
        (value) => {
          const data = this.parseDataView(value);

          // If ESP32 includes RSSI in data, update it (though unlikely)
          if (data && data.rssi !== undefined) {
            this.currentRssi = data.rssi;
          }

          // Add current RSSI to data for UI display
          if (data && this.currentRssi !== null) {
            data.rssi = this.currentRssi;
          }

          // Store last sensor data (includes firmware_version for OTA checks)
          if (data) {
            this.lastSensorData = data;
          }

          this.notifyListeners('data', data);
        }
      );
      console.log('📡 Sensor notifications started');
    } catch (error) {
      console.error('❌ Failed to start notifications:', error);
    }
  },

  // Parse DataView - handles both binary (45 bytes) and legacy JSON formats
  // Binary packet structure (45 bytes, little-endian, packed):
  //   0: uint8  packetType (0=hub, 1=device)
  //   1-6: uint8[6] mac address bytes
  //   7-10: float32 ch1AirPressure
  //   11-14: float32 ch2AirPressure
  //   15-18: float32 atmosphericPressure
  //   19-22: float32 temperature
  //   23-26: float32 ch1Weight
  //   27-30: float32 ch2Weight
  //   31-34: float32 totalWeight
  //   35: uint8 batteryLevel
  //   36: uint8 deviceCount (hub only)
  //   37-40: float32 fleetTotalWeight (hub only)
  //   41: uint8 fwMajor
  //   42: uint8 fwMinor
  //   43: uint8 fwPatch
  //   44: int8 espnowRssi (device only)
  parseDataView(dataView) {
    try {
      // Check if this is a binary packet (45 bytes) or JSON
      if (dataView.byteLength === 45) {
        // Binary packet - parse it
        const littleEndian = true;

        const packetType = dataView.getUint8(0);
        const isHub = packetType === 0;

        // Parse MAC address bytes to string
        const macBytes = [];
        for (let i = 0; i < 6; i++) {
          macBytes.push(dataView.getUint8(1 + i).toString(16).padStart(2, '0').toUpperCase());
        }
        const macAddress = macBytes.join(':');

        // Parse floats
        const ch1AirPressure = dataView.getFloat32(7, littleEndian);
        const ch2AirPressure = dataView.getFloat32(11, littleEndian);
        const atmosphericPressure = dataView.getFloat32(15, littleEndian);
        const temperature = dataView.getFloat32(19, littleEndian);
        const ch1Weight = dataView.getFloat32(23, littleEndian);
        const ch2Weight = dataView.getFloat32(27, littleEndian);
        const totalWeight = dataView.getFloat32(31, littleEndian);

        const batteryLevel = dataView.getUint8(35);
        const deviceCount = dataView.getUint8(36);
        const fleetTotalWeight = dataView.getFloat32(37, littleEndian);

        const fwMajor = dataView.getUint8(41);
        const fwMinor = dataView.getUint8(42);
        const fwPatch = dataView.getUint8(43);
        const firmwareVersion = `${fwMajor}.${fwMinor}.${fwPatch}`;

        const espnowRssi = dataView.getInt8(44);

        // Build object matching the old JSON format for compatibility
        const data = {
          mac_address: macAddress,
          ch1_air_pressure: ch1AirPressure,
          ch2_air_pressure: ch2AirPressure,
          atmospheric_pressure: atmosphericPressure,
          temperature: temperature,
          ch1_weight: ch1Weight,
          ch2_weight: ch2Weight,
          total_weight: totalWeight,
          battery_level: batteryLevel,
          firmware_version: firmwareVersion,
          role: isHub ? 'hub' : 'device'
        };

        // Hub-specific fields
        if (isHub) {
          data.device_count = deviceCount;
          data.fleet_total_weight = fleetTotalWeight;
        } else {
          // Device-specific fields
          data.espnow_rssi = espnowRssi;
        }

        console.log(`📦 Binary BLE packet (${dataView.byteLength} bytes): ${data.role} ${macAddress} v${firmwareVersion}`);
        return data;
      }

      // Fallback: try to parse as JSON (legacy format)
      const decoder = new TextDecoder();
      const jsonString = decoder.decode(dataView);
      const data = JSON.parse(jsonString);
      console.log(`📄 JSON BLE packet (${dataView.byteLength} bytes)`);
      return data;
    } catch (error) {
      console.error('Failed to parse BLE data:', error);
      return null;
    }
  },

  // Send coefficients to device
  async sendCoefficients(coefficients, channel = 1, targetMac = null) {
  if (!this.connectedDeviceId) {
    throw new Error('No device connected');
  }

  // Block coefficient writes during OTA - they cause BLE contention
  if (this.otaInProgress) {
    console.log('⏸️ Skipping coefficient write - OTA in progress');
    return;
  }

  try {
    const encoder = new TextEncoder();
    const payload = {
      channel: channel, // Channel index (1 or 2)
      intercept: coefficients.intercept || 0,
      air_pressure_coeff: coefficients.air_pressure_coeff || 0,
      ambient_pressure_coeff: coefficients.ambient_pressure_coeff || 0,
      air_temp_coeff: coefficients.air_temp_coeff || 0,
      target_mac: targetMac || ''
    };

    const data = encoder.encode(JSON.stringify(payload));

    await BleClient.write(
      this.connectedDeviceId,
      BLE_SERVICE_UUID,
      BLE_COEFFS_CHAR_UUID,
      data
    );

    console.log('✅ Coefficients sent to:', targetMac || 'hub (self)', 'for channel', channel);
  } catch (error) {
    console.error('❌ Failed to send coefficients:', error);
    throw error;
  }
},

  // Disconnect
  async disconnect() {
    if (!this.connectedDeviceId) return;

    try {
      await BleClient.disconnect(this.connectedDeviceId);
      console.log('🔌 Disconnected');
    } catch (error) {
      console.error('Disconnect error:', error);
    }

    this.connectedDeviceId = null;
  },

  // Forget saved device
  forgetDevice() {
    localStorage.removeItem('airscales_ble_device_id');
    localStorage.removeItem('airscales_ble_device_name');
    this.disconnect();
    console.log('🗑️ Device forgotten');
  },

  // Event listener system
  addListener(callback) {
    this.listeners.push(callback);
    return () => {
      this.listeners = this.listeners.filter(l => l !== callback);
    };
  },

  notifyListeners(event, data) {
    this.listeners.forEach(callback => {
      try {
        callback(event, data);
      } catch (error) {
        console.error('Listener error:', error);
      }
    });
  },

  // Get saved device info
  getSavedDevice() {
  return {
    deviceId: localStorage.getItem('airscales_ble_device_id'),
    deviceName: localStorage.getItem('airscales_ble_device_name'),
    wifiMac: localStorage.getItem('airscales_ble_wifi_mac'),
  };
},

  // Get connected device firmware version (from last sensor data)
  getConnectedDeviceFirmware() {
    if (!this.isConnected() || !this.lastSensorData) {
      return null;
    }
    return this.lastSensorData.firmware_version || null;
  },

  // Background scanning for better signal devices
  startBackgroundScanning() {
    if (this.backgroundScanInterval) {
      clearInterval(this.backgroundScanInterval);
    }

    // Scan every 60 seconds
    this.backgroundScanInterval = setInterval(() => {
      if (this.autoSwitchEnabled && this.connectedDeviceId) {
        this.scanForBetterSignal();
      }
    }, 60000);

    console.log('📡 Background signal monitoring started (every 60s)');
  },

  // Scan for devices with better signal
  // Strategy:
  // - If signal is good (> -70 dBm), don't even scan
  // - If signal is acceptable (-70 to -80 dBm), scan but don't switch
  // - If signal is degraded (< -80 dBm) AND another device is 25+ dBm better, switch
  // This prevents unnecessary switching when signal is already good
  async scanForBetterSignal() {
    if (!this.isAvailable() || !this.connectedDeviceId) {
      return;
    }

    // Cooldown: Don't switch if we just switched less than 2 minutes ago
    const timeSinceLastSwitch = Date.now() - this.lastSwitchTime;
    if (timeSinceLastSwitch < 120000) {
      return;
    }

    // Skip if we don't have a valid RSSI baseline to compare against
    // We need to do a quick scan of the CURRENT device to get its RSSI first
    if (this.currentRssi === null || this.currentRssi === undefined) {
      console.log('📡 No RSSI baseline - will update on next scan');
      // Don't return - we'll scan to get current device RSSI
    }

    // KEY CHANGE: Only consider switching if current signal is DEGRADED
    // If signal is good (> -70 dBm), don't even bother scanning for alternatives
    const GOOD_SIGNAL_THRESHOLD = -70; // dBm - above this, don't switch
    const DEGRADED_SIGNAL_THRESHOLD = -80; // dBm - below this, actively look for better
    const MIN_IMPROVEMENT = 25; // dBm - minimum improvement required to switch

    if (this.currentRssi !== null && this.currentRssi > GOOD_SIGNAL_THRESHOLD) {
      console.log(`✓ Signal good (${this.currentRssi} dBm > ${GOOD_SIGNAL_THRESHOLD} dBm) - no need to scan`);
      return;
    }

    // Only actively scan for alternatives if signal is degraded
    if (this.currentRssi !== null && this.currentRssi > DEGRADED_SIGNAL_THRESHOLD) {
      console.log(`📶 Signal acceptable (${this.currentRssi} dBm) - passive monitoring only`);
      // Still scan but with higher threshold for switching
    }

    console.log('🔍 Background scan: Looking for better signal (current:', this.currentRssi, 'dBm)');

    try {
      let bestDevice = null;
      let bestRssi = this.currentRssi || -100;
      const savedDevice = this.getSavedDevice();

      await BleClient.requestLEScan(
        { namePrefix: 'AirScale' },
        (result) => {
          const rssi = result.rssi || -100;
          const wifiMac = this.extractMacFromName(result.device.name);

          // If this is our currently connected device, update our RSSI baseline
          if (wifiMac === savedDevice.wifiMac) {
            this.currentRssi = rssi;
            console.log(`📡 Current device RSSI updated: ${rssi} dBm`);
            return;
          }

          // Check if this device has significantly better signal
          // Use the MIN_IMPROVEMENT from outer scope (25 dBm)
          if (rssi > bestRssi + MIN_IMPROVEMENT) {
            bestRssi = rssi;
            bestDevice = {
              deviceId: result.device.deviceId,
              name: result.device.name,
              wifiMac: wifiMac,
              rssi: rssi
            };
            console.log('📶 Found candidate:', result.device.name, 'at', rssi, 'dBm (vs current:', this.currentRssi, 'dBm)');
          }
        }
      );

      // Stop scan after 5 seconds
      setTimeout(async () => {
        try {
          await BleClient.stopLEScan();

          // Decision logic for switching:
          // 1. Must have found a candidate device
          // 2. Current signal must be DEGRADED (< -75 dBm)
          // 3. Candidate must be significantly better (25+ dBm improvement)
          const currentSignalDegraded = this.currentRssi === null || this.currentRssi < DEGRADED_SIGNAL_THRESHOLD;
          const candidateSignificantlyBetter = bestDevice && bestRssi > (this.currentRssi || -100) + MIN_IMPROVEMENT;

          if (!bestDevice) {
            console.log('✓ No alternative devices found');
          } else if (!currentSignalDegraded) {
            console.log(`✓ Current signal OK (${this.currentRssi} dBm) - not switching despite candidate at ${bestRssi} dBm`);
          } else if (!candidateSignificantlyBetter) {
            console.log(`✓ Candidate not significantly better (need ${MIN_IMPROVEMENT}+ dBm improvement)`);
          }

          // Only switch if current signal is degraded AND candidate is much better
          if (currentSignalDegraded && candidateSignificantlyBetter) {
            console.log('🔄 Auto-switching to better device:', bestDevice.name, '(', this.currentRssi, 'dBm →', bestRssi, 'dBm)');

            this.lastSwitchTime = Date.now();

            // Set flag to prevent disconnect callback from interfering
            this.isAutoSwitching = true;

            // Save previous device info in case new connection fails
            const previousDeviceId = localStorage.getItem('airscales_ble_device_id');
            const previousDeviceName = localStorage.getItem('airscales_ble_device_name');
            const previousWifiMac = localStorage.getItem('airscales_ble_wifi_mac');
            const previousRssi = this.currentRssi; // Save current RSSI

            try {
              // Disconnect and connect to better device
              await this.disconnect();
              await new Promise(resolve => setTimeout(resolve, 2000)); // Wait for BLE stack to settle

              // Verify device is still advertising before attempting connection
              console.log('🔍 Verifying device is still available...');
              let deviceStillAvailable = false;

              await BleClient.requestLEScan(
                { namePrefix: 'AirScale' },
                (result) => {
                  const wifiMac = this.extractMacFromName(result.device.name);
                  if (wifiMac === bestDevice.wifiMac) {
                    deviceStillAvailable = true;
                    // Update device ID in case it changed
                    bestDevice.deviceId = result.device.deviceId;
                    console.log('✅ Device still advertising:', result.device.name, 'RSSI:', result.rssi);
                  }
                }
              );

              // Stop scan after 3 seconds
              await new Promise(resolve => setTimeout(resolve, 3000));
              await BleClient.stopLEScan();

              if (!deviceStillAvailable) {
                throw new Error('Device no longer advertising (likely still in mesh slave mode)');
              }

              // Update expected RSSI before connection
              this.currentRssi = bestRssi;

              await this.connectToDevice(bestDevice.deviceId, bestDevice.name, bestDevice.wifiMac);
              console.log('✅ Auto-switch completed successfully');
            } catch (error) {
              console.error('❌ Auto-switch failed:', error);

              // Restore previous device info so reconnect goes to the old device
              if (previousDeviceId && previousDeviceName) {
                localStorage.setItem('airscales_ble_device_id', previousDeviceId);
                localStorage.setItem('airscales_ble_device_name', previousDeviceName);
                if (previousWifiMac) {
                  localStorage.setItem('airscales_ble_wifi_mac', previousWifiMac);
                }
                this.currentRssi = previousRssi; // Restore previous RSSI
                console.log('↩️ Restored previous device for reconnection');
              }
            } finally {
              // Always clear the flag
              this.isAutoSwitching = false;
            }
          }
          // Note: logging for non-switch cases is handled above
        } catch (error) {
          console.error('Error in background scan:', error);
        }
      }, 5000);

    } catch (error) {
      console.error('❌ Background scan failed:', error);
    }
  },

  // Enable/disable auto-switching (syncs with database)
  async setAutoSwitch(enabled) {
    this.autoSwitchEnabled = enabled;
    localStorage.setItem('airscales_auto_switch', enabled ? 'true' : 'false');
    console.log('🔧 Auto-switching', enabled ? 'enabled' : 'disabled');

    // Sync with database if user is logged in
    try {
      const response = await fetch('/api/settings/ble-auto-switch', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ enabled })
      });

      if (!response.ok) {
        console.warn('⚠️ Failed to sync auto-switch setting to database');
      } else {
        console.log('✅ Auto-switch preference saved to database');
      }
    } catch (error) {
      console.warn('⚠️ Could not sync auto-switch setting:', error.message);
    }
  },

  // Get auto-switch status (prefers database over localStorage)
  async getAutoSwitchEnabled() {
    // Try to load from database first
    try {
      const response = await fetch('/api/settings/ble-auto-switch');
      if (response.ok) {
        const data = await response.json();
        this.autoSwitchEnabled = data.ble_auto_switch;
        // Sync localStorage with database value
        localStorage.setItem('airscales_auto_switch', this.autoSwitchEnabled ? 'true' : 'false');
        console.log('✅ Loaded auto-switch preference from database:', this.autoSwitchEnabled);
        return this.autoSwitchEnabled;
      }
    } catch (error) {
      console.warn('⚠️ Could not load auto-switch setting from database, using localStorage');
    }

    // Fallback to localStorage
    const saved = localStorage.getItem('airscales_auto_switch');
    if (saved !== null) {
      this.autoSwitchEnabled = saved === 'true';
    }
    return this.autoSwitchEnabled;
  },

  // Stop background scanning
  stopBackgroundScanning() {
    if (this.backgroundScanInterval) {
      clearInterval(this.backgroundScanInterval);
      this.backgroundScanInterval = null;
      console.log('⏹️ Background scanning stopped');
    }
  },

  // Debug: Force a fresh scan (call from console)
  async debugScan() {
    console.log('🐛 DEBUG: Starting manual scan...');
    console.log('🐛 isCapacitor:', this.isCapacitor);
    console.log('🐛 isInitialized:', this.isInitialized);
    console.log('🐛 connectedDeviceId:', this.connectedDeviceId);

    if (!this.isAvailable()) {
      console.error('❌ BLE not available!');
      return;
    }

    try {
      console.log('🔍 Starting scan with namePrefix: "AirScale"');
      let devicesFound = 0;

      await BleClient.requestLEScan(
        { namePrefix: 'AirScale' },
        (result) => {
          devicesFound++;
          console.log(`✨ Device #${devicesFound}:`, {
            name: result.device.name,
            deviceId: result.device.deviceId,
            rssi: result.rssi
          });
        }
      );

      setTimeout(async () => {
        await BleClient.stopLEScan();
        console.log(`🏁 Scan complete. Found ${devicesFound} AirScale device(s)`);
      }, 5000);
    } catch (error) {
      console.error('❌ Debug scan failed:', error);
    }
  },

  // Debug: Complete BLE reset (call from console)
  async debugReset() {
    console.log('🔄 COMPLETE BLE RESET...');

    // Stop any active scans
    try {
      await BleClient.stopLEScan();
      console.log('✅ Stopped active scan');
    } catch (e) {
      console.log('⚠️ No active scan to stop');
    }

    // Disconnect if connected
    if (this.connectedDeviceId) {
      try {
        await this.disconnect();
        console.log('✅ Disconnected from device');
      } catch (e) {
        console.log('⚠️ Disconnect failed:', e.message);
      }
    }

    // Stop background scanning
    this.stopBackgroundScanning();
    console.log('✅ Stopped background scanning');

    // Clear all state
    this.connectedDeviceId = null;
    this.currentRssi = null;
    this.lastSwitchTime = 0;
    localStorage.removeItem('airscales_ble_device_id');
    localStorage.removeItem('airscales_ble_device_name');
    localStorage.removeItem('airscales_ble_wifi_mac');
    console.log('✅ Cleared all BLE state');

    // Restart discovery
    console.log('🔄 Restarting discovery...');
    await this.startAutoDiscovery();
    console.log('✅ Complete reset done!');
  },

  // Scan for devices without popup (like nRF Connect)
  // Returns discovered devices via callback, doesn't auto-connect
  async scanForDevices(onDeviceFound, durationMs = 10000) {
    if (!this.isAvailable()) {
      throw new Error('Bluetooth not available');
    }

    this.autoDiscoveryPaused = true;
    console.log('⏸️ Auto-discovery paused for manual scan');

    // Stop any ongoing scan + timers (critical!)
    await this.stopScan('manual scan starting');

    // IMPORTANT: Must disconnect to scan on this BLE plugin version
    if (this.connectedDeviceId) {
      console.log('⚠️ Disconnecting to enable scanning...');
      try {
        await this.disconnect();
        // Wait 500ms after disconnect for BLE stack to settle
        await new Promise(resolve => setTimeout(resolve, 500));
      } catch (e) {
        console.log('⚠️ Disconnect error (ignoring):', e);
      }
    }

    const discoveredDevices = new Map();

    try {
      await this.startScan({
        label: 'manual scan',
        durationMs,
        options: { namePrefix: 'AirScale' },
        onResult: (result) => {
          console.log('🔍 Device discovered:', result.device.name, result.device.deviceId, 'RSSI:', result.rssi);

          const wifiMac = this.extractMacFromName(result.device.name) || result.device.deviceId;
          const deviceInfo = {
            deviceId: result.device.deviceId,
            name: result.device.name,
            wifiMac,
            rssi: result.rssi ?? -100
          };

          const existing = discoveredDevices.get(wifiMac);
          if (!existing || Math.abs(existing.rssi - deviceInfo.rssi) > 5) {
            discoveredDevices.set(wifiMac, deviceInfo);
            console.log('📱 Found:', deviceInfo.name, 'RSSI:', deviceInfo.rssi, 'dBm');
            onDeviceFound(deviceInfo);
          }
        }
      });

      console.log(`✅ Manual scan started for ${durationMs / 1000}s`);
      console.log('▶️ Auto-discovery remains paused until user connects or closes scan');

      return true;
    } catch (error) {
      console.error('❌ Manual scan failed:', error);
      this.autoDiscoveryPaused = false;
      console.log('▶️ Auto-discovery resumed after scan error');
      throw error;
    }
  },

  // Resume auto-discovery (call this when closing scan modal without connecting)
  resumeAutoDiscovery() {
    this.autoDiscoveryPaused = false;
    console.log('▶️ Auto-discovery resumed');

    // If not connected, start auto-discovery immediately
    if (!this.isConnected()) {
      console.log('🔄 No active connection - starting auto-discovery...');
      this.startAutoDiscovery();
    }
  },

  // Connect to a device by its BLE deviceId (from scan results)
  async connectById(deviceId, deviceName, wifiMac) {
    // Resume auto-discovery when connecting (so it can retry if connection fails)
    this.autoDiscoveryPaused = false;
    console.log('▶️ Auto-discovery resumed for connection attempt');
    return await this.connectToDevice(deviceId, deviceName, wifiMac);
  },

  // ============================================================
  // OTA (Over-The-Air) Firmware Update Methods
  // ============================================================

  // OTA state
  otaInProgress: false,
  otaAborted: false,

  /**
   * Perform OTA firmware update via BLE
   * @param {string} firmwareUrl - URL to download firmware from (e.g., /api/firmware/download/1)
   * @param {function} onProgress - Callback with progress updates: { phase, percent, message }
   * @returns {Promise<boolean>} - true if successful
   */
  async performOtaUpdate(firmwareUrl, onProgress = () => {}) {
    if (!this.isConnected()) {
      throw new Error('No device connected');
    }

    if (this.otaInProgress) {
      throw new Error('OTA update already in progress');
    }

    this.otaInProgress = true;
    this.otaAborted = false;

    // Disable auto-switch during OTA to prevent background scanning from interfering
    const previousAutoSwitch = this.autoSwitchEnabled;
    if (previousAutoSwitch) {
      this.autoSwitchEnabled = false;
      console.log('⏸️ Auto-switch temporarily disabled for OTA');
    }

    try {
      // Phase 1: Download firmware
      onProgress({ phase: 'download', percent: 0, message: 'Downloading firmware...' });
      console.log('📥 Downloading firmware from:', firmwareUrl);

      const response = await fetch(firmwareUrl);
      if (!response.ok) {
        throw new Error(`Failed to download firmware: ${response.status}`);
      }

      const firmwareData = await response.arrayBuffer();
      const firmwareSize = firmwareData.byteLength;
      console.log(`📦 Firmware downloaded: ${firmwareSize} bytes`);

      onProgress({ phase: 'download', percent: 100, message: `Downloaded ${this.formatBytes(firmwareSize)}` });

      // Phase 2: Send start command
      onProgress({ phase: 'prepare', percent: 0, message: 'Preparing device for update...' });

      // Build start packet: [0x01, size (4 bytes little-endian)]
      const startPacket = new Uint8Array(5);
      startPacket[0] = OTA_CMD_START;
      startPacket[1] = firmwareSize & 0xFF;
      startPacket[2] = (firmwareSize >> 8) & 0xFF;
      startPacket[3] = (firmwareSize >> 16) & 0xFF;
      startPacket[4] = (firmwareSize >> 24) & 0xFF;

      await BleClient.write(
        this.connectedDeviceId,
        BLE_SERVICE_UUID,
        BLE_OTA_CHAR_UUID,
        startPacket
      );

      console.log('✅ OTA start command sent');
      await this.delay(100); // Small delay after start

      // Phase 3: Send firmware data in chunks
      // Compute optimal chunk size from negotiated MTU
      // MTU - 3 (ATT header) - 1 (OTA command byte) = usable payload
      const mtu = this.negotiatedMtu || 23;
      const maxChunk = Math.max(20, mtu - 3 - 1); // At least 20 bytes
      const chunkSize = Math.min(240, maxChunk);  // Cap at 240 for cross-stack safety
      const totalChunks = Math.ceil(firmwareSize / chunkSize);
      let sentBytes = 0;

      // Use writeWithoutResponse only if available AND we have high MTU
      // The ESP32 BLE stack can get overwhelmed even with high MTU if we send
      // too fast. With ~6000 chunks for a 1.4MB firmware, we need careful pacing.
      // Actually, for reliability, let's default to regular write() with ACK
      // and only use writeWithoutResponse if MTU is very high (250+)
      const hasWriteNoResponse = typeof BleClient.writeWithoutResponse === 'function';
      const useWriteNoResponse = hasWriteNoResponse && mtu >= 250;
      console.log(`📤 Sending ${totalChunks} chunks of ${chunkSize} bytes each (MTU: ${mtu}, writeNoResponse: ${useWriteNoResponse})...`);

      for (let i = 0; i < totalChunks; i++) {
        if (this.otaAborted) {
          throw new Error('OTA update aborted by user');
        }

        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, firmwareSize);
        const chunk = new Uint8Array(firmwareData.slice(start, end));

        // Build data packet: [0x02, ...data]
        const dataPacket = new Uint8Array(1 + chunk.length);
        dataPacket[0] = OTA_CMD_DATA;
        dataPacket.set(chunk, 1);

        // Use writeWithoutResponse for data packets if available AND MTU is good
        if (useWriteNoResponse) {
          await BleClient.writeWithoutResponse(
            this.connectedDeviceId,
            BLE_SERVICE_UUID,
            BLE_OTA_CHAR_UUID,
            dataPacket
          );
          // Tiny delay after each write to prevent rapid-fire flooding
          // This gives the ESP32 BLE stack time to queue the packet
          await this.delay(2);
        } else {
          await BleClient.write(
            this.connectedDeviceId,
            BLE_SERVICE_UUID,
            BLE_OTA_CHAR_UUID,
            dataPacket
          );
        }

        sentBytes += chunk.length;
        const percent = Math.round((sentBytes / firmwareSize) * 100);

        // Update progress every 5% or on last chunk
        if (percent % 5 === 0 || i === totalChunks - 1) {
          onProgress({
            phase: 'upload',
            percent,
            message: `Uploading... ${percent}% (${this.formatBytes(sentBytes)} / ${this.formatBytes(firmwareSize)})`
          });
        }

        // Flow control: pace writes to prevent ESP32 BLE queue overflow
        // The ESP32 writes each chunk to flash, which takes time. Without pacing,
        // the BLE stack buffer overflows and returns GATT_INTERNAL_ERROR (201).
        //
        // Strategy: use a small delay every N chunks. The ESP32 flash write is
        // about 10-20ms for 4KB pages. With 240-byte chunks, that's ~17 chunks per page.
        // We add a pause every 10 chunks to let flash writes complete.
        if (useWriteNoResponse) {
          // writeWithoutResponse is fast but can overwhelm the ESP32
          // Delay every 10 chunks to match flash page write timing
          if (i % 10 === 0 && i > 0) {
            await this.delay(25); // 25ms every 10 chunks (~250KB/s effective)
          }
        } else {
          // Regular write() has built-in ACK which provides natural pacing
          // But still add occasional delays for very long transfers
          if (i % 50 === 0 && i > 0) {
            await this.delay(10);
          }
        }
      }

      console.log('✅ All firmware data sent');

      // Phase 4: Send end command
      onProgress({ phase: 'verify', percent: 0, message: 'Verifying and installing...' });

      const endPacket = new Uint8Array([OTA_CMD_END]);

      // The ESP32 reboots immediately after receiving the end command,
      // which causes the write to fail with a timeout or disconnect error.
      // This is actually expected behavior - treat these errors as success.
      try {
        await BleClient.write(
          this.connectedDeviceId,
          BLE_SERVICE_UUID,
          BLE_OTA_CHAR_UUID,
          endPacket
        );
        console.log('✅ OTA end command acknowledged');
      } catch (endError) {
        // Timeout or disconnect during end command is expected - device is rebooting
        const errorMsg = endError?.message?.toLowerCase() || '';
        if (errorMsg.includes('timeout') || errorMsg.includes('disconnect') || errorMsg.includes('not connected')) {
          console.log('✅ OTA end command sent - device rebooting (connection lost as expected)');
        } else {
          // Unexpected error - rethrow
          throw endError;
        }
      }

      console.log('✅ OTA update complete - device will reboot');

      onProgress({ phase: 'complete', percent: 100, message: 'Update complete! Device is rebooting...' });

      // Clear connection state since device will reboot
      this.connectedDeviceId = null;
      this.currentRssi = null;

      // The device will reboot, wait a bit then try to reconnect
      // Note: The disconnect callback will likely trigger autoReconnect already,
      // so we check if we're already connected before attempting another reconnect
      setTimeout(async () => {
        if (!this.connectedDeviceId) {
          console.log('🔄 Attempting to reconnect after OTA...');
          await this.autoReconnect();
        } else {
          console.log('✅ Already reconnected after OTA');
        }
      }, 5000);

      return true;

    } catch (error) {
      console.error('❌ OTA update failed:', error);

      // Try to abort the OTA on the device
      if (this.connectedDeviceId) {
        try {
          const abortPacket = new Uint8Array([OTA_CMD_ABORT]);
          await BleClient.write(
            this.connectedDeviceId,
            BLE_SERVICE_UUID,
            BLE_OTA_CHAR_UUID,
            abortPacket
          );
          console.log('⚠️ OTA abort command sent');
        } catch (e) {
          // Ignore abort errors
        }
      }

      onProgress({ phase: 'error', percent: 0, message: `Update failed: ${error.message}` });
      throw error;

    } finally {
      this.otaInProgress = false;
      this.otaAborted = false;

      // Restore auto-switch setting
      if (previousAutoSwitch) {
        this.autoSwitchEnabled = true;
        console.log('▶️ Auto-switch re-enabled after OTA');
      }
    }
  },

  /**
   * Abort an in-progress OTA update
   */
  abortOtaUpdate() {
    if (this.otaInProgress) {
      console.log('⚠️ Aborting OTA update...');
      this.otaAborted = true;
    }
  },

  /**
   * Check if OTA is in progress
   */
  isOtaInProgress() {
    return this.otaInProgress;
  },

  /**
   * Helper: Format bytes to human readable
   */
  formatBytes(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' bytes';
  },

  /**
   * Helper: Delay for async operations
   */
  delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  },

  // Debug: Scan for ALL devices (no filter)
  async debugScanAll(durationMs = 5000) {
    if (!this.isAvailable()) {
      console.error('❌ BLE not available');
      return;
    }

    console.log('🐛 DEBUG: Scanning for ALL BLE devices...');
    const allDevices = [];

    try {
      await BleClient.stopLEScan();
    } catch (e) {}

    try {
      await BleClient.requestLEScan(
        {}, // No filter - scan for ALL devices
        (result) => {
          console.log('🐛 FOUND:', result.device.name || '(no name)', 'ID:', result.device.deviceId, 'RSSI:', result.rssi);
          allDevices.push(result.device.name || '(no name)');
        }
      );

      setTimeout(async () => {
        await BleClient.stopLEScan();
        console.log(`🐛 Scan complete. Found ${allDevices.length} total devices:`, allDevices);
      }, durationMs);
    } catch (error) {
      console.error('❌ Debug scan failed:', error);
    }
  }
};

// Make it globally available
window.AirScalesBLE = AirScalesBLE;

export default AirScalesBLE;
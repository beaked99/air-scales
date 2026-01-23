# Mesh Registration Implementation for Phone App

## Overview
The phone app needs to register the mesh topology with the server so the server knows which slave devices are connected to the hub. This enables the server to return slave device coefficients when the hub requests them.

## When to Call
- After BLE connects to hub
- When a new slave device is discovered
- Periodically (every 60 seconds) to keep server updated

## Implementation

### 1. Add Mesh Registration Function

Add this function to your phone app's JavaScript (likely in capacitor app or dashboard.js):

```javascript
/**
 * Register mesh topology with server
 * @param {string} hubMac - Hub device MAC address
 * @param {Array<string>} slaveMacs - Array of slave device MAC addresses
 */
async function registerMeshTopology(hubMac, slaveMacs) {
  try {
    const response = await fetch(`${SERVER_URL}/api/bridge/mesh/register`, {
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
        signal_strength: null  // Optional: could add WiFi/BLE signal strength
      })
    });

    if (!response.ok) {
      throw new Error(`Mesh registration failed: ${response.status}`);
    }

    const result = await response.json();
    console.log('✅ Mesh topology registered:', result);
    return result;

  } catch (error) {
    console.error('❌ Mesh registration error:', error);
    return null;
  }
}
```

### 2. Track Connected Devices

When receiving BLE data, track which devices are slaves:

```javascript
// Global tracking
let hubMAC = null;
let connectedSlaves = new Set();
let lastMeshRegistration = 0;

// When processing BLE sensor data
function handleBLESensorData(data) {
  const mac = data.mac_address?.toUpperCase();
  const role = data.role || 'device';

  if (role === 'hub' || role === 'master') {
    // This is the hub
    hubMAC = mac;
    console.log(`📡 Hub identified: ${hubMAC}`);
  } else if (role === 'device' || role === 'slave') {
    // This is a slave device
    if (!connectedSlaves.has(mac)) {
      connectedSlaves.add(mac);
      console.log(`📡 Slave discovered: ${mac}`);

      // Register immediately when new slave is found
      if (hubMAC) {
        registerMeshTopology(hubMAC, Array.from(connectedSlaves));
      }
    }
  }

  // ... rest of sensor data handling
}
```

### 3. Periodic Registration

Add periodic mesh registration to keep server updated:

```javascript
// In your main loop or interval
setInterval(() => {
  // Only register if we have a hub and at least one slave
  if (hubMAC && connectedSlaves.size > 0) {
    const now = Date.now();

    // Register every 60 seconds
    if (now - lastMeshRegistration > 60000) {
      registerMeshTopology(hubMAC, Array.from(connectedSlaves))
        .then(() => {
          lastMeshRegistration = now;
        });
    }
  }
}, 10000); // Check every 10 seconds
```

### 4. Request Coefficients After Registration

When uploading sensor data, include `request_coefficients: true`:

```javascript
async function uploadBLEDataToServer(sensorData) {
  try {
    const payload = {
      ...sensorData,
      request_coefficients: true  // Request coefficients for hub AND slaves
    };

    const response = await fetch(`${SERVER_URL}/api/bridge/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    if (!response.ok) {
      throw new Error(`Upload failed: ${response.status}`);
    }

    const result = await response.json();

    // Check if we received slave coefficients
    if (result.slave_coefficients) {
      console.log('📊 Received slave coefficients:', Object.keys(result.slave_coefficients));

      // Forward slave coefficients back to hub via BLE
      await sendCoefficientsToBLE(result.slave_coefficients);
    }

    return result;

  } catch (error) {
    console.error('❌ Upload error:', error);
    return null;
  }
}
```

### 5. Send Slave Coefficients to Hub via BLE

```javascript
/**
 * Send slave device coefficients to hub via BLE
 * Hub will forward these to slaves via ESP-NOW
 */
async function sendCoefficientsToBLE(slaveCoefficients) {
  if (!bluetoothDevice || !coeffsCharacteristic) {
    console.error('❌ BLE not connected');
    return;
  }

  for (const [slaveMac, slaveData] of Object.entries(slaveCoefficients)) {
    const channelCalibrations = slaveData.channel_calibrations;

    for (const [channelIndex, coeffs] of Object.entries(channelCalibrations)) {
      const payload = {
        target_mac: slaveMac,
        channel: parseInt(channelIndex),
        intercept: coeffs.intercept,
        air_pressure_coeff: coeffs.air_pressure_coeff,
        ambient_pressure_coeff: coeffs.ambient_pressure_coeff || 0,
        air_temp_coeff: coeffs.air_temp_coeff || 0
      };

      const jsonStr = JSON.stringify(payload);
      const encoder = new TextEncoder();
      const data = encoder.encode(jsonStr);

      try {
        await coeffsCharacteristic.writeValue(data);
        console.log(`✅ Sent CH${channelIndex} coefficients to ${slaveMac} via hub`);
        await new Promise(resolve => setTimeout(resolve, 100)); // Small delay between writes
      } catch (error) {
        console.error(`❌ Failed to send coefficients to ${slaveMac}:`, error);
      }
    }
  }
}
```

## Server Response Format

After calling `/api/bridge/register` with `request_coefficients: true`, expect:

```json
{
  "status": "data_received",
  "device_id": 19,
  "calculated_weight": 24166,
  "virtual_steer_weight": 24355,
  "timestamp": "2026-01-20 08:15:30",
  "channel_calibrations": {
    "1": {
      "intercept": 22282.912,
      "air_pressure_coeff": 133.689,
      "ambient_pressure_coeff": 0,
      "air_temp_coeff": 0
    }
  },
  "slave_coefficients": {
    "9C:13:9E:BA:DC:90": {
      "device_id": 18,
      "channel_calibrations": {
        "1": {
          "intercept": 15234.567,
          "air_pressure_coeff": 145.234,
          "ambient_pressure_coeff": 0,
          "air_temp_coeff": 0
        },
        "2": {
          "intercept": 18765.432,
          "air_pressure_coeff": 152.876,
          "ambient_pressure_coeff": 0,
          "air_temp_coeff": 0
        }
      }
    }
  }
}
```

## Complete Flow

```
1. Phone connects to Hub via BLE
2. Hub sends sensor data (hub + slaves) via BLE → Phone
3. Phone calls /api/bridge/mesh/register with hub MAC + slave MACs
4. Server stores mesh topology in database
5. Phone uploads sensor data with request_coefficients=true
6. Server returns hub coefficients + slave coefficients
7. Phone sends slave coefficients to Hub via BLE
8. Hub forwards slave coefficients to slaves via ESP-NOW
9. Slaves receive and store coefficients locally
10. Slaves calculate weights using stored coefficients
```

## Testing

To verify it's working:

1. Check console logs for "✅ Mesh topology registered"
2. Check server logs for "📡 BridgeAPI: Including coefficients for slave device"
3. Verify slave devices show calculated weights (not 0 lbs)
4. Check database: `SELECT current_role, connected_slaves FROM device WHERE id = 19`

## Notes

- The phone app is the orchestrator - it manages the mesh topology
- The hub firmware doesn't need WiFi - everything goes through phone
- Mesh registration should happen before requesting coefficients
- Server automatically includes slave coefficients if mesh is registered

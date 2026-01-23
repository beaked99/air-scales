#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPIFFS.h>
#include <Preferences.h>
#include <BLEDevice.h>
#include <BLEServer.h>
#include <BLEUtils.h>
#include <BLE2902.h>
#include <esp_now.h>
#include <esp_wifi.h>
#include <esp_bt.h>
#include <esp_gatt_common_api.h>  // esp_ble_gatt_set_local_mtu
#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BME280.h>
#include <Adafruit_NeoPixel.h>
#include <Update.h>      // ESP32 OTA library
#include <esp_ota_ops.h>  // OTA partition operations

// ============================================================
// CONFIGURATION
// ============================================================

// Firmware Version
#define FIRMWARE_VERSION "0.0.9"
#define FIRMWARE_DATE "2026-01-22T18:41:00Z"
// this seems good now?
// Hardware Configuration - CUSTOM AIR SCALES BOARD
#define WS2812B_PIN 10
#define I2C_SDA 48
#define I2C_SCL 47

// Pressure Sensor ADC Pins (virtual for now)
#define PRESSURE_SENSOR_CH1_PIN 34  // Channel 1 - Axle Group 1
#define PRESSURE_SENSOR_CH2_PIN 35  // Channel 2 - Axle Group 2

// BLE configuration
#define SERVICE_UUID        "12345678-1234-1234-1234-123456789abc"
#define SENSOR_CHAR_UUID    "87654321-4321-4321-4321-cba987654321"
#define COEFFS_CHAR_UUID    "11111111-2222-3333-4444-555555555555"
#define OTA_CHAR_UUID       "22222222-3333-4444-5555-666666666666"  // OTA firmware updates
#define DEVICE_NAME_PREFIX  "AirScale-"

// ESP-NOW Configuration - FIXED CHANNEL (no WiFi required)
#define ESPNOW_CHANNEL 1

// Server Configuration (only used when WiFi available)
const char* SERVER_URL = "https://beaker.ca";

// Optional WiFi (for home testing/development only)
const char* FALLBACK_SSID = "";     // Leave empty for truck deployment
const char* FALLBACK_PASSWORD = ""; // Leave empty for truck deployment

// Timing Configuration
#define BROADCAST_INTERVAL_MS   10000  // Slaves broadcast every 10 seconds
#define HUB_SEND_INTERVAL_MS    5000   // Hub sends to phone every 5 seconds
#define DEVICE_TIMEOUT_MS       120000 // Mark device inactive after 2 minutes

// ============================================================
// GLOBAL OBJECTS
// ============================================================

// Late-init objects (pointers) to avoid pre-setup() crashes
// HTTPClient removed as global - use local instances when needed
WebServer* server = nullptr;
Preferences preferences;
Adafruit_BME280 bme;
Adafruit_NeoPixel* pixel = nullptr;

// BLE Global Objects
BLEServer* pServer = nullptr;
BLEAdvertising* g_adv = nullptr;  // Single advertising instance - use this everywhere
BLECharacteristic* pSensorCharacteristic = nullptr;
BLECharacteristic* pCoeffsCharacteristic = nullptr;
BLECharacteristic* pOtaCharacteristic = nullptr;
bool deviceConnected = false;
bool bleEnabled = false;
String bleDeviceName;

// OTA Update State
bool otaInProgress = false;
size_t otaTotalSize = 0;
size_t otaReceived = 0;
uint32_t otaStartTime = 0;
int otaChunkCount = 0;  // For debug logging of first few chunks

// Device State
String deviceMAC;
String apSSID;
bool isConnectedToWiFi = false;
bool isHub = false;
bool bmeInitialized = false;

// BLE discovery quiet window (helps BLE scans win airtime vs ESP-NOW)
static unsigned long g_lastDiscoveryWindowStart = 0;
static bool g_discoveryQuiet = false;

// Promiscuous mode state
static bool g_promiscuousModeEnabled = false;

// RSSI sentinel when promiscuous is off / unavailable
static constexpr int8_t RSSI_UNKNOWN = -127;

// Store last received RSSI (captured via promiscuous mode callback)
static int8_t lastReceivedRssi = RSSI_UNKNOWN;

// Mesh activity tracking - if we haven't received ESP-NOW data in X seconds, assume mesh is dead
static unsigned long g_lastMeshActivity = 0;
static constexpr uint32_t MESH_TIMEOUT_MS = 60000; // 60 seconds

// ============================================================
// DATA STRUCTURES
// ============================================================

// ESP-NOW Data Structure (sensor data AND coefficient updates)
typedef struct {
  char deviceMAC[18];
  char deviceName[32];
  float ch1AirPressure;       // Channel 1 - Axle Group 1
  float ch2AirPressure;       // Channel 2 - Axle Group 2
  float atmosphericPressure;
  float temperature;
  float elevation;
  float ch1Weight;            // Weight from Channel 1
  float ch2Weight;            // Weight from Channel 2
  float totalWeight;          // Combined weight
  uint32_t timestamp;
  uint8_t batteryLevel;
  bool isCharging;
  uint8_t messageType;        // 0 = sensor data, 1 = ch1 coefficients, 2 = ch2 coefficients
} ESPNowData;

#define MSG_TYPE_SENSOR_DATA 0
#define MSG_TYPE_COEFFICIENTS_CH1 1
#define MSG_TYPE_COEFFICIENTS_CH2 2

// Device tracking
#define MAX_DEVICES 10
struct DeviceData {
  char macAddress[18];
  char deviceName[32];
  ESPNowData lastData;
  unsigned long lastSeen;
  bool isActive;
  int8_t espNowRssi;          // ESP-NOW signal strength (dBm)
} knownDevices[MAX_DEVICES];
int deviceCount = 0;

// Sensor Data Structure
struct SensorData {
  float ch1AirPressure;       // Channel 1 - Axle Group 1
  float ch2AirPressure;       // Channel 2 - Axle Group 2
  float atmosphericPressure;
  float temperature;
  float elevation;
  float ch1Weight;            // Weight from Channel 1
  float ch2Weight;            // Weight from Channel 2
  float totalWeight;          // Combined weight
  String timestamp;
};

// Regression Coefficients Structure (per channel)
struct RegressionCoeffs {
  float intercept = 0.0;
  float airPressureCoeff = 0.0;
  float ambientPressureCoeff = 0.0;
  float airTempCoeff = 0.0;
};

RegressionCoeffs ch1Coeffs;  // Channel 1 - Axle Group 1
RegressionCoeffs ch2Coeffs;  // Channel 2 - Axle Group 2

// LED Status Colors
enum LEDStatus {
  LED_OFF,
  LED_BOOTING,      // Blue pulse
  LED_STANDALONE,   // Red slow pulse (no hub connection)
  LED_HUB_MODE,     // Purple pulse (I am the hub)
  LED_TRANSMITTING, // Quick white flash
  LED_CONNECTED     // Green solid (slave connected to hub)
};

LEDStatus currentLEDStatus = LED_OFF;

// ============================================================
// FUNCTION DECLARATIONS
// ============================================================

void initESPNow();
void promiscuousRxCallback(void *buf, wifi_promiscuous_pkt_type_t type);
void onESPNowDataReceived(const uint8_t *mac_addr, const uint8_t *incomingData, int len);
void onESPNowDataSent(const uint8_t *mac_addr, esp_now_send_status_t status);
void broadcastMyData();
void sendAllDataViaBLE();
void sendCoeffsToDevice(const char* targetMAC, RegressionCoeffs* targetCoeffs, int channel);
SensorData readSensors();
float simulatePressure(int channel);
String getCurrentTimestamp();
void initBLE();
void updateDeviceData(ESPNowData* data, int8_t rssi);
DeviceData* findDevice(const char* mac);
void initBME280();
void setLEDStatus(LEDStatus status);
void updateLED();
void tryConnectWiFi();
void setupWebServer();

// ============================================================
// BLE CALLBACKS
// ============================================================

class MyServerCallbacks: public BLEServerCallbacks {
  void onConnect(BLEServer* pServer) {
    deviceConnected = true;
    isHub = true;  // BLE connection makes me the hub!
    Serial.println("🔵 BLE Client Connected - I AM NOW THE HUB!");
    setLEDStatus(LED_HUB_MODE);
  }

  void onDisconnect(BLEServer* pServer) {
    deviceConnected = false;
    isHub = false;  // No longer a hub when disconnected
    Serial.println("🔵 BLE Client Disconnected - No longer hub");

    // Restart advertising using global instance
    if (bleEnabled && g_adv) {
      delay(200);
      g_adv->start();
      Serial.println("📡 BLE advertising restarted");
    }

    setLEDStatus(LED_STANDALONE);
  }
};

class CoeffsCallbacks: public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* pCharacteristic) {
    std::string rxValue = pCharacteristic->getValue();

    if (rxValue.length() > 0) {
      Serial.println("📥 Received coefficients via BLE");
      Serial.println(rxValue.c_str());

      DynamicJsonDocument doc(512);
      DeserializationError error = deserializeJson(doc, rxValue.c_str());

      if (error) {
        Serial.printf("❌ JSON parse error: %s\n", error.c_str());
        return;
      }

      // Check if this is for a specific device and channel
      const char* targetMac = doc["target_mac"] | "";
      int channel = doc["channel"] | 1;  // Default to channel 1

      // Extract coefficients from JSON
      RegressionCoeffs newCoeffs;
      newCoeffs.intercept = doc["intercept"] | 0.0;
      newCoeffs.airPressureCoeff = doc["air_pressure_coeff"] | 0.0;
      newCoeffs.ambientPressureCoeff = doc["ambient_pressure_coeff"] | 0.0;
      newCoeffs.airTempCoeff = doc["air_temp_coeff"] | 0.0;

      Serial.printf("📊 CH%d Coefficients: intercept=%.4f, air=%.4f, ambient=%.4f, temp=%.4f\n",
                   channel, newCoeffs.intercept, newCoeffs.airPressureCoeff,
                   newCoeffs.ambientPressureCoeff, newCoeffs.airTempCoeff);
      Serial.printf("🎯 Target MAC: '%s'\n", targetMac);

      // Is this for me (the hub) or no target specified?
      if (strlen(targetMac) == 0 || strcasecmp(targetMac, deviceMAC.c_str()) == 0) {
        // Save locally to appropriate channel
        if (channel == 1) {
          ch1Coeffs = newCoeffs;
          preferences.putFloat("ch1_intercept", ch1Coeffs.intercept);
          preferences.putFloat("ch1_air_coeff", ch1Coeffs.airPressureCoeff);
          preferences.putFloat("ch1_amb_coeff", ch1Coeffs.ambientPressureCoeff);
          preferences.putFloat("ch1_temp_coeff", ch1Coeffs.airTempCoeff);
          Serial.println("✅ Saved CH1 coefficients locally");
        } else {
          ch2Coeffs = newCoeffs;
          preferences.putFloat("ch2_intercept", ch2Coeffs.intercept);
          preferences.putFloat("ch2_air_coeff", ch2Coeffs.airPressureCoeff);
          preferences.putFloat("ch2_amb_coeff", ch2Coeffs.ambientPressureCoeff);
          preferences.putFloat("ch2_temp_coeff", ch2Coeffs.airTempCoeff);
          Serial.println("✅ Saved CH2 coefficients locally");
        }
      } else {
        // Forward to slave device via ESP-NOW
        Serial.printf("📡 Forwarding CH%d coefficients to slave: %s\n", channel, targetMac);
        sendCoeffsToDevice(targetMac, &newCoeffs, channel);
      }
    }
  }
};

// ============================================================
// OTA UPDATE CALLBACKS
// ============================================================

class OtaCallbacks: public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic* pCharacteristic) {
    std::string rxValue = pCharacteristic->getValue();

    if (rxValue.length() == 0) return;

    uint8_t* data = (uint8_t*)rxValue.data();
    size_t len = rxValue.length();

    // Command packet: first byte indicates command type
    // 0x01 = Start OTA (followed by 4-byte size)
    // 0x02 = Data chunk
    // 0x03 = End OTA / Verify
    // 0x04 = Abort OTA

    uint8_t cmd = data[0];

    switch (cmd) {
      case 0x01: {  // Start OTA
        if (len < 5) {
          Serial.println("❌ OTA start packet too short");
          return;
        }

        // Extract firmware size (4 bytes, little-endian) with explicit casts
        otaTotalSize =
          (uint32_t)data[1] |
          ((uint32_t)data[2] << 8) |
          ((uint32_t)data[3] << 16) |
          ((uint32_t)data[4] << 24);

        // Sanity check: reject obviously invalid sizes
        if (otaTotalSize < 4096 || otaTotalSize > (14UL * 1024UL * 1024UL)) {
          Serial.printf("❌ OTA invalid size: %u bytes (must be 4KB-14MB)\n", (unsigned)otaTotalSize);
          otaInProgress = false;
          return;
        }

        otaReceived = 0;
        otaStartTime = millis();

        Serial.printf("📦 OTA Start: expecting %u bytes\n", (unsigned)otaTotalSize);

        // Begin OTA update
        if (!Update.begin(otaTotalSize)) {
          Serial.printf("❌ OTA begin failed: %s\n", Update.errorString());
          otaInProgress = false;
          return;
        }

        otaInProgress = true;
        otaChunkCount = 0;  // Reset chunk counter for debug logging
        setLEDStatus(LED_BOOTING);  // Show update in progress

        // Log partition and heap info for debugging
        const esp_partition_t* otaPart = esp_ota_get_next_update_partition(NULL);
        if (otaPart) {
          Serial.printf("📦 OTA target partition: %s size=%uKB\n",
                        otaPart->label, (unsigned)(otaPart->size / 1024));
        }
        Serial.printf("📦 Free heap at OTA start: %u bytes\n", (unsigned)ESP.getFreeHeap());

        // Disable WiFi/ESP-NOW during OTA to reduce BLE/WiFi coexistence throttling
        // This gives BLE maximum bandwidth for faster transfers
        esp_now_deinit();
        WiFi.mode(WIFI_MODE_NULL);
        Serial.println("📡 WiFi/ESP-NOW disabled for OTA speed");

        Serial.println("✅ OTA update started");
        break;
      }

      case 0x02: {  // Data chunk
        if (!otaInProgress) {
          Serial.println("❌ OTA data received but not in progress");
          return;
        }

        // Write data (skip command byte) with error checking
        size_t toWrite = len - 1;

        // Debug: log first few chunk sizes to detect MTU issues
        if (otaChunkCount < 5) {
          Serial.printf("📦 OTA chunk #%d: %u bytes\n", otaChunkCount, (unsigned)toWrite);
        }
        otaChunkCount++;

        size_t written = Update.write(data + 1, toWrite);

        if (written != toWrite) {
          Serial.printf("❌ OTA short write: wrote %u of %u (%s)\n",
                        (unsigned)written, (unsigned)toWrite, Update.errorString());
          otaInProgress = false;
          Update.abort();
          setLEDStatus(LED_STANDALONE);
          return;
        }

        otaReceived += written;

        // Progress update every 10KB
        if (otaReceived % 10240 < toWrite) {
          int progress = (otaReceived * 100) / otaTotalSize;
          Serial.printf("📥 OTA Progress: %d%% (%u/%u bytes)\n", progress, otaReceived, otaTotalSize);
        }
        break;
      }

      case 0x03: {  // End OTA
        if (!otaInProgress) {
          Serial.println("❌ OTA end received but not in progress");
          return;
        }

        // Verify we received exactly what was expected
        if (otaReceived != otaTotalSize) {
          Serial.printf("❌ OTA size mismatch: got %u expected %u\n",
                        (unsigned)otaReceived, (unsigned)otaTotalSize);
          otaInProgress = false;
          Update.abort();
          setLEDStatus(LED_STANDALONE);
          return;
        }

        if (Update.end(true)) {  // true = set as boot partition
          uint32_t duration = millis() - otaStartTime;
          Serial.printf("✅ OTA Complete! %u bytes in %u ms\n", otaReceived, duration);
          Serial.println("🔄 Rebooting in 2 seconds...");

          otaInProgress = false;

          // Send success response via BLE before reboot
          delay(500);

          // Reboot to new firmware
          delay(1500);
          ESP.restart();
        } else {
          Serial.printf("❌ OTA end failed: %s\n", Update.errorString());
          otaInProgress = false;
          setLEDStatus(LED_STANDALONE);
        }
        break;
      }

      case 0x04: {  // Abort OTA
        if (otaInProgress) {
          Update.abort();
          otaInProgress = false;
          otaReceived = 0;
          otaTotalSize = 0;
          Serial.println("⚠️ OTA aborted by user");
          setLEDStatus(LED_STANDALONE);

          // Restore WiFi/ESP-NOW after abort
          WiFi.mode(WIFI_STA);
          WiFi.setSleep(true);
          esp_wifi_set_ps(WIFI_PS_MIN_MODEM);
          initESPNow();
          Serial.println("📡 WiFi/ESP-NOW restored after OTA abort");
        }
        break;
      }

      default:
        Serial.printf("❌ Unknown OTA command: 0x%02X\n", cmd);
        break;
    }
  }
};

// ============================================================
// SETUP
// ============================================================

void setup() {
  Serial.begin(115200);
  delay(1000);
  
  Serial.println("\n\n🚀 ========================================");
  Serial.println("🚀 AirScale Firmware v4.0 (No WiFi Required)");
  Serial.println("🚀 ========================================\n");

  // PSRAM sanity check
  if (psramFound()) {
    Serial.printf("✅ PSRAM OK: %u bytes\n", ESP.getPsramSize());
  } else {
    Serial.println("❌ PSRAM NOT FOUND");
  }

  // OTA partition check - verify we have proper OTA layout
  const esp_partition_t* running = esp_ota_get_running_partition();
  const esp_partition_t* nextOta = esp_ota_get_next_update_partition(NULL);
  if (running) {
    Serial.printf("📦 Running partition: %s @0x%08X size=%uKB\n",
                  running->label, (unsigned)running->address, (unsigned)(running->size / 1024));
  }
  if (nextOta) {
    Serial.printf("📦 Next OTA partition: %s @0x%08X size=%uKB\n",
                  nextOta->label, (unsigned)nextOta->address, (unsigned)(nextOta->size / 1024));
  } else {
    Serial.println("⚠️ No OTA partition available - OTA updates will fail!");
  }

  // *** CRITICAL: Initialize WiFi BEFORE getting MAC address ***
  WiFi.mode(WIFI_STA);

  // Now safe to initialize objects that may depend on system being ready
  server = new WebServer(80);
  pixel = new Adafruit_NeoPixel(1, WS2812B_PIN, NEO_GRB + NEO_KHZ800);

  // Initialize LED
  pixel->begin();
  pixel->setBrightness(255); // was set to 50 on 3.3V but way too dim
  setLEDStatus(LED_BOOTING);

  delay(100);

  // Get MAC address (WiFi must be in STA mode briefly for this)
  deviceMAC = WiFi.macAddress();
  apSSID = "AirScale-" + deviceMAC;
  bleDeviceName = DEVICE_NAME_PREFIX + deviceMAC;
  
  Serial.println("📱 Device MAC: " + deviceMAC);
  Serial.println("📱 BLE Name: " + bleDeviceName);
  
  // Initialize SPIFFS
  if (!SPIFFS.begin(true)) {
    Serial.println("⚠️ SPIFFS Mount Failed");
  }
  
  // Initialize preferences and load saved coefficients for both channels
  preferences.begin("airscale", false);

  // Channel 1 coefficients
  ch1Coeffs.intercept = preferences.getFloat("ch1_intercept", 0.0);
  ch1Coeffs.airPressureCoeff = preferences.getFloat("ch1_air_coeff", 0.0);
  ch1Coeffs.ambientPressureCoeff = preferences.getFloat("ch1_amb_coeff", 0.0);
  ch1Coeffs.airTempCoeff = preferences.getFloat("ch1_temp_coeff", 0.0);

  // Channel 2 coefficients
  ch2Coeffs.intercept = preferences.getFloat("ch2_intercept", 0.0);
  ch2Coeffs.airPressureCoeff = preferences.getFloat("ch2_air_coeff", 0.0);
  ch2Coeffs.ambientPressureCoeff = preferences.getFloat("ch2_amb_coeff", 0.0);
  ch2Coeffs.airTempCoeff = preferences.getFloat("ch2_temp_coeff", 0.0);

  Serial.printf("📊 CH1 coefficients: intercept=%.4f, air=%.4f, ambient=%.4f, temp=%.4f\n",
               ch1Coeffs.intercept, ch1Coeffs.airPressureCoeff,
               ch1Coeffs.ambientPressureCoeff, ch1Coeffs.airTempCoeff);
  Serial.printf("📊 CH2 coefficients: intercept=%.4f, air=%.4f, ambient=%.4f, temp=%.4f\n",
               ch2Coeffs.intercept, ch2Coeffs.airPressureCoeff,
               ch2Coeffs.ambientPressureCoeff, ch2Coeffs.airTempCoeff);
  
  // Start as non-hub (will become hub when BLE connects)
  isHub = false;
  isConnectedToWiFi = false;

  // Disable WiFi temporarily for clean BLE initialization (avoids coex conflicts)
  WiFi.mode(WIFI_MODE_NULL);
  delay(50);

  // Initialize BLE FIRST (with WiFi off to avoid coex issues)
  initBLE();

  // Now re-enable WiFi for ESP-NOW
  WiFi.mode(WIFI_STA);

  // REQUIRED: Enable modem sleep when WiFi + BLE are both enabled (prevents abort)
  WiFi.setSleep(true);
  esp_wifi_set_ps(WIFI_PS_MIN_MODEM);
  Serial.println("✅ WiFi re-enabled with modem sleep (required for WiFi+BLE coexistence)");

  // Try WiFi connection (optional - skip if no credentials)
  tryConnectWiFi();

  // Setup web server
  setupWebServer();

  // Initialize ESP-NOW with fixed channel
  initESPNow();

  // Initialize I2C and BME280 LAST to avoid conflicts with WiFi/BLE initialization
  // Use setPins() instead of begin() - the BME280 library will call Wire.begin() internally
  Wire.setPins(I2C_SDA, I2C_SCL);
  initBME280();

  // Set initial LED status
  setLEDStatus(LED_STANDALONE);
  
  Serial.println("\n✅ ========================================");
  Serial.println("✅ AirScale Ready!");
  Serial.println("✅ Waiting for BLE connection to become hub");
  Serial.println("✅ Broadcasting sensor data via ESP-NOW");
  Serial.println("✅ ========================================\n");
}

// ============================================================
// MAIN LOOP
// ============================================================

void loop() {
  if (server) server->handleClient();
  updateLED();

  // During OTA, freeze all radio gymnastics (ESP-NOW, advertising toggles, etc.)
  // This prevents interference with the firmware stream
  if (otaInProgress) {
    delay(2);
    yield();  // Let BLE stack process incoming OTA packets
    return;
  }

  // Status debug output every 30 seconds
  static unsigned long lastDebugPrint = 0;
  if (millis() - lastDebugPrint > 30000) {
    uint8_t currentChannel;
    wifi_second_chan_t dummy;
    esp_wifi_get_channel(&currentChannel, &dummy);
    
    Serial.printf("\n📊 STATUS: %s | Ch:%d | RAM:%d | Devices:%d | BLE:%s | BME280:%s\n", 
                 isHub ? "HUB" : "DEVICE", 
                 currentChannel, 
                 ESP.getFreeHeap(), 
                 deviceCount,
                 deviceConnected ? "Connected" : "Waiting",
                 bmeInitialized ? "OK" : "FAIL");
    
    if (deviceCount > 0) {
      Serial.println("📡 Known devices:");
      for (int i = 0; i < deviceCount; i++) {
        if (knownDevices[i].isActive) {
          unsigned long age = millis() - knownDevices[i].lastSeen;
          Serial.printf("   %d: %s | CH1=%.1f | CH2=%.1f | Total=%.1f lbs | RSSI=%d dBm | %lu ms ago\n",
                       i, knownDevices[i].macAddress,
                       knownDevices[i].lastData.ch1Weight,
                       knownDevices[i].lastData.ch2Weight,
                       knownDevices[i].lastData.totalWeight,
                       knownDevices[i].espNowRssi,
                       age);
        }
      }
    }
    
    lastDebugPrint = millis();
  }
  
  // BLE advertising watchdog - keep device discoverable when not connected
  // This compensates for WiFi/ESP-NOW coexistence suppressing BLE advertising
  // Relaxed to 20s since discovery window already restarts advertising every 6s
  static unsigned long lastAdvCheck = 0;
  if (bleEnabled && !deviceConnected && millis() - lastAdvCheck > 20000) {
    if (g_adv) g_adv->start();
    Serial.println("📡 BLE watchdog: g_adv->start()");
    lastAdvCheck = millis();
  }

  // Gate promiscuous mode: only enable when hub (reduces WiFi workload, improves BLE)
  // When standalone, we don't need RSSI values from other devices
  if (isHub && !g_promiscuousModeEnabled) {
    esp_wifi_set_promiscuous_rx_cb(promiscuousRxCallback); // (re)attach callback
    esp_wifi_set_promiscuous(true);
    g_promiscuousModeEnabled = true;
    Serial.println("📡 Promiscuous mode enabled (hub mode)");
  } else if (!isHub && g_promiscuousModeEnabled) {
    esp_wifi_set_promiscuous(false);
    esp_wifi_set_promiscuous_rx_cb(nullptr); // optional cleanup
    g_promiscuousModeEnabled = false;
    Serial.println("📡 Promiscuous mode disabled (standalone mode - better BLE coexistence)");
  }

  // Check for mesh timeout - if we haven't received ESP-NOW data in 60s, assume mesh is dead
  // This allows devices to become discoverable again after the hub disconnects
  bool meshActive = (g_lastMeshActivity > 0) && (millis() - g_lastMeshActivity < MESH_TIMEOUT_MS);
  if (!meshActive && g_lastMeshActivity > 0) {
    Serial.println("⏰ Mesh timeout - no ESP-NOW data in 60s, enabling BLE discovery");

    // CRITICAL: Force BLE advertising restart to ensure discoverability
    // The BLE stack sometimes needs a full stop/start cycle after being suppressed by ESP-NOW
    if (bleEnabled && g_adv) {
      g_adv->stop();
      delay(100);
      g_adv->start();
      Serial.println("🔵 BLE advertising force-restarted after mesh timeout");
    }

    g_lastMeshActivity = 0; // Reset so we don't print this every loop
  }

  // BLE discovery quiet window (Android/Capacitor tuned):
  // Every ~6s while NOT connected over BLE AND (no active mesh OR mesh timed out), pause ESP-NOW TX for ~1.2s and restart advertising once.
  // This makes Android BLE scanning feel instant by giving frequent clean scan windows.
  static unsigned long lastDiscoveryKick = 0;

  const uint32_t DISCOVERY_KICK_MS  = 6000;   // how often we create a scan-friendly gap
  const uint32_t DISCOVERY_QUIET_MS = 1200;   // how long we pause ESP-NOW TX

  // Only run discovery windows if NOT connected AND (never been in mesh OR mesh timed out)
  if (bleEnabled && !deviceConnected && !meshActive) {
    if (!g_discoveryQuiet && (millis() - lastDiscoveryKick > DISCOVERY_KICK_MS)) {
      g_discoveryQuiet = true;
      g_lastDiscoveryWindowStart = millis();
      lastDiscoveryKick = millis();

      // Force stop/start cycle using global advertising instance
      if (g_adv) {
        g_adv->stop();
        delay(30);
        g_adv->start();
      }
      Serial.println("📡 BLE discovery window: quiet ESP-NOW TX ~1.2s + restart advertising");
    }

    if (g_discoveryQuiet && (millis() - g_lastDiscoveryWindowStart > DISCOVERY_QUIET_MS)) {
      g_discoveryQuiet = false;
      Serial.println("📡 BLE discovery window: resume ESP-NOW TX");
    }
  } else {
    g_discoveryQuiet = false;
  }

  // ALL devices broadcast their sensor data via ESP-NOW
  // Broadcast slower when not connected to save airtime for BLE advertising
  static unsigned long lastBroadcast = 0;
  uint32_t broadcastInterval = deviceConnected ? BROADCAST_INTERVAL_MS : 30000;  // 30s when standalone
  if (!g_discoveryQuiet && (millis() - lastBroadcast > broadcastInterval)) {
    setLEDStatus(LED_TRANSMITTING);
    broadcastMyData();
    delay(50);
    setLEDStatus(isHub ? LED_HUB_MODE : LED_STANDALONE);
    lastBroadcast = millis();
  }

  // Hub sends all collected data via BLE
  if (isHub && deviceConnected) {
    static unsigned long lastBLESend = 0;
    if (millis() - lastBLESend > HUB_SEND_INTERVAL_MS) {
      setLEDStatus(LED_TRANSMITTING);
      sendAllDataViaBLE();
      delay(50);
      setLEDStatus(LED_HUB_MODE);
      lastBLESend = millis();
    }
  }
  
  // Clean up old devices
  static unsigned long lastCleanup = 0;
  if (millis() - lastCleanup > 60000) {
    for (int i = 0; i < deviceCount; i++) {
      if (millis() - knownDevices[i].lastSeen > DEVICE_TIMEOUT_MS) {
        if (knownDevices[i].isActive) {
          Serial.printf("⚠️ Device %s marked inactive (timeout)\n", knownDevices[i].macAddress);
          knownDevices[i].isActive = false;
        }
      }
    }
    lastCleanup = millis();
  }
  
  delay(10);
}

// ============================================================
// ESP-NOW FUNCTIONS
// ============================================================

void initESPNow() {
  Serial.println("\n📡 Initializing ESP-NOW...");
  
  // WiFi.mode(WIFI_STA) already called in setup()
  
  // If not connected to WiFi, set fixed channel
  if (!isConnectedToWiFi) {
    WiFi.disconnect();
    delay(100);
    
    // Force to fixed channel
    esp_wifi_set_promiscuous(true);
    esp_wifi_set_channel(ESPNOW_CHANNEL, WIFI_SECOND_CHAN_NONE);
    esp_wifi_set_promiscuous(false);

    // Ensure clean state after channel set
    g_promiscuousModeEnabled = false;
    lastReceivedRssi = RSSI_UNKNOWN;

    Serial.printf("📡 ESP-NOW set to fixed channel: %d\n", ESPNOW_CHANNEL);
  } else {
    Serial.printf("📡 ESP-NOW using WiFi channel: %d\n", WiFi.channel());
  }
  
  // Verify channel
  uint8_t actualChannel;
  wifi_second_chan_t dummy;
  esp_wifi_get_channel(&actualChannel, &dummy);
  Serial.printf("📡 Actual channel: %d\n", actualChannel);
  
  // Initialize ESP-NOW
  if (esp_now_init() != ESP_OK) {
    Serial.println("❌ ESP-NOW initialization failed!");
    return;
  }

  // Promiscuous mode is expensive and increases WiFi workload, which can starve BLE
  // We'll enable it dynamically only when acting as a hub
  esp_wifi_set_promiscuous_rx_cb(nullptr);
  Serial.println("📡 Promiscuous mode will be enabled only when hub (connected via BLE)");

  // Register callbacks
  esp_now_register_send_cb(onESPNowDataSent);
  esp_now_register_recv_cb(onESPNowDataReceived);

  Serial.printf("✅ ESP-NOW initialized | Channel: %d | RAM: %d bytes\n",
                actualChannel, ESP.getFreeHeap());
}

// Promiscuous mode callback to capture RSSI
void promiscuousRxCallback(void *buf, wifi_promiscuous_pkt_type_t type) {
  if (type == WIFI_PKT_MGMT || type == WIFI_PKT_DATA) {
    wifi_promiscuous_pkt_t *pkt = (wifi_promiscuous_pkt_t *)buf;
    lastReceivedRssi = pkt->rx_ctrl.rssi;
  }
}

void onESPNowDataReceived(const uint8_t *mac_addr, const uint8_t *incomingData, int len) {
  // Validate data size
  if (len != sizeof(ESPNowData)) {
    Serial.printf("⚠️ Invalid ESP-NOW data size: got %d, expected %d\n", len, sizeof(ESPNowData));
    return;
  }

  // Track mesh activity - we received data, so mesh is alive
  g_lastMeshActivity = millis();

  ESPNowData* data = (ESPNowData*)incomingData;

  // Use the RSSI captured from promiscuous mode (or sentinel if promiscuous is off)
  int8_t rssi = g_promiscuousModeEnabled ? lastReceivedRssi : RSSI_UNKNOWN;

  // Ignore our own broadcasts
  if (strcmp(data->deviceMAC, deviceMAC.c_str()) == 0) {
    return;
  }

  Serial.printf("📥 ESP-NOW RX from %s (RSSI: %d dBm): ", data->deviceMAC, rssi);
  
  // Check message type
  if (data->messageType == MSG_TYPE_COEFFICIENTS_CH1 || data->messageType == MSG_TYPE_COEFFICIENTS_CH2) {
    // This is a coefficient update
    int channel = (data->messageType == MSG_TYPE_COEFFICIENTS_CH1) ? 1 : 2;
    Serial.printf("CH%d COEFFICIENTS UPDATE\n", channel);

    // Extract coefficients from reused fields
    RegressionCoeffs newCoeffs;
    newCoeffs.intercept = data->ch1AirPressure;
    newCoeffs.airPressureCoeff = data->ch2AirPressure;
    newCoeffs.ambientPressureCoeff = data->atmosphericPressure;
    newCoeffs.airTempCoeff = data->temperature;

    if (channel == 1) {
      float oldIntercept = ch1Coeffs.intercept;
      ch1Coeffs = newCoeffs;
      preferences.putFloat("ch1_intercept", ch1Coeffs.intercept);
      preferences.putFloat("ch1_air_coeff", ch1Coeffs.airPressureCoeff);
      preferences.putFloat("ch1_amb_coeff", ch1Coeffs.ambientPressureCoeff);
      preferences.putFloat("ch1_temp_coeff", ch1Coeffs.airTempCoeff);
      Serial.printf("✅ CH1 Coefficients updated: intercept %.4f → %.4f\n",
                   oldIntercept, ch1Coeffs.intercept);
    } else {
      float oldIntercept = ch2Coeffs.intercept;
      ch2Coeffs = newCoeffs;
      preferences.putFloat("ch2_intercept", ch2Coeffs.intercept);
      preferences.putFloat("ch2_air_coeff", ch2Coeffs.airPressureCoeff);
      preferences.putFloat("ch2_amb_coeff", ch2Coeffs.ambientPressureCoeff);
      preferences.putFloat("ch2_temp_coeff", ch2Coeffs.airTempCoeff);
      Serial.printf("✅ CH2 Coefficients updated: intercept %.4f → %.4f\n",
                   oldIntercept, ch2Coeffs.intercept);
    }
  } else {
    // This is sensor data
    Serial.printf("CH1=%.1f lbs | CH2=%.1f lbs | Total=%.1f lbs\n",
                 data->ch1Weight, data->ch2Weight, data->totalWeight);
    updateDeviceData(data, rssi);
  }
}

void onESPNowDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {
  if (status != ESP_NOW_SEND_SUCCESS) {
    Serial.printf("📤 ESP-NOW TX FAILED to %02X:%02X:%02X:%02X:%02X:%02X\n",
                 mac_addr[0], mac_addr[1], mac_addr[2],
                 mac_addr[3], mac_addr[4], mac_addr[5]);
  }
}

void broadcastMyData() {
  SensorData sensorData = readSensors();

  ESPNowData data;
  memset(&data, 0, sizeof(data));

  strncpy(data.deviceMAC, deviceMAC.c_str(), sizeof(data.deviceMAC) - 1);
  strncpy(data.deviceName, bleDeviceName.c_str(), sizeof(data.deviceName) - 1);
  data.ch1AirPressure = sensorData.ch1AirPressure;
  data.ch2AirPressure = sensorData.ch2AirPressure;
  data.atmosphericPressure = sensorData.atmosphericPressure;
  data.temperature = sensorData.temperature;
  data.elevation = sensorData.elevation;
  data.ch1Weight = sensorData.ch1Weight;
  data.ch2Weight = sensorData.ch2Weight;
  data.totalWeight = sensorData.totalWeight;
  data.timestamp = millis();
  data.batteryLevel = 85;  // TODO: Real battery reading
  data.isCharging = false;
  data.messageType = MSG_TYPE_SENSOR_DATA;

  // Broadcast to all devices
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};

  // Add broadcast peer if not exists
  if (!esp_now_is_peer_exist(broadcastAddress)) {
    esp_now_peer_info_t peerInfo = {};
    memcpy(peerInfo.peer_addr, broadcastAddress, 6);
    peerInfo.channel = 0;  // Use current channel
    peerInfo.encrypt = false;
    esp_now_add_peer(&peerInfo);
  }

  esp_err_t result = esp_now_send(broadcastAddress, (uint8_t*)&data, sizeof(data));

  if (result == ESP_OK) {
    Serial.printf("📡 Broadcast: CH1=%.1f lbs (%.2f psi) | CH2=%.1f lbs (%.2f psi) | Total=%.1f lbs | %s\n",
                 data.ch1Weight, data.ch1AirPressure,
                 data.ch2Weight, data.ch2AirPressure,
                 data.totalWeight,
                 isHub ? "HUB" : "DEVICE");
  } else {
    Serial.printf("❌ Broadcast failed: %d\n", result);
  }
}

void sendCoeffsToDevice(const char* targetMAC, RegressionCoeffs* targetCoeffs, int channel) {
  Serial.printf("📤 Sending CH%d coefficients to %s\n", channel, targetMAC);

  // Parse target MAC
  uint8_t macBytes[6];
  if (sscanf(targetMAC, "%hhx:%hhx:%hhx:%hhx:%hhx:%hhx",
         &macBytes[0], &macBytes[1], &macBytes[2],
         &macBytes[3], &macBytes[4], &macBytes[5]) != 6) {
    Serial.println("❌ Invalid target MAC format");
    return;
  }

  // Create coefficient update packet
  // We reuse some fields to send coefficients:
  // ch1AirPressure = intercept, ch2AirPressure = airPressureCoeff
  // atmosphericPressure = ambientPressureCoeff, temperature = airTempCoeff
  ESPNowData coeffsData;
  memset(&coeffsData, 0, sizeof(coeffsData));

  strncpy(coeffsData.deviceMAC, deviceMAC.c_str(), sizeof(coeffsData.deviceMAC) - 1);
  strncpy(coeffsData.deviceName, "COEFFS", sizeof(coeffsData.deviceName) - 1);
  coeffsData.ch1AirPressure = targetCoeffs->intercept;
  coeffsData.ch2AirPressure = targetCoeffs->airPressureCoeff;
  coeffsData.atmosphericPressure = targetCoeffs->ambientPressureCoeff;
  coeffsData.temperature = targetCoeffs->airTempCoeff;
  coeffsData.timestamp = millis();
  coeffsData.messageType = (channel == 1) ? MSG_TYPE_COEFFICIENTS_CH1 : MSG_TYPE_COEFFICIENTS_CH2;

  // Add peer if not exists
  if (!esp_now_is_peer_exist(macBytes)) {
    esp_now_peer_info_t peerInfo = {};
    memcpy(peerInfo.peer_addr, macBytes, 6);
    peerInfo.channel = 0;
    peerInfo.encrypt = false;
    esp_now_add_peer(&peerInfo);
  }

  // Send
  esp_err_t result = esp_now_send(macBytes, (uint8_t*)&coeffsData, sizeof(coeffsData));

  Serial.printf("📤 CH%d Coefficients to %s: %s (intercept=%.4f, air=%.4f)\n",
               channel, targetMAC,
               result == ESP_OK ? "SUCCESS" : "FAILED",
               targetCoeffs->intercept,
               targetCoeffs->airPressureCoeff);
}

void updateDeviceData(ESPNowData* data, int8_t rssi) {
  DeviceData* device = findDevice(data->deviceMAC);

  if (device == nullptr && deviceCount < MAX_DEVICES) {
    // Add new device
    device = &knownDevices[deviceCount++];
    strncpy(device->macAddress, data->deviceMAC, sizeof(device->macAddress) - 1);
    Serial.printf("✨ New device discovered: %s\n", data->deviceMAC);
  }

  if (device != nullptr) {
    strncpy(device->deviceName, data->deviceName, sizeof(device->deviceName) - 1);
    memcpy(&device->lastData, data, sizeof(ESPNowData));
    device->lastSeen = millis();
    device->isActive = true;
    device->espNowRssi = rssi;
  }
}

DeviceData* findDevice(const char* mac) {
  for (int i = 0; i < deviceCount; i++) {
    if (strcmp(knownDevices[i].macAddress, mac) == 0) {
      return &knownDevices[i];
    }
  }
  return nullptr;
}

// ============================================================
// BLE FUNCTIONS
// ============================================================

void initBLE() {
  Serial.println("\n🔵 Initializing BLE...");

  // Free Classic BT memory - we only need BLE (saves ~30KB and avoids coex issues)
  // Static guard ensures this only runs once (safe if initBLE is called multiple times)
  static bool btMemReleased = false;
  if (!btMemReleased) {
    esp_bt_controller_mem_release(ESP_BT_MODE_CLASSIC_BT);
    btMemReleased = true;
  }

  // Set local MTU BEFORE BLEDevice::init() so the stack knows the limit
  // This allows Android to negotiate >23 bytes (e.g. 247)
  // Critical for fast OTA transfers
  esp_err_t mtuRes = esp_ble_gatt_set_local_mtu(247);
  Serial.printf("📏 esp_ble_gatt_set_local_mtu(247): %s\n", esp_err_to_name(mtuRes));

  BLEDevice::init(bleDeviceName.c_str());

  pServer = BLEDevice::createServer();
  pServer->setCallbacks(new MyServerCallbacks());
  
  BLEService *pService = pServer->createService(SERVICE_UUID);
  
  // Sensor data characteristic (notify phone of sensor readings)
  pSensorCharacteristic = pService->createCharacteristic(
      SENSOR_CHAR_UUID,
      BLECharacteristic::PROPERTY_READ | BLECharacteristic::PROPERTY_NOTIFY
  );
  pSensorCharacteristic->addDescriptor(new BLE2902());
  
  // Coefficients characteristic (receive calibration from phone)
  pCoeffsCharacteristic = pService->createCharacteristic(
      COEFFS_CHAR_UUID,
      BLECharacteristic::PROPERTY_WRITE
  );
  pCoeffsCharacteristic->setCallbacks(new CoeffsCallbacks());

  // OTA characteristic (receive firmware updates from phone)
  // WRITE for compatibility + WRITE_NR for speed (phone can choose)
  pOtaCharacteristic = pService->createCharacteristic(
      OTA_CHAR_UUID,
      BLECharacteristic::PROPERTY_WRITE | BLECharacteristic::PROPERTY_WRITE_NR
  );
  pOtaCharacteristic->setCallbacks(new OtaCallbacks());
  Serial.println("📦 OTA characteristic initialized (WRITE + WRITE_NR)");

  pService->start();

  // Get and store global advertising instance - use this everywhere
  g_adv = BLEDevice::getAdvertising();
  g_adv->addServiceUUID(SERVICE_UUID);

  // CRITICAL: Build custom advertisement data with device name in the advertisement packet
  // (not just scan response) so all BLE scanners can see it without requesting scan response
  BLEAdvertisementData advertisementData;
  advertisementData.setName(bleDeviceName.c_str());
  advertisementData.setCompleteServices(BLEUUID(SERVICE_UUID));
  advertisementData.setFlags(0x06); // BR_EDR_NOT_SUPPORTED | General Discoverable Mode
  g_adv->setAdvertisementData(advertisementData);

  // Scan response can contain additional data if needed
  BLEAdvertisementData scanResponseData;
  scanResponseData.setName(bleDeviceName.c_str()); // Include name here too for compatibility
  g_adv->setScanResponseData(scanResponseData);

  g_adv->setMinPreferred(0x06);
  g_adv->setMinPreferred(0x12);

  g_adv->start();  // Use global instance, not BLEDevice::startAdvertising()

  bleEnabled = true;
  Serial.println("✅ BLE advertising started: " + bleDeviceName);
}

// Binary BLE notification packet structure (45 bytes - fits any MTU)
// This replaces the ~300+ byte JSON that was getting truncated
#pragma pack(push, 1)
struct BLESensorPacket {
  uint8_t  packetType;        // 0 = hub, 1 = device   (offset 0)
  uint8_t  mac[6];            // MAC address bytes     (offset 1-6)
  float    ch1AirPressure;    // 4 bytes               (offset 7-10)
  float    ch2AirPressure;    // 4 bytes               (offset 11-14)
  float    atmosphericPressure; // 4 bytes             (offset 15-18)
  float    temperature;       // 4 bytes               (offset 19-22)
  float    ch1Weight;         // 4 bytes               (offset 23-26)
  float    ch2Weight;         // 4 bytes               (offset 27-30)
  float    totalWeight;       // 4 bytes               (offset 31-34)
  uint8_t  batteryLevel;      // 1 byte                (offset 35)
  uint8_t  deviceCount;       // 1 byte (hub only)     (offset 36)
  float    fleetTotalWeight;  // 4 bytes (hub only)    (offset 37-40)
  uint8_t  fwMajor;           // 1 byte                (offset 41)
  uint8_t  fwMinor;           // 1 byte                (offset 42)
  uint8_t  fwPatch;           // 1 byte                (offset 43)
  int8_t   espnowRssi;        // 1 byte (devices only) (offset 44)
};  // Total: 45 bytes (packed)
#pragma pack(pop)

// Helper to parse MAC string "AA:BB:CC:DD:EE:FF" into 6 bytes
void parseMacString(const char* macStr, uint8_t* macBytes) {
  sscanf(macStr, "%hhx:%hhx:%hhx:%hhx:%hhx:%hhx",
         &macBytes[0], &macBytes[1], &macBytes[2],
         &macBytes[3], &macBytes[4], &macBytes[5]);
}

// Helper to parse firmware version string "X.Y.Z" into bytes
void parseFirmwareVersion(const char* version, uint8_t* major, uint8_t* minor, uint8_t* patch) {
  int maj = 0, min = 0, pat = 0;
  sscanf(version, "%d.%d.%d", &maj, &min, &pat);
  *major = (uint8_t)maj;
  *minor = (uint8_t)min;
  *patch = (uint8_t)pat;
}

void sendAllDataViaBLE() {
  if (!deviceConnected || !bleEnabled) return;

  // Read my own sensor data
  SensorData myData = readSensors();

  // Count active devices and sum weights
  int activeDevices = 0;
  float fleetTotalWeight = myData.totalWeight;

  for (int i = 0; i < deviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 60000) {
      activeDevices++;
      fleetTotalWeight += knownDevices[i].lastData.totalWeight;
    }
  }

  // Build binary packet for hub
  BLESensorPacket hubPacket;
  memset(&hubPacket, 0, sizeof(hubPacket));

  hubPacket.packetType = 0;  // hub
  parseMacString(deviceMAC.c_str(), hubPacket.mac);
  hubPacket.ch1AirPressure = myData.ch1AirPressure;
  hubPacket.ch2AirPressure = myData.ch2AirPressure;
  hubPacket.atmosphericPressure = myData.atmosphericPressure;
  hubPacket.temperature = myData.temperature;
  hubPacket.ch1Weight = myData.ch1Weight;
  hubPacket.ch2Weight = myData.ch2Weight;
  hubPacket.totalWeight = myData.totalWeight;
  hubPacket.batteryLevel = 85;  // TODO: Real battery reading
  hubPacket.deviceCount = activeDevices + 1;  // Include myself
  hubPacket.fleetTotalWeight = fleetTotalWeight;
  parseFirmwareVersion(FIRMWARE_VERSION, &hubPacket.fwMajor, &hubPacket.fwMinor, &hubPacket.fwPatch);
  hubPacket.espnowRssi = 0;  // Not applicable for hub

  pSensorCharacteristic->setValue((uint8_t*)&hubPacket, sizeof(hubPacket));
  pSensorCharacteristic->notify();

  Serial.printf("📲 BLE TX [HUB]: CH1=%.1f | CH2=%.1f | Total=%.1f | Fleet=%.1f lbs | Devices: %d (48 bytes)\n",
               myData.ch1Weight, myData.ch2Weight, myData.totalWeight,
               fleetTotalWeight, activeDevices + 1);

  delay(100);  // Small delay between notifications

  // Send each slave's data
  for (int i = 0; i < deviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 60000) {
      BLESensorPacket slavePacket;
      memset(&slavePacket, 0, sizeof(slavePacket));

      slavePacket.packetType = 1;  // device
      parseMacString(knownDevices[i].macAddress, slavePacket.mac);
      slavePacket.ch1AirPressure = knownDevices[i].lastData.ch1AirPressure;
      slavePacket.ch2AirPressure = knownDevices[i].lastData.ch2AirPressure;
      slavePacket.atmosphericPressure = knownDevices[i].lastData.atmosphericPressure;
      slavePacket.temperature = knownDevices[i].lastData.temperature;
      slavePacket.ch1Weight = knownDevices[i].lastData.ch1Weight;
      slavePacket.ch2Weight = knownDevices[i].lastData.ch2Weight;
      slavePacket.totalWeight = knownDevices[i].lastData.totalWeight;
      slavePacket.batteryLevel = knownDevices[i].lastData.batteryLevel;
      slavePacket.deviceCount = 0;  // Not applicable for devices
      slavePacket.fleetTotalWeight = 0;  // Not applicable for devices
      parseFirmwareVersion(FIRMWARE_VERSION, &slavePacket.fwMajor, &slavePacket.fwMinor, &slavePacket.fwPatch);
      slavePacket.espnowRssi = knownDevices[i].espNowRssi;

      pSensorCharacteristic->setValue((uint8_t*)&slavePacket, sizeof(slavePacket));
      pSensorCharacteristic->notify();

      Serial.printf("📲 BLE TX [SLAVE]: %s | CH1=%.1f | CH2=%.1f | Total=%.1f lbs | RSSI=%d (48 bytes)\n",
                   knownDevices[i].macAddress,
                   knownDevices[i].lastData.ch1Weight,
                   knownDevices[i].lastData.ch2Weight,
                   knownDevices[i].lastData.totalWeight,
                   knownDevices[i].espNowRssi);

      delay(100);
    }
  }
}

// ============================================================
// SENSOR FUNCTIONS
// ============================================================

// Pressure simulation - creates realistic loading/unloading patterns
// Channel 1 and Channel 2 follow different patterns with phase offsets
float simulatePressure(int channel) {
  // Total cycle is about 12 minutes, with different segments
  // Channel 2 is offset by 90 seconds (phase shift)
  unsigned long timeMs = millis();
  if (channel == 2) {
    timeMs += 90000;  // 90 second phase offset for channel 2
  }

  // Convert to seconds for easier calculation
  float timeSec = (timeMs / 1000.0);

  // Full cycle period (about 12 minutes = 720 seconds)
  float cycleTime = fmod(timeSec, 720.0);

  float pressure;

  // Segment 1: 0-300s (5 min) - Slow descent from 110 to 40 PSI (loading trailer)
  if (cycleTime < 300.0) {
    float progress = cycleTime / 300.0;
    // Smooth sinusoidal transition
    float easedProgress = (1.0 - cos(progress * PI)) / 2.0;
    pressure = 110.0 - (70.0 * easedProgress);
  }
  // Segment 2: 300-420s (2 min) - Stabilize around 40 PSI (loaded, stationary)
  else if (cycleTime < 420.0) {
    pressure = 40.0;
  }
  // Segment 3: 420-480s (1 min) - Quick rise to 80 PSI (partial unload)
  else if (cycleTime < 480.0) {
    float segmentTime = cycleTime - 420.0;
    float progress = segmentTime / 60.0;
    float easedProgress = (1.0 - cos(progress * PI)) / 2.0;
    pressure = 40.0 + (40.0 * easedProgress);
  }
  // Segment 4: 480-500s (20 sec) - Hold at 80 PSI
  else if (cycleTime < 500.0) {
    pressure = 80.0;
  }
  // Segment 5: 500-620s (2 min) - Slow descent to 20 PSI (heavy load)
  else if (cycleTime < 620.0) {
    float segmentTime = cycleTime - 500.0;
    float progress = segmentTime / 120.0;
    float easedProgress = (1.0 - cos(progress * PI)) / 2.0;
    pressure = 80.0 - (60.0 * easedProgress);
  }
  // Segment 6: 620-720s (100 sec) - Rise back to 75 PSI (partial unload, end of cycle)
  else {
    float segmentTime = cycleTime - 620.0;
    float progress = segmentTime / 100.0;
    float easedProgress = (1.0 - cos(progress * PI)) / 2.0;
    pressure = 20.0 + (55.0 * easedProgress);
  }

  // Add small noise for realism (+/- 0.5 PSI)
  pressure += (random(-50, 51) / 100.0);

  // Clamp to valid range
  if (pressure < 15.0) pressure = 15.0;
  if (pressure > 110.0) pressure = 110.0;

  return pressure;
}

void initBME280() {
  Serial.println("🌡️ Initializing BME280...");

  // Use default Wire instance (initialized in setup with our custom pins)
  bmeInitialized = bme.begin(0x76, &Wire);

  if (!bmeInitialized) {
    bmeInitialized = bme.begin(0x77, &Wire);
  }
  
  if (bmeInitialized) {
    Serial.println("✅ BME280 initialized!");
    
    bme.setSampling(Adafruit_BME280::MODE_NORMAL,
                    Adafruit_BME280::SAMPLING_X2,
                    Adafruit_BME280::SAMPLING_X16,
                    Adafruit_BME280::SAMPLING_X1,
                    Adafruit_BME280::FILTER_X16,
                    Adafruit_BME280::STANDBY_MS_500);
    
    float temp = bme.readTemperature() * 9.0/5.0 + 32.0;
    float pressure = bme.readPressure() / 6894.76;
    
    Serial.printf("📊 Initial: %.1f°F, %.2f PSI\n", temp, pressure);
  } else {
    Serial.println("❌ BME280 not found - using dummy data");
  }
}

SensorData readSensors() {
  SensorData data;

  if (bmeInitialized) {
    data.temperature = bme.readTemperature() * 9.0/5.0 + 32.0;
    data.atmosphericPressure = bme.readPressure() / 6894.76;
    data.elevation = bme.readAltitude(1013.25) * 3.28084;
  } else {
    // Dummy environmental data for testing
    data.atmosphericPressure = 14.7 + (random(-10, 10)/100.0);
    data.temperature = 72.0 + (random(-30, 30)/10.0);
    data.elevation = 1000 + random(-50, 50);
  }

  // TODO: Replace with actual ADC reads from pressure sensors
  // Channel 1 air pressure (Axle Group 1)
  // data.ch1AirPressure = analogRead(PRESSURE_SENSOR_CH1_PIN) * conversion_factor;
  // Channel 2 air pressure (Axle Group 2)
  // data.ch2AirPressure = analogRead(PRESSURE_SENSOR_CH2_PIN) * conversion_factor;

  // Simulate realistic pressure patterns for testing
  // Channel 1 and Channel 2 have different phase offsets (90 sec apart)
  data.ch1AirPressure = simulatePressure(1);
  data.ch2AirPressure = simulatePressure(2);

  // Calculate weight for Channel 1 using ch1 coefficients
  data.ch1Weight = ch1Coeffs.intercept +
                   (data.ch1AirPressure * ch1Coeffs.airPressureCoeff) +
                   (data.atmosphericPressure * ch1Coeffs.ambientPressureCoeff) +
                   (data.temperature * ch1Coeffs.airTempCoeff);
  if (data.ch1Weight < 0) data.ch1Weight = 0;

  // Calculate weight for Channel 2 using ch2 coefficients
  data.ch2Weight = ch2Coeffs.intercept +
                   (data.ch2AirPressure * ch2Coeffs.airPressureCoeff) +
                   (data.atmosphericPressure * ch2Coeffs.ambientPressureCoeff) +
                   (data.temperature * ch2Coeffs.airTempCoeff);
  if (data.ch2Weight < 0) data.ch2Weight = 0;

  // Total weight is sum of both axle groups
  data.totalWeight = data.ch1Weight + data.ch2Weight;

  data.timestamp = getCurrentTimestamp();

  return data;
}

String getCurrentTimestamp() {
  return String(millis());
}

// ============================================================
// WiFi & WEB SERVER (Optional)
// ============================================================

void tryConnectWiFi() {
  if (strlen(FALLBACK_SSID) == 0) {
    Serial.println("📡 No WiFi configured - running in standalone mode");
    isConnectedToWiFi = false;
    return;
  }
  
  Serial.printf("📡 Trying WiFi: %s\n", FALLBACK_SSID);
  
  // WiFi.mode already set in setup(), just connect
  WiFi.begin(FALLBACK_SSID, FALLBACK_PASSWORD);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    isConnectedToWiFi = true;
    Serial.printf("\n✅ WiFi connected! IP: %s, Channel: %d\n", 
                 WiFi.localIP().toString().c_str(), WiFi.channel());
  } else {
    isConnectedToWiFi = false;
    WiFi.disconnect();
    Serial.println("\n⚠️ WiFi not available - ESP-NOW will use fixed channel");
  }
}

void setupWebServer() {
  if (!server) return;  // Guard against null

  server->on("/", HTTP_GET, []() {
    String html = "<html><head><title>AirScale</title></head><body>";
    html += "<h1>AirScale Device</h1>";
    html += "<p><b>MAC:</b> " + deviceMAC + "</p>";
    html += "<p><b>Role:</b> " + String(isHub ? "HUB (BLE Connected)" : "DEVICE") + "</p>";
    html += "<p><b>BLE:</b> " + String(deviceConnected ? "Connected" : "Waiting") + "</p>";
    html += "<p><b>BME280:</b> " + String(bmeInitialized ? "OK" : "Not Found") + "</p>";
    html += "<p><b>Known Devices:</b> " + String(deviceCount) + "</p>";
    html += "<p><b>Channel 1 Coefficients (Axle Group 1):</b><br>";
    html += "Intercept: " + String(ch1Coeffs.intercept, 4) + "<br>";
    html += "Air Pressure: " + String(ch1Coeffs.airPressureCoeff, 4) + "<br>";
    html += "Ambient: " + String(ch1Coeffs.ambientPressureCoeff, 4) + "<br>";
    html += "Temp: " + String(ch1Coeffs.airTempCoeff, 4) + "</p>";
    html += "<p><b>Channel 2 Coefficients (Axle Group 2):</b><br>";
    html += "Intercept: " + String(ch2Coeffs.intercept, 4) + "<br>";
    html += "Air Pressure: " + String(ch2Coeffs.airPressureCoeff, 4) + "<br>";
    html += "Ambient: " + String(ch2Coeffs.ambientPressureCoeff, 4) + "<br>";
    html += "Temp: " + String(ch2Coeffs.airTempCoeff, 4) + "</p>";
    html += "</body></html>";
    server->send(200, "text/html", html);
  });

  server->on("/api/status", HTTP_GET, []() {
    DynamicJsonDocument doc(768);
    doc["mac_address"] = deviceMAC;
    doc["is_hub"] = isHub;
    doc["ble_connected"] = deviceConnected;
    doc["wifi_connected"] = isConnectedToWiFi;
    doc["known_devices"] = deviceCount;
    doc["bme280"] = bmeInitialized;

    JsonObject ch1CoeffsObj = doc.createNestedObject("ch1_coefficients");
    ch1CoeffsObj["intercept"] = ch1Coeffs.intercept;
    ch1CoeffsObj["air_pressure"] = ch1Coeffs.airPressureCoeff;
    ch1CoeffsObj["ambient_pressure"] = ch1Coeffs.ambientPressureCoeff;
    ch1CoeffsObj["temperature"] = ch1Coeffs.airTempCoeff;

    JsonObject ch2CoeffsObj = doc.createNestedObject("ch2_coefficients");
    ch2CoeffsObj["intercept"] = ch2Coeffs.intercept;
    ch2CoeffsObj["air_pressure"] = ch2Coeffs.airPressureCoeff;
    ch2CoeffsObj["ambient_pressure"] = ch2Coeffs.ambientPressureCoeff;
    ch2CoeffsObj["temperature"] = ch2Coeffs.airTempCoeff;

    String response;
    serializeJson(doc, response);
    server->send(200, "application/json", response);
  });

  server->begin();
  Serial.println("🌐 Web server started on port 80");
}

// ============================================================
// LED FUNCTIONS
// ============================================================

void setLEDStatus(LEDStatus status) {
  if (!pixel) return;  // Guard against early calls before init

  currentLEDStatus = status;

  switch (status) {
    case LED_OFF:
      pixel->setPixelColor(0, pixel->Color(0, 0, 0));
      break;
    case LED_BOOTING:
      pixel->setPixelColor(0, pixel->Color(0, 0, 50));
      break;
    case LED_STANDALONE:
      pixel->setPixelColor(0, pixel->Color(50, 20, 0));  // Orange
      break;
    case LED_HUB_MODE:
      pixel->setPixelColor(0, pixel->Color(25, 0, 50));  // Purple
      break;
    case LED_TRANSMITTING:
      pixel->setPixelColor(0, pixel->Color(50, 50, 50)); // White
      break;
    case LED_CONNECTED:
      pixel->setPixelColor(0, pixel->Color(0, 50, 0));   // Green
      break;
  }
  pixel->show();
}

void updateLED() {
  if (!pixel) return;  // Guard against early calls before init

  static unsigned long lastUpdate = 0;
  static uint8_t brightness = 0;
  static bool increasing = true;

  if (millis() - lastUpdate > 30) {
    // Pulse effect for hub and standalone modes
    if (currentLEDStatus == LED_HUB_MODE || currentLEDStatus == LED_STANDALONE) {
      if (increasing) {
        brightness += 3;
        if (brightness >= 50) increasing = false;
      } else {
        brightness -= 3;
        if (brightness <= 10) increasing = true;
      }

      if (currentLEDStatus == LED_HUB_MODE) {
        pixel->setPixelColor(0, pixel->Color(brightness/2, 0, brightness));
      } else {
        pixel->setPixelColor(0, pixel->Color(brightness, brightness/3, 0));
      }
      pixel->show();
    }

    lastUpdate = millis();
  }
}
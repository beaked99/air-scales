#include <WiFi.h>
#include <WebServer.h>
#include <WebSocketsServer.h>
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
#include <Wire.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BME280.h>
#include <Adafruit_NeoPixel.h>

// Hardware Configuration - CUSTOM AIR SCALES BOARD
#define WS2812B_PIN 10
#define I2C_SDA 48
#define I2C_SCL 47

// BLE configuration
#define SERVICE_UUID        "12345678-1234-1234-1234-123456789abc"
#define SENSOR_CHAR_UUID    "87654321-4321-4321-4321-cba987654321"
#define COEFFS_CHAR_UUID    "11111111-2222-3333-4444-555555555555"
#define DEVICE_NAME_PREFIX  "AirScale-"

// Network Configuration
const char* SERVER_URL = "https://beaker.ca";
const char* FALLBACK_SSID = "YourHomeWiFi";    
const char* FALLBACK_PASSWORD = "YourPassword"; 

// Global Objects
WebServer server(80);
WebSocketsServer webSocket(81);
Preferences preferences;
HTTPClient http;
Adafruit_BME280 bme; // BME280 sensor
Adafruit_NeoPixel pixel(1, WS2812B_PIN, NEO_GRB + NEO_KHZ800);

// BLE Global Objects
BLEServer* pServer = nullptr;
BLECharacteristic* pSensorCharacteristic = nullptr;
BLECharacteristic* pCoeffsCharacteristic = nullptr;
bool deviceConnected = false;
bool bleEnabled = false;
String bleDeviceName;

// Device State
String deviceMAC;
String apSSID;
bool isConnectedToWiFi = false;
bool isAPMode = false;
bool isHub = false;
bool bmeInitialized = false;

// ESP-NOW Data Structure
typedef struct {
  char deviceMAC[18];
  char deviceName[32];
  float mainAirPressure;
  float atmosphericPressure;
  float temperature;
  float elevation;
  float gpsLat;
  float gpsLng;
  float weight;
  uint32_t timestamp;
  uint8_t batteryLevel;
  bool isCharging;
} ESPNowData;

// Simple device tracking
#define MAX_DEVICES 10
struct DeviceData {
  char macAddress[18];
  char deviceName[32];
  ESPNowData lastData;
  unsigned long lastSeen;
  bool isActive;
} knownDevices[MAX_DEVICES];
int deviceCount = 0;

// Sensor Data Structure
struct SensorData {
  float mainAirPressure;
  float atmosphericPressure;
  float temperature;
  float elevation;
  float gpsLat;
  float gpsLng;
  float weight;
  String timestamp;
};

// Regression Coefficients Structure
struct RegressionCoeffs {
  float intercept = 0.0;
  float airPressureCoeff = 0.0;
  float ambientPressureCoeff = 0.0;
  float airTempCoeff = 0.0;
};

RegressionCoeffs coeffs;

// LED Status Colors
enum LEDStatus {
  LED_OFF,
  LED_BOOTING,      // Blue pulse
  LED_NO_WIFI,      // Red slow blink
  LED_WIFI_OK,      // Green solid
  LED_HUB_MODE,     // Purple pulse
  LED_TRANSMITTING  // Quick white flash
};

// Function declarations
void connectToWiFi();
void startAPMode();
void setupWebServer();
void initESPNow();
void onESPNowDataReceived(const uint8_t *mac, const uint8_t *incomingData, int len);
void onESPNowDataSent(const uint8_t *mac_addr, esp_now_send_status_t status);
void broadcastMyData();
void sendAllDataToServer();
void sendAllDataViaBLE();
SensorData readSensors();
void registerDevice();
void downloadAndDistributeCoeffs();
void sendCoeffsToDevice(const char* targetMAC);
String getCurrentTimestamp();
void initBLE();
void updateDeviceData(ESPNowData* data);
DeviceData* findDevice(const char* mac);
void initBME280();
void setLEDStatus(LEDStatus status);
void updateLED();

// BLE Callback Classes
class MyServerCallbacks: public BLEServerCallbacks {
    void onConnect(BLEServer* pServer) {
        deviceConnected = true;
        Serial.println("BLE Client Connected!");
        if (isHub) {
            downloadAndDistributeCoeffs();
        }
    }

    void onDisconnect(BLEServer* pServer) {
        deviceConnected = false;
        Serial.println("BLE Client Disconnected!");
        if (bleEnabled) {
            pServer->startAdvertising();
        }
    }
};

class CoeffsCallbacks: public BLECharacteristicCallbacks {
    void onWrite(BLECharacteristic* pCharacteristic) {
        std::string rxValue = pCharacteristic->getValue();
        
        if (rxValue.length() > 0) {
            Serial.println("Received coefficients via BLE:");
            
            DynamicJsonDocument doc(512);
            DeserializationError error = deserializeJson(doc, rxValue.c_str());
            
            if (!error) {
                coeffs.intercept = doc["intercept"] | 0.0;
                coeffs.airPressureCoeff = doc["air_pressure_coeff"] | 0.0;
                coeffs.ambientPressureCoeff = doc["ambient_pressure_coeff"] | 0.0;
                coeffs.airTempCoeff = doc["air_temp_coeff"] | 0.0;
                
                preferences.putFloat("intercept", coeffs.intercept);
                preferences.putFloat("air_coeff", coeffs.airPressureCoeff);
                preferences.putFloat("ambient_coeff", coeffs.ambientPressureCoeff);
                preferences.putFloat("temp_coeff", coeffs.airTempCoeff);
                
                Serial.println("Updated coefficients via BLE");
            }
        }
    }
};

void setup() {
  Serial.begin(115200);
  delay(1000);
  
  Serial.println("🚀 AirScales ESP32 Custom Board Starting...");
  
  // Initialize WS2812B LED
  pixel.begin();
  pixel.setBrightness(50);
  setLEDStatus(LED_BOOTING);
  
  // Initialize I2C for BME280
  Wire.begin(I2C_SDA, I2C_SCL);
  initBME280();
  
  // Get MAC address for device ID
  deviceMAC = WiFi.macAddress();
  apSSID = "AirScales-" + deviceMAC;
  bleDeviceName = DEVICE_NAME_PREFIX + deviceMAC;
  
  Serial.println("Device MAC: " + deviceMAC);
  
  // Initialize SPIFFS
  if (!SPIFFS.begin(true)) {
    Serial.println("SPIFFS Mount Failed");
  }
  
  // Initialize preferences and load coefficients
  preferences.begin("airscales", false);
  coeffs.intercept = preferences.getFloat("intercept", 0.0);
  coeffs.airPressureCoeff = preferences.getFloat("air_coeff", 0.0);
  coeffs.ambientPressureCoeff = preferences.getFloat("ambient_coeff", 0.0);
  coeffs.airTempCoeff = preferences.getFloat("temp_coeff", 0.0);
  
  // Try to connect to WiFi
  connectToWiFi();
  
  // If no WiFi, start AP mode
  if (!isConnectedToWiFi) {
    startAPMode();
    setLEDStatus(LED_NO_WIFI);
  } else {
    setLEDStatus(LED_WIFI_OK);
  }
  
  // Determine if I'm a hub
  isHub = isConnectedToWiFi;
  Serial.println(isHub ? "🌟 I AM THE HUB" : "📡 I am a regular device");
  
  if (isHub) {
    setLEDStatus(LED_HUB_MODE);
  }
  
  // Setup web server
  setupWebServer();
  
  // Initialize BLE
  initBLE();
  
  // Initialize ESP-NOW
  initESPNow();
  
  // If I'm the hub, register and get coefficients
  if (isHub) {
    registerDevice();
    downloadAndDistributeCoeffs();
  }
  
  Serial.println("✅ AirScales Ready!");
}

void loop() {
  server.handleClient();
  webSocket.loop();
  
  // Update LED status animation
  updateLED();
  
  // If someone connects via BLE, I become a hub
  if (!isHub && deviceConnected) {
    isHub = true;
    Serial.println("🌟 BLE connected - I AM NOW THE HUB");
    setLEDStatus(LED_HUB_MODE);
    downloadAndDistributeCoeffs();
  }
  
  // Status debug output
  static unsigned long lastDebugPrint = 0;
  if (millis() - lastDebugPrint > 60000) {
    uint8_t currentChannel = 0;
    if (WiFi.status() == WL_CONNECTED) {
      currentChannel = WiFi.channel();
    } else {
      wifi_second_chan_t dummy;
      esp_wifi_get_channel(&currentChannel, &dummy);
    }
    
    Serial.printf("🔍 STATUS: %s | Ch:%d | RAM:%d | Devices:%d | WiFi:%s | BME280:%s\n", 
                 isHub ? "HUB" : "DEVICE", 
                 currentChannel, 
                 ESP.getFreeHeap(), 
                 deviceCount,
                 isConnectedToWiFi ? "OK" : "NO",
                 bmeInitialized ? "OK" : "FAIL");
    
    for (int i = 0; i < deviceCount; i++) {
      if (knownDevices[i].isActive) {
        Serial.printf("   Device %d: %s | Last seen: %lu ms ago\n", 
                     i, knownDevices[i].macAddress, millis() - knownDevices[i].lastSeen);
      }
    }
    
    lastDebugPrint = millis();
  }
  
  // Broadcast sensor data every 10 seconds (non-hub devices only)
  static unsigned long lastBroadcast = 0;
  if (millis() - lastBroadcast > 10000) {
    if (!isHub) {
      setLEDStatus(LED_TRANSMITTING);
      broadcastMyData();
      delay(100);
      setLEDStatus(LED_NO_WIFI);
    }
    lastBroadcast = millis();
  }
  
  // Hub uploads data every 30 seconds
  if (isHub) {
    static unsigned long lastDataSend = 0;
    if (millis() - lastDataSend > 30000) {
      setLEDStatus(LED_TRANSMITTING);
      sendAllDataToServer();
      sendAllDataViaBLE();
      delay(100);
      setLEDStatus(LED_HUB_MODE);
      lastDataSend = millis();
    }
    
    // Update coefficients every 60 seconds
    static unsigned long lastCoeffUpdate = 0;
    if (millis() - lastCoeffUpdate > 60000) {
      downloadAndDistributeCoeffs();
      lastCoeffUpdate = millis();
    }
  }
  
  // Clean up old devices
  static unsigned long lastCleanup = 0;
  if (millis() - lastCleanup > 60000) {
    for (int i = 0; i < deviceCount; i++) {
      if (millis() - knownDevices[i].lastSeen > 120000) {
        knownDevices[i].isActive = false;
      }
    }
    lastCleanup = millis();
  }
  
  delay(100);
}

void initBME280() {
  Serial.println("🌡️  Initializing BME280...");
  
  bmeInitialized = bme.begin(0x76, &Wire); // Try address 0x76 first
  
  if (!bmeInitialized) {
    bmeInitialized = bme.begin(0x77, &Wire); // Try 0x77 if 0x76 fails
  }
  
  if (bmeInitialized) {
    Serial.println("✅ BME280 initialized successfully!");
    
    // Configure BME280 for weather monitoring
    bme.setSampling(Adafruit_BME280::MODE_NORMAL,
                    Adafruit_BME280::SAMPLING_X2,  // temperature
                    Adafruit_BME280::SAMPLING_X16, // pressure
                    Adafruit_BME280::SAMPLING_X1,  // humidity
                    Adafruit_BME280::FILTER_X16,
                    Adafruit_BME280::STANDBY_MS_500);
                    
    // Read and display initial values
    float temp = bme.readTemperature() * 9.0/5.0 + 32.0; // Convert to F
    float pressure = bme.readPressure() / 6894.76; // Convert to PSI
    float altitude = bme.readAltitude(1013.25); // Sea level pressure in hPa
    
    Serial.printf("Initial readings: %.1f°F, %.2f PSI, %.1fm altitude\n", 
                 temp, pressure, altitude);
  } else {
    Serial.println("❌ BME280 initialization failed! Using dummy data.");
  }
}

void setLEDStatus(LEDStatus status) {
  static LEDStatus currentStatus = LED_OFF;
  currentStatus = status;
  
  switch (status) {
    case LED_OFF:
      pixel.setPixelColor(0, pixel.Color(0, 0, 0));
      break;
    case LED_BOOTING:
      pixel.setPixelColor(0, pixel.Color(0, 0, 50)); // Blue
      break;
    case LED_NO_WIFI:
      pixel.setPixelColor(0, pixel.Color(50, 0, 0)); // Red
      break;
    case LED_WIFI_OK:
      pixel.setPixelColor(0, pixel.Color(0, 50, 0)); // Green
      break;
    case LED_HUB_MODE:
      pixel.setPixelColor(0, pixel.Color(25, 0, 25)); // Purple
      break;
    case LED_TRANSMITTING:
      pixel.setPixelColor(0, pixel.Color(50, 50, 50)); // White
      break;
  }
  pixel.show();
}

void updateLED() {
  static unsigned long lastUpdate = 0;
  static uint8_t brightness = 0;
  static bool increasing = true;
  
  if (millis() - lastUpdate > 20) {
    // Pulse effect for certain states
    if (isHub || !isConnectedToWiFi) {
      if (increasing) {
        brightness += 5;
        if (brightness >= 50) increasing = false;
      } else {
        brightness -= 5;
        if (brightness <= 10) increasing = true;
      }
      pixel.setBrightness(brightness);
      
      if (isHub) {
        pixel.setPixelColor(0, pixel.Color(brightness/2, 0, brightness/2));
      } else {
        pixel.setPixelColor(0, pixel.Color(brightness, 0, 0));
      }
      pixel.show();
    }
    
    lastUpdate = millis();
  }
}

SensorData readSensors() {
  SensorData data;
  
  if (bmeInitialized) {
    // Read actual BME280 data
    data.temperature = bme.readTemperature() * 9.0/5.0 + 32.0; // Convert C to F
    data.atmosphericPressure = bme.readPressure() / 6894.76; // Convert Pa to PSI
    data.elevation = bme.readAltitude(1013.25) * 3.28084; // Convert m to feet
    
    // For now, use atmospheric pressure as main air pressure (will be calibrated later)
    data.mainAirPressure = data.atmosphericPressure + random(-5, 5) / 10.0;
    
    Serial.printf("📊 BME280: %.1f°F, %.2f PSI, %.0f ft\n", 
                 data.temperature, data.atmosphericPressure, data.elevation);
  } else {
    // Fallback to dummy data if BME280 failed
    data.mainAirPressure = 35.0 + (random(-50, 50)/10.0);
    data.atmosphericPressure = 14.7 + (random(-10, 10)/100.0);
    data.temperature = 72.0 + (random(-30, 30)/10.0);
    data.elevation = 1000 + random(-500, 500);
    
    Serial.println("⚠️  Using dummy sensor data (BME280 not available)");
  }
  
  // GPS data (placeholder for now)
  data.gpsLat = 40.7128 + (random(-100, 100)/1000.0);
  data.gpsLng = -74.0060 + (random(-100, 100)/1000.0);
  
  // Calculate weight using regression coefficients
  data.weight = coeffs.intercept + 
                (data.mainAirPressure * coeffs.airPressureCoeff) +
                (data.atmosphericPressure * coeffs.ambientPressureCoeff) +
                (data.temperature * coeffs.airTempCoeff);
  
  if (data.weight < 0) data.weight = 0;
  
  data.timestamp = getCurrentTimestamp();
  
  return data;
}

// [Rest of the functions remain the same - connectToWiFi, startAPMode, setupWebServer, etc.]
// I'll include them below for completeness:

void connectToWiFi() {
  Serial.println("Trying to connect to WiFi...");
  
  String savedSSID = preferences.getString("wifi_ssid", "");
  String savedPassword = preferences.getString("wifi_password", "");
  
  if (savedSSID.length() > 0) {
    Serial.println("Trying saved network: " + savedSSID);
    WiFi.begin(savedSSID.c_str(), savedPassword.c_str());
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
      delay(500);
      attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
      isConnectedToWiFi = true;
      Serial.println("Connected to saved WiFi: " + savedSSID);
      Serial.println("IP: " + WiFi.localIP().toString());
      return;
    }
  }
  
  Serial.println("Trying fallback network...");
  WiFi.begin(FALLBACK_SSID, FALLBACK_PASSWORD);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    attempts++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    isConnectedToWiFi = true;
    Serial.println("Connected to fallback WiFi");
    Serial.println("IP: " + WiFi.localIP().toString());
  }
}

void startAPMode() {
  Serial.println("Starting AP Mode: " + apSSID);
  WiFi.softAP(apSSID.c_str(), "airscales123");
  isAPMode = true;
  Serial.println("AP IP: " + WiFi.softAPIP().toString());
}

void setupWebServer() {
  server.serveStatic("/", SPIFFS, "/");
  
  server.on("/", HTTP_GET, []() {
    String html = "<h1>AirScales Custom Board</h1>";
    html += "<p>MAC: " + deviceMAC + "</p>";
    html += "<p>Role: " + String(isHub ? "HUB" : "DEVICE") + "</p>";
    html += "<p>BME280: " + String(bmeInitialized ? "OK" : "FAILED") + "</p>";
    server.send(200, "text/html", html);
  });
  
  server.on("/api/status", HTTP_GET, []() {
    DynamicJsonDocument doc(512);
    doc["mac_address"] = deviceMAC;
    doc["is_hub"] = isHub;
    doc["wifi_connected"] = isConnectedToWiFi;
    doc["ble_connected"] = deviceConnected;
    doc["known_devices"] = deviceCount;
    doc["bme280_initialized"] = bmeInitialized;
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
  });
  
  server.begin();
  Serial.println("Web server started");
}

// [Include all remaining functions from original code: initESPNow, onESPNowDataReceived, 
// onESPNowDataSent, broadcastMyData, sendAllDataToServer, sendAllDataViaBLE, 
// registerDevice, downloadAndDistributeCoeffs, sendCoeffsToDevice, initBLE, 
// updateDeviceData, findDevice, getCurrentTimestamp]

void initESPNow() {
  WiFi.mode(WIFI_STA);
  
  uint8_t currentChannel = 0;
  if (WiFi.status() == WL_CONNECTED) {
    currentChannel = WiFi.channel();
    Serial.printf("📡 WiFi connected on channel: %d\n", currentChannel);
  } else {
    wifi_second_chan_t dummy;
    esp_wifi_get_channel(&currentChannel, &dummy);
    Serial.printf("📡 WiFi not connected, current channel: %d\n", currentChannel);
  }
  
  Serial.printf("💾 Free RAM before ESP-NOW init: %d bytes\n", ESP.getFreeHeap());
  Serial.printf("📦 ESPNowData struct size: %d bytes\n", sizeof(ESPNowData));
  
  if (esp_now_init() != ESP_OK) {
    Serial.println("❌ Error initializing ESP-NOW");
    return;
  }
  
  esp_now_register_send_cb(onESPNowDataSent);
  esp_now_register_recv_cb(onESPNowDataReceived);
  
  Serial.printf("✅ ESP-NOW initialized | Ch:%d | Free RAM: %d bytes\n", currentChannel, ESP.getFreeHeap());
}

void onESPNowDataReceived(const uint8_t *mac, const uint8_t *incomingData, int len) {
  uint8_t currentChannel = 0;
  if (WiFi.status() == WL_CONNECTED) {
    currentChannel = WiFi.channel();
  } else {
    wifi_second_chan_t dummy;
    esp_wifi_get_channel(&currentChannel, &dummy);
  }
  
  Serial.printf("🔥 ESP-NOW RX: Size=%d bytes | Ch:%d | Free RAM: %d bytes\n", 
               len, currentChannel, ESP.getFreeHeap());
  
  if (len != sizeof(ESPNowData)) {
    if (len > 80 && len < 120) {
      Serial.printf("❌ Invalid ESP-NOW data size: expected %d, got %d\n", sizeof(ESPNowData), len);
    }
    return;
  }
  
  ESPNowData* data = (ESPNowData*)incomingData;
  
  Serial.printf("✅ Received ESP-NOW data from %s: %.1f lbs\n", data->deviceMAC, data->weight);
  
  // Channel locking for non-hub devices
  if (!isHub && !isConnectedToWiFi) {
    static bool channelLocked = false;
    static String hubMAC = "";
    static uint8_t targetChannel = 0;
    
    if (!channelLocked || hubMAC != String(data->deviceMAC)) {
      targetChannel = currentChannel;
      Serial.printf("🔒 HARD LOCKING to hub channel %d (hub MAC: %s)\n", targetChannel, data->deviceMAC);
      
      WiFi.mode(WIFI_STA);
      WiFi.disconnect();
      delay(100);
      
      esp_wifi_stop();
      delay(50);
      
      wifi_config_t wifi_config = {};
      esp_wifi_set_config(WIFI_IF_STA, &wifi_config);
      
      esp_wifi_start();
      delay(50);
      
      esp_wifi_set_promiscuous(true);
      esp_err_t result = esp_wifi_set_channel(targetChannel, WIFI_SECOND_CHAN_NONE);
      esp_wifi_set_promiscuous(false);
      
      if (result == ESP_OK) {
        Serial.printf("✅ Successfully locked to channel %d\n", targetChannel);
        channelLocked = true;
        hubMAC = String(data->deviceMAC);
      } else {
        Serial.printf("❌ Failed to lock channel: %d\n", result);
      }
    }
    
    // Verify channel
    uint8_t actualChannel;
    wifi_second_chan_t dummy;
    esp_wifi_get_channel(&actualChannel, &dummy);
    if (actualChannel != targetChannel && channelLocked) {
      Serial.printf("⚠️  Channel drift detected! Expected %d, got %d - re-locking\n", targetChannel, actualChannel);
      esp_wifi_set_promiscuous(true);
      esp_wifi_set_channel(targetChannel, WIFI_SECOND_CHAN_NONE);
      esp_wifi_set_promiscuous(false);
    }
  }
  
  updateDeviceData(data);
  
  if (isHub && (coeffs.intercept != 0.0 || coeffs.airPressureCoeff != 0.0)) {
    sendCoeffsToDevice(data->deviceMAC);
  }
}

void onESPNowDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {
  uint8_t currentChannel = 0;
  if (WiFi.status() == WL_CONNECTED) {
    currentChannel = WiFi.channel();
  } else {
    wifi_second_chan_t dummy;
    esp_wifi_get_channel(&currentChannel, &dummy);
  }
  
  Serial.printf("📤 ESP-NOW TX: %s | Ch:%d | To: %02X:%02X:%02X:%02X:%02X:%02X\n", 
               status == ESP_NOW_SEND_SUCCESS ? "SUCCESS" : "FAILED",
               currentChannel,
               mac_addr[0], mac_addr[1], mac_addr[2], mac_addr[3], mac_addr[4], mac_addr[5]);
}

void broadcastMyData() {
  SensorData sensorData = readSensors();
  
  ESPNowData data;
  strcpy(data.deviceMAC, deviceMAC.c_str());
  strcpy(data.deviceName, bleDeviceName.c_str());
  data.mainAirPressure = sensorData.mainAirPressure;
  data.atmosphericPressure = sensorData.atmosphericPressure;
  data.temperature = sensorData.temperature;
  data.elevation = sensorData.elevation;
  data.gpsLat = sensorData.gpsLat;
  data.gpsLng = sensorData.gpsLng;
  data.weight = sensorData.weight;
  data.timestamp = millis();
  data.batteryLevel = 85;
  data.isCharging = false;
  
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  esp_now_peer_info_t peerInfo = {};
  memcpy(peerInfo.peer_addr, broadcastAddress, 6);
  peerInfo.channel = 0;
  peerInfo.encrypt = false;
  
  if (!esp_now_is_peer_exist(broadcastAddress)) {
    esp_now_add_peer(&peerInfo);
  }
  
  uint8_t currentChannel = 0;
  if (WiFi.status() == WL_CONNECTED) {
    currentChannel = WiFi.channel();
  } else {
    wifi_second_chan_t dummy;
    esp_wifi_get_channel(&currentChannel, &dummy);
  }
  
  esp_err_t result = esp_now_send(broadcastAddress, (uint8_t*)&data, sizeof(data));
  
  if (result == ESP_OK) {
    Serial.printf("📡 Broadcasting %.1f lbs | Coeffs: intercept=%.2f, air=%.4f | Ch:%d | Free RAM: %d bytes\n", 
                 data.weight, coeffs.intercept, coeffs.airPressureCoeff, currentChannel, ESP.getFreeHeap());
  } else {
    Serial.printf("❌ Broadcast failed: %d | Ch:%d | Free RAM: %d bytes\n", result, currentChannel, ESP.getFreeHeap());
  }
}

void sendAllDataToServer() {
  if (!isConnectedToWiFi) return;
  
  SensorData myData = readSensors();
  
  DynamicJsonDocument doc(1024);
  doc["mac_address"] = deviceMAC;
  doc["main_air_pressure"] = myData.mainAirPressure;
  doc["atmospheric_pressure"] = myData.atmosphericPressure;
  doc["temperature"] = myData.temperature;
  doc["elevation"] = myData.elevation;
  doc["gps_lat"] = myData.gpsLat;
  doc["gps_lng"] = myData.gpsLng;
  doc["weight"] = myData.weight;
  doc["timestamp"] = getCurrentTimestamp();
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  http.begin(String(SERVER_URL) + "/api/microdata");
  http.addHeader("Content-Type", "application/json");
  int httpResponseCode = http.POST(jsonString);
  
  if (httpResponseCode > 0) {
    Serial.println("Sent my data to server: " + String(httpResponseCode));
  } else {
    Serial.println("❌ Failed to send data to server: " + String(httpResponseCode));
  }
  http.end();
  
  // Send all collected device data
  for (int i = 0; i < deviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 60000) {
      DynamicJsonDocument slaveDoc(1024);
      slaveDoc["mac_address"] = knownDevices[i].macAddress;
      slaveDoc["main_air_pressure"] = knownDevices[i].lastData.mainAirPressure;
      slaveDoc["atmospheric_pressure"] = knownDevices[i].lastData.atmosphericPressure;
      slaveDoc["temperature"] = knownDevices[i].lastData.temperature;
      slaveDoc["elevation"] = knownDevices[i].lastData.elevation;
      slaveDoc["gps_lat"] = knownDevices[i].lastData.gpsLat;
      slaveDoc["gps_lng"] = knownDevices[i].lastData.gpsLng;
      slaveDoc["weight"] = knownDevices[i].lastData.weight;
      slaveDoc["timestamp"] = String(knownDevices[i].lastData.timestamp);
      
      String slaveJson;
      serializeJson(slaveDoc, slaveJson);
      
      http.begin(String(SERVER_URL) + "/api/microdata");
      http.addHeader("Content-Type", "application/json");
      int responseCode = http.POST(slaveJson);
      
      if (responseCode > 0) {
        Serial.printf("Sent device %s data to server: %d\n", knownDevices[i].macAddress, responseCode);
      }
      http.end();
      
      delay(100);
    }
  }
}

void sendAllDataViaBLE() {
  if (!deviceConnected || !bleEnabled) return;
  
  SensorData myData = readSensors();
  
  DynamicJsonDocument doc(1024);
  doc["mac_address"] = deviceMAC;
  doc["main_air_pressure"] = myData.mainAirPressure;
  doc["atmospheric_pressure"] = myData.atmosphericPressure;
  doc["temperature"] = myData.temperature;
  doc["elevation"] = myData.elevation;
  doc["gps_lat"] = myData.gpsLat;
  doc["gps_lng"] = myData.gpsLng;
  doc["weight"] = myData.weight;
  doc["timestamp"] = getCurrentTimestamp();
  doc["role"] = "hub";
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  pSensorCharacteristic->setValue(jsonString.c_str());
  pSensorCharacteristic->notify();
  
  delay(100);
  
  for (int i = 0; i < deviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 60000) {
      DynamicJsonDocument deviceDoc(1024);
      deviceDoc["mac_address"] = knownDevices[i].macAddress;
      deviceDoc["main_air_pressure"] = knownDevices[i].lastData.mainAirPressure;
      deviceDoc["atmospheric_pressure"] = knownDevices[i].lastData.atmosphericPressure;
      deviceDoc["temperature"] = knownDevices[i].lastData.temperature;
      deviceDoc["elevation"] = knownDevices[i].lastData.elevation;
      deviceDoc["gps_lat"] = knownDevices[i].lastData.gpsLat;
      deviceDoc["gps_lng"] = knownDevices[i].lastData.gpsLng;
      deviceDoc["weight"] = knownDevices[i].lastData.weight;
      deviceDoc["timestamp"] = String(knownDevices[i].lastData.timestamp);
      deviceDoc["role"] = "device";
      deviceDoc["device_name"] = knownDevices[i].deviceName;
      
      String deviceJson;
      serializeJson(deviceDoc, deviceJson);
      
      pSensorCharacteristic->setValue(deviceJson.c_str());
      pSensorCharacteristic->notify();
      
      delay(100);
    }
  }
}

void registerDevice() {
  if (!isConnectedToWiFi) return;
  
  http.begin(String(SERVER_URL) + "/api/bridge/register");
  http.addHeader("Content-Type", "application/json");
  
  DynamicJsonDocument doc(512);
  doc["mac_address"] = deviceMAC;
  doc["device_type"] = "AirScales_Custom";
  doc["firmware_version"] = "3.0.0";
  doc["is_hub"] = isHub;
  doc["has_bme280"] = bmeInitialized;
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  int httpResponseCode = http.POST(jsonString);
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.println("Device registered: " + String(httpResponseCode));
    
    DynamicJsonDocument responseDoc(1024);
    deserializeJson(responseDoc, response);
    
    if (responseDoc.containsKey("regression_coefficients")) {
      JsonObject regCoeffs = responseDoc["regression_coefficients"];
      
      float newIntercept = regCoeffs["intercept"] | 0.0;
      float newAirCoeff = regCoeffs["air_pressure_coeff"] | 0.0;
      float newAmbientCoeff = regCoeffs["ambient_pressure_coeff"] | 0.0;
      float newTempCoeff = regCoeffs["air_temp_coeff"] | 0.0;
      
      Serial.printf("🔄 OLD COEFFS: intercept=%.4f, air=%.4f, ambient=%.4f, temp=%.4f\n", 
                   coeffs.intercept, coeffs.airPressureCoeff, coeffs.ambientPressureCoeff, coeffs.airTempCoeff);
      Serial.printf("🆕 NEW COEFFS: intercept=%.4f, air=%.4f, ambient=%.4f, temp=%.4f\n", 
                   newIntercept, newAirCoeff, newAmbientCoeff, newTempCoeff);
      
      coeffs.intercept = newIntercept;
      coeffs.airPressureCoeff = newAirCoeff;
      coeffs.ambientPressureCoeff = newAmbientCoeff;
      coeffs.airTempCoeff = newTempCoeff;
      
      preferences.putFloat("intercept", coeffs.intercept);
      preferences.putFloat("air_coeff", coeffs.airPressureCoeff);
      preferences.putFloat("ambient_coeff", coeffs.ambientPressureCoeff);
      preferences.putFloat("temp_coeff", coeffs.airTempCoeff);
      
      Serial.println("✅ Updated regression coefficients from server");
    }
  } else {
    Serial.println("❌ Failed to register device: " + String(httpResponseCode));
  }
  
  http.end();
}

void downloadAndDistributeCoeffs() {
  if (!isHub) return;
  
  Serial.println("🔄 Downloading and distributing coefficients...");
  
  registerDevice();
  
  int sentCount = 0;
  for (int i = 0; i < deviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 120000) {
      sendCoeffsToDevice(knownDevices[i].macAddress);
      sentCount++;
      delay(100);
    }
  }
  
  Serial.printf("📤 Sent coefficients to %d active devices\n", sentCount);
}

void sendCoeffsToDevice(const char* targetMAC) {
  if (coeffs.intercept == 0.0 && coeffs.airPressureCoeff == 0.0) {
    Serial.printf("⚠️  No coefficients to send to %s\n", targetMAC);
    return; 
  }
  
  Serial.printf("📡 Sending coefficients to %s: intercept=%.2f, air=%.4f\n", 
               targetMAC, coeffs.intercept, coeffs.airPressureCoeff);
  
  uint8_t macBytes[6];
  sscanf(targetMAC, "%hhx:%hhx:%hhx:%hhx:%hhx:%hhx",
         &macBytes[0], &macBytes[1], &macBytes[2],
         &macBytes[3], &macBytes[4], &macBytes[5]);
  
  ESPNowData coeffsData;
  strcpy(coeffsData.deviceMAC, deviceMAC.c_str());
  strcpy(coeffsData.deviceName, "COEFFS_UPDATE");
  coeffsData.mainAirPressure = coeffs.intercept;
  coeffsData.atmosphericPressure = coeffs.airPressureCoeff;
  coeffsData.temperature = coeffs.ambientPressureCoeff;
  coeffsData.elevation = coeffs.airTempCoeff;
  coeffsData.timestamp = millis();
  
  esp_now_peer_info_t peerInfo = {};
  memcpy(peerInfo.peer_addr, macBytes, 6);
  peerInfo.channel = 0;
  peerInfo.encrypt = false;
  
  if (!esp_now_is_peer_exist(macBytes)) {
    esp_now_add_peer(&peerInfo);
  }
  
  esp_err_t result = esp_now_send(macBytes, (uint8_t*)&coeffsData, sizeof(coeffsData));
  if (result != ESP_OK) {
    Serial.printf("❌ Failed to send coefficients to %s: %d\n", targetMAC, result);
  }
}

void initBLE() {
  Serial.println("Initializing BLE...");
  
  BLEDevice::init(bleDeviceName.c_str());
  
  pServer = BLEDevice::createServer();
  pServer->setCallbacks(new MyServerCallbacks());
  
  BLEService *pService = pServer->createService(SERVICE_UUID);
  
  pSensorCharacteristic = pService->createCharacteristic(
      SENSOR_CHAR_UUID,
      BLECharacteristic::PROPERTY_READ | BLECharacteristic::PROPERTY_NOTIFY
  );
  pSensorCharacteristic->addDescriptor(new BLE2902());
  
  pCoeffsCharacteristic = pService->createCharacteristic(
      COEFFS_CHAR_UUID,
      BLECharacteristic::PROPERTY_WRITE
  );
  pCoeffsCharacteristic->setCallbacks(new CoeffsCallbacks());
  
  pService->start();
  
  BLEAdvertising *pAdvertising = BLEDevice::getAdvertising();
  pAdvertising->addServiceUUID(SERVICE_UUID);
  pAdvertising->setScanResponse(false);
  pAdvertising->setMinPreferred(0x0);
  
  pServer->startAdvertising();
  Serial.println("✅ BLE advertising started");
  
  bleEnabled = true;
}

void updateDeviceData(ESPNowData* data) {
  if (strcmp(data->deviceName, "COEFFS_UPDATE") == 0) {
    float oldIntercept = coeffs.intercept;
    float oldAirCoeff = coeffs.airPressureCoeff;
    
    coeffs.intercept = data->mainAirPressure;
    coeffs.airPressureCoeff = data->atmosphericPressure;
    coeffs.ambientPressureCoeff = data->temperature;
    coeffs.airTempCoeff = data->elevation;
    
    preferences.putFloat("intercept", coeffs.intercept);
    preferences.putFloat("air_coeff", coeffs.airPressureCoeff);
    preferences.putFloat("ambient_coeff", coeffs.ambientPressureCoeff);
    preferences.putFloat("temp_coeff", coeffs.airTempCoeff);
    
    Serial.printf("🔄 Updated coefficients via ESP-NOW: intercept %.2f→%.2f, air %.4f→%.4f\n", 
                 oldIntercept, coeffs.intercept, oldAirCoeff, coeffs.airPressureCoeff);
    return;
  }
  
  DeviceData* device = findDevice(data->deviceMAC);
  
  if (device == nullptr && deviceCount < MAX_DEVICES) {
    device = &knownDevices[deviceCount++];
    strcpy(device->macAddress, data->deviceMAC);
    Serial.printf("Added new device: %s\n", data->deviceMAC);
  }
  
  if (device != nullptr) {
    strcpy(device->deviceName, data->deviceName);
    memcpy(&device->lastData, data, sizeof(ESPNowData));
    device->lastSeen = millis();
    device->isActive = true;
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

String getCurrentTimestamp() {
  return String(millis());
}
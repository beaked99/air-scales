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

// Hardware Configuration
#define STATUS_LED_PIN 13

// BLE configuration
#define SERVICE_UUID        "12345678-1234-1234-1234-123456789abc"
#define SENSOR_CHAR_UUID    "87654321-4321-4321-4321-cba987654321"
#define COEFFS_CHAR_UUID    "11111111-2222-3333-4444-555555555555"
#define DEVICE_NAME_PREFIX  "AirScales-"

// Network Configuration
const char* SERVER_URL = "https://beaker.ca";
const char* FALLBACK_SSID = "YourHomeWiFi";    
const char* FALLBACK_PASSWORD = "YourPassword"; 

// Global Objects
WebServer server(80);
WebSocketsServer webSocket(81);
Preferences preferences;
HTTPClient http;

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
bool isHub = false; // Simple: am I the hub or not?

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

// BLE Callback Classes
class MyServerCallbacks: public BLEServerCallbacks {
    void onConnect(BLEServer* pServer) {
        deviceConnected = true;
        Serial.println("BLE Client Connected!");
        // Download coefficients when BLE connects
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
  
  Serial.println("AirScales ESP32 Starting...");
  
  // Initialize hardware
  pinMode(STATUS_LED_PIN, OUTPUT);
  digitalWrite(STATUS_LED_PIN, LOW);
  
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
  }
  
  // Determine if I'm a hub (have WiFi or will have BLE)
  isHub = isConnectedToWiFi;
  Serial.println(isHub ? "I AM THE HUB" : "I am a regular device");
  
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
  
  Serial.println("AirScales Ready!");
  digitalWrite(STATUS_LED_PIN, HIGH);
}

void loop() {
  // Handle web server
  server.handleClient();
  webSocket.loop();
  
  // If someone connects via BLE, I become a hub
  if (!isHub && deviceConnected) {
    isHub = true;
    Serial.println("BLE connected - I AM NOW THE HUB");
    downloadAndDistributeCoeffs();
  }
  
  // Add periodic memory and status debugging
  static unsigned long lastDebugPrint = 0;
  if (millis() - lastDebugPrint > 60000) { // Every 60 seconds
    uint8_t currentChannel = 0;
    if (WiFi.status() == WL_CONNECTED) {
      currentChannel = WiFi.channel();
    } else {
      wifi_second_chan_t dummy;
      esp_wifi_get_channel(&currentChannel, &dummy);
    }
    
    Serial.printf("🔍 STATUS: %s | Ch:%d | RAM:%d | Known devices:%d | WiFi:%s\n", 
                 isHub ? "HUB" : "DEVICE", 
                 currentChannel, 
                 ESP.getFreeHeap(), 
                 deviceCount,
                 isConnectedToWiFi ? "OK" : "NO");
    
    // Show known devices
    for (int i = 0; i < deviceCount; i++) {
      if (knownDevices[i].isActive) {
        Serial.printf("   Device %d: %s | Last seen: %lu ms ago\n", 
                     i, knownDevices[i].macAddress, millis() - knownDevices[i].lastSeen);
      }
    }
    
    lastDebugPrint = millis();
  }
  
  // Broadcast my sensor data every 10 seconds (only if not hub)
  static unsigned long lastBroadcast = 0;
  if (millis() - lastBroadcast > 10000) {
    if (!isHub) {  // Only non-hub devices broadcast via ESP-NOW
      broadcastMyData();
    }
    lastBroadcast = millis();
  }
  
  // If I'm the hub, send all data every 30 seconds
  if (isHub) {
    static unsigned long lastDataSend = 0;
    if (millis() - lastDataSend > 30000) {
      sendAllDataToServer();
      sendAllDataViaBLE();
      lastDataSend = millis();
    }
    
    // Send updated coefficients to all devices every 60 seconds
    static unsigned long lastCoeffUpdate = 0;
    if (millis() - lastCoeffUpdate > 60000) {
      downloadAndDistributeCoeffs();
      lastCoeffUpdate = millis();
    }
  }
  
  // Clean up old devices every 60 seconds
  static unsigned long lastCleanup = 0;
  if (millis() - lastCleanup > 60000) {
    for (int i = 0; i < deviceCount; i++) {
      if (millis() - knownDevices[i].lastSeen > 120000) { // 2 minutes timeout
        knownDevices[i].isActive = false;
      }
    }
    lastCleanup = millis();
  }
  
  // Blink status LED
  static unsigned long lastBlink = 0;
  if (millis() - lastBlink > (isHub ? 500 : 1000)) {
    digitalWrite(STATUS_LED_PIN, !digitalRead(STATUS_LED_PIN));
    lastBlink = millis();
  }
  
  delay(100);
}

void connectToWiFi() {
  Serial.println("Trying to connect to WiFi...");
  
  // Try saved networks first
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
  
  // Try fallback network
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
    server.send(200, "text/html", "<h1>AirScales Device</h1><p>MAC: " + deviceMAC + "</p><p>Role: " + (isHub ? "HUB" : "DEVICE") + "</p>");
  });
  
  server.on("/api/status", HTTP_GET, []() {
    DynamicJsonDocument doc(512);
    doc["mac_address"] = deviceMAC;
    doc["is_hub"] = isHub;
    doc["wifi_connected"] = isConnectedToWiFi;
    doc["ble_connected"] = deviceConnected;
    doc["known_devices"] = deviceCount;
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
  });
  
  server.begin();
  Serial.println("Web server started");
}

void initESPNow() {
  WiFi.mode(WIFI_STA);
  
  // Show current WiFi channel and memory status
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
  // Get current channel for debugging
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
    // Only log if it's close to our expected size (reduce spam)
    if (len > 80 && len < 120) {
      Serial.printf("❌ Invalid ESP-NOW data size: expected %d, got %d\n", sizeof(ESPNowData), len);
    }
    return;
  }
  
  ESPNowData* data = (ESPNowData*)incomingData;
  
  Serial.printf("✅ Received ESP-NOW data from %s: %.1f lbs\n", data->deviceMAC, data->weight);
  
  // **NEW: If I'm not a hub and received data from a hub, lock to their channel**
  if (!isHub && !isConnectedToWiFi) {
    static bool channelLocked = false;
    static String hubMAC = "";
    static uint8_t targetChannel = 0;
    
    if (!channelLocked || hubMAC != String(data->deviceMAC)) {
      // Lock to the channel we received this message on
      targetChannel = currentChannel;
      Serial.printf("🔒 HARD LOCKING to hub channel %d (hub MAC: %s)\n", targetChannel, data->deviceMAC);
      
      // Aggressive channel lock sequence
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
    
    // Verify we're still on the right channel
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
  
  // Update device data
  updateDeviceData(data);
  
  // If I'm the hub and have coefficients, send them back to this device
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
  
  // Broadcast to everyone
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  esp_now_peer_info_t peerInfo = {};
  memcpy(peerInfo.peer_addr, broadcastAddress, 6);
  peerInfo.channel = 0;
  peerInfo.encrypt = false;
  
  if (!esp_now_is_peer_exist(broadcastAddress)) {
    esp_now_add_peer(&peerInfo);
  }
  
  // Get current WiFi channel for debugging
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
  
  // Send my own data
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
      
      delay(100); // Small delay between requests
    }
  }
}

void sendAllDataViaBLE() {
  if (!deviceConnected || !bleEnabled) return;
  
  // Send my own data
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
  
  // Send all device data
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

SensorData readSensors() {
  SensorData data;
  
  // Generate realistic dummy data
  static float basePressure = 35.0;
  static float baseTemp = 72.0;
  
  float pressureVariation = (random(-50, 50) / 100.0);
  float tempVariation = (random(-30, 30) / 10.0);
  
  data.mainAirPressure = basePressure + pressureVariation;
  data.atmosphericPressure = 14.7 + (random(-10, 10) / 100.0);
  data.temperature = baseTemp + tempVariation;
  data.elevation = 1000 + random(-50, 50);
  data.gpsLat = 40.7128 + (random(-100, 100) / 100000.0);
  data.gpsLng = -74.0060 + (random(-100, 100) / 100000.0);
  
  // Calculate weight using regression coefficients
  data.weight = coeffs.intercept + 
                (data.mainAirPressure * coeffs.airPressureCoeff) +
                (data.atmosphericPressure * coeffs.ambientPressureCoeff) +
                (data.temperature * coeffs.airTempCoeff);
  
  if (data.weight < 0) data.weight = 0;
  
  data.timestamp = getCurrentTimestamp();
  
  return data;
}

void registerDevice() {
  if (!isConnectedToWiFi) return;
  
  http.begin(String(SERVER_URL) + "/api/esp32/register");
  http.addHeader("Content-Type", "application/json");
  
  DynamicJsonDocument doc(512);
  doc["mac_address"] = deviceMAC;
  doc["device_type"] = "FeatherS3";
  doc["firmware_version"] = "2.0.0";
  doc["is_hub"] = isHub;
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  int httpResponseCode = http.POST(jsonString);
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.println("Device registered: " + String(httpResponseCode));
    
    // Parse regression coefficients from response
    DynamicJsonDocument responseDoc(1024);
    deserializeJson(responseDoc, response);
    
    if (responseDoc.containsKey("regression_coefficients")) {
      JsonObject regCoeffs = responseDoc["regression_coefficients"];
      
      coeffs.intercept = regCoeffs["intercept"] | 0.0;
      coeffs.airPressureCoeff = regCoeffs["air_pressure_coeff"] | 0.0;
      coeffs.ambientPressureCoeff = regCoeffs["ambient_pressure_coeff"] | 0.0;
      coeffs.airTempCoeff = regCoeffs["air_temp_coeff"] | 0.0;
      
      // Save coefficients
      preferences.putFloat("intercept", coeffs.intercept);
      preferences.putFloat("air_coeff", coeffs.airPressureCoeff);
      preferences.putFloat("ambient_coeff", coeffs.ambientPressureCoeff);
      preferences.putFloat("temp_coeff", coeffs.airTempCoeff);
      
      Serial.println("Updated regression coefficients from server");
    }
  }
  
  http.end();
}

void downloadAndDistributeCoeffs() {
  if (!isHub) return;
  
  Serial.println("🔄 Downloading and distributing coefficients...");
  
  // First, get my coefficients from server
  registerDevice();
  
  // Then send them to all known devices
  int sentCount = 0;
  for (int i = 0; i < deviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 120000) { // Active within 2 minutes
      sendCoeffsToDevice(knownDevices[i].macAddress);
      sentCount++;
      delay(100); // Small delay between sends
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
  
  // Convert MAC string to bytes
  uint8_t macBytes[6];
  sscanf(targetMAC, "%hhx:%hhx:%hhx:%hhx:%hhx:%hhx",
         &macBytes[0], &macBytes[1], &macBytes[2],
         &macBytes[3], &macBytes[4], &macBytes[5]);
  
  // Create coefficients message
  ESPNowData coeffsData;
  strcpy(coeffsData.deviceMAC, deviceMAC.c_str());
  strcpy(coeffsData.deviceName, "COEFFS_UPDATE");
  coeffsData.mainAirPressure = coeffs.intercept;
  coeffsData.atmosphericPressure = coeffs.airPressureCoeff;
  coeffsData.temperature = coeffs.ambientPressureCoeff;
  coeffsData.elevation = coeffs.airTempCoeff;
  coeffsData.timestamp = millis();
  
  // Add peer if not exists
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
  Serial.println("BLE advertising started");
  
  bleEnabled = true;
}

void updateDeviceData(ESPNowData* data) {
  // Check if this is a coefficients update
  if (strcmp(data->deviceName, "COEFFS_UPDATE") == 0) {
    float oldIntercept = coeffs.intercept;
    float oldAirCoeff = coeffs.airPressureCoeff;
    
    coeffs.intercept = data->mainAirPressure;
    coeffs.airPressureCoeff = data->atmosphericPressure;
    coeffs.ambientPressureCoeff = data->temperature;
    coeffs.airTempCoeff = data->elevation;
    
    // Save coefficients
    preferences.putFloat("intercept", coeffs.intercept);
    preferences.putFloat("air_coeff", coeffs.airPressureCoeff);
    preferences.putFloat("ambient_coeff", coeffs.ambientPressureCoeff);
    preferences.putFloat("temp_coeff", coeffs.airTempCoeff);
    
    Serial.printf("🔄 Updated coefficients via ESP-NOW: intercept %.2f→%.2f, air %.4f→%.4f\n", 
                 oldIntercept, coeffs.intercept, oldAirCoeff, coeffs.airPressureCoeff);
    return;
  }
  
  // Find or create device entry
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
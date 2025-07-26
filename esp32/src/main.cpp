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

//BLE configuration shit
#define SERVICE_UUID        "12345678-1234-1234-1234-123456789abc"
#define SENSOR_CHAR_UUID    "87654321-4321-4321-4321-cba987654321"
#define COEFFS_CHAR_UUID    "11111111-2222-3333-4444-555555555555"
#define DEVICE_NAME_PREFIX  "AirScales-"

// ESPNow Configuration
#define MAX_SLAVES 10           // Maximum devices in mesh
#define MESH_CHANNEL 1          // WiFi channel for mesh
#define DISCOVERY_INTERVAL 30000 // 30 seconds between discovery broadcasts
#define HEARTBEAT_INTERVAL 10000 // 10 seconds between slave heartbeats

// Network Configuration
const char* SERVER_URL = "https://beaker.ca";
const char* FALLBACK_SSID = "YourHomeWiFi";    // Replace with your WiFi
const char* FALLBACK_PASSWORD = "YourPassword"; // Replace with your password

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
unsigned long lastDataSend = 0;
unsigned long lastHeartbeat = 0;

// Device Role Enumeration
enum DeviceRole {
  ROLE_UNDEFINED = 0,
  ROLE_MASTER = 1,
  ROLE_SLAVE = 2,
  ROLE_DISCOVERY = 3
};

// Message Types for ESPNow Communication
enum MessageType {
  MSG_DISCOVERY_BROADCAST = 1,
  MSG_DISCOVERY_RESPONSE = 2,
  MSG_PAIRING_REQUEST = 3,
  MSG_PAIRING_RESPONSE = 4,
  MSG_SENSOR_DATA = 5,
  MSG_HEARTBEAT = 6,
  MSG_ROLE_ASSIGNMENT = 7,
  MSG_CONFIGURATION_SAVE = 8
};

// ESPNow Message Structure
typedef struct {
  uint8_t messageType;
  uint8_t senderMAC[6];
  char deviceName[32];
  char truckConfig[64];
  float sensorData[8];  // [pressure, temp, weight, lat, lng, elevation, atmospheric, timestamp]
  uint32_t timestamp;
  uint8_t batteryLevel;
  bool isCharging;
} MeshMessage;

// Device Information Structure
typedef struct {
  uint8_t macAddress[6];
  char deviceName[32];
  DeviceRole role;
  unsigned long lastSeen;
  float lastSensorData[8];
  bool isActive;
  uint8_t signalStrength;
} DeviceInfo;

// Global Mesh Variables
DeviceRole currentRole = ROLE_UNDEFINED;
String truckConfiguration = "";
DeviceInfo knownDevices[MAX_SLAVES];
uint8_t knownDeviceCount = 0;
unsigned long lastDiscovery = 0;
//unsigned long lastHeartbeat = 0;
bool meshInitialized = false;
bool meshPeerSetup = false;
bool hasWiFiMaster();
bool hasAnyMaster();

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
void webSocketEvent(uint8_t num, WStype_t type, uint8_t * payload, size_t length);
void registerDevice();
SensorData readSensors();
void storeDataLocally(SensorData data);
void sendDataToServer(SensorData data);
void broadcastSensorData(SensorData data);
void sendHeartbeat();
void parseRegressionCoeffs(String response);
void saveRegressionCoeffs();
void loadRegressionCoeffs();
void handleStatus();
void handleSensors();
void handleWiFiConfig();
void handleRestart();
void handleDataDownload();
void checkForUpdatedCoeffs(String response);
String getCurrentTimestamp();
void initBLE();
void sendSensorDataViaBLE(SensorData data);

// ESPNow, Master / Slave Shit
void initMeshNetworking();
void onESPNowDataReceived(const uint8_t *mac_addr, const uint8_t *data, int data_len);
void onESPNowDataSent(const uint8_t *mac_addr, esp_now_send_status_t status);
void handleDiscoveryBroadcast();
void handleDiscoveryResponse(const uint8_t *mac_addr, MeshMessage* msg);
void handlePairingRequest(const uint8_t *mac_addr, MeshMessage* msg);
void handleSensorData(const uint8_t *mac_addr, MeshMessage* msg);
void sendMeshMessage(const uint8_t *mac_addr, MeshMessage* msg);
void broadcastMeshMessage(MeshMessage* msg);
void becomeMaster();
void becomeSlave(const uint8_t *masterMAC);
void aggregateSlaveData();
void saveTruckConfiguration();
void loadTruckConfiguration();
void handleMeshLoop();
DeviceInfo* findDevice(const uint8_t *mac_addr);
void addOrUpdateDevice(const uint8_t *mac_addr, MeshMessage* msg);
void removeInactiveDevices();
void registerMeshStatus();

void setupBroadcastPeer();
void sendAggregatedDataViaBLE();
void sendSensorDataToMaster();
void initMeshNetworkingFixed();
void setupBroadcastPeerFixed();

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
  
  Serial.println("Device MAC: " + deviceMAC);
  Serial.println("AP SSID: " + apSSID);
  
  // Initialize SPIFFS
  if (!SPIFFS.begin(true)) {
    Serial.println("SPIFFS Mount Failed");
  } else {
    Serial.println("SPIFFS Mounted");
  }
  
  // Initialize preferences
  preferences.begin("airscales", false);
  loadRegressionCoeffs();
  
  // Try to connect to known WiFi networks
  connectToWiFi();
  initMeshNetworking();
  // If no WiFi connection, start AP mode
  if (!isConnectedToWiFi) {
    startAPMode();
  }
  
  // Setup web server routes
  setupWebServer();

  // Initialize BLE
  initBLE();
  
  // Start WebSocket server
  webSocket.begin();
  webSocket.onEvent(webSocketEvent);
  
  // Register device with server (if connected)
  if (isConnectedToWiFi) {
    registerDevice();
  }
  
  Serial.println("AirScales Ready!");
  digitalWrite(STATUS_LED_PIN, HIGH);

  // Initialize mesh networking
  initMeshNetworking();
  
  Serial.println("AirScales Ready with Mesh!");
  digitalWrite(STATUS_LED_PIN, HIGH);

  initMeshNetworkingFixed();

  Serial.println("AirScales Ready with Fixed Mesh!");
  digitalWrite(STATUS_LED_PIN, HIGH);
}

void loop() {
  // Handle web server
  server.handleClient();
  webSocket.loop();
  handleMeshLoop();

  // Check WiFi connection every 10 seconds
  static unsigned long lastWiFiCheck = 0;
  if (millis() - lastWiFiCheck > 10000) {
  //    checkWiFiConnection();
      lastWiFiCheck = millis();
  }
  //Read sensors and send data on BLE every second.
  static unsigned long lastSensorRead = 0;
  if (millis() - lastSensorRead > 1000) {
      SensorData data = readSensors();
      
      // Send via BLE every second (if connected)
      sendSensorDataViaBLE(data);
      
      // Send via WebSocket to AP clients
      broadcastSensorData(data);
      
      lastSensorRead = millis();
  }
  
  // Read sensors and send data every 30 seconds
  if (millis() - lastDataSend > 30000) {
    SensorData data = readSensors();
    
    // Store locally
    storeDataLocally(data);
    
    // Send to server if connected
    if (isConnectedToWiFi) {
        sendDataToServer(data);
    } else {
        Serial.println("Skipping server send - no WiFi connection");
    }
    
    lastDataSend = millis();
  }

  // Register mesh status every 30 seconds
  static unsigned long lastMeshRegister = 0;
  if (millis() - lastMeshRegister > 30000) {
    registerMeshStatus();
    lastMeshRegister = millis();
  }
  
  // Heartbeat every 5 minutes
  if (millis() - lastHeartbeat > 300000) {
    if (isConnectedToWiFi) {
      sendHeartbeat();
    }
    lastHeartbeat = millis();
  }
  
  // Blink status LED
  static unsigned long lastBlink = 0;
  if (millis() - lastBlink > 1000) {
    digitalWrite(STATUS_LED_PIN, !digitalRead(STATUS_LED_PIN));
    lastBlink = millis();
  }
  
  delay(100);
}

void connectToWiFi() {
  Serial.println("Scanning for known WiFi networks...");
  
  // Try saved networks first
  String savedSSID = preferences.getString("wifi_ssid", "");
  String savedPassword = preferences.getString("wifi_password", "");
  
  if (savedSSID.length() > 0) {
    Serial.println("Trying saved network: " + savedSSID);
    WiFi.begin(savedSSID.c_str(), savedPassword.c_str());
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
      delay(500);
      Serial.print(".");
      attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
      isConnectedToWiFi = true;
      Serial.println("");
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
    Serial.print(".");
    attempts++;
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    isConnectedToWiFi = true;
    Serial.println("");
    Serial.println("Connected to fallback WiFi");
    Serial.println("IP: " + WiFi.localIP().toString());
  } else {
    Serial.println("");
    Serial.println("WiFi connection failed");
  }
}

void startAPMode() {
  Serial.println("Starting AP Mode: " + apSSID);
  
  WiFi.softAP(apSSID.c_str(), "airscales123"); // Default password
  isAPMode = true;
  
  IPAddress IP = WiFi.softAPIP();
  Serial.println("AP IP address: " + IP.toString());
  Serial.println("Connect to: " + apSSID);
  Serial.println("Password: airscales123");
}

void setupWebServer() {
  // Serve static files from SPIFFS
  server.serveStatic("/", SPIFFS, "/");
  
  // Default route to index.html
  server.on("/", HTTP_GET, []() {
    File file = SPIFFS.open("/index.html", "r");
    if (file) {
      server.streamFile(file, "text/html");
      file.close();
    } else {
      server.send(404, "text/plain", "index.html not found");
    }
  });
  
  // API endpoints
  server.on("/api/status", HTTP_GET, handleStatus);
  server.on("/api/sensors", HTTP_GET, handleSensors);
  server.on("/api/wifi", HTTP_POST, handleWiFiConfig);
  server.on("/api/restart", HTTP_POST, handleRestart);
  server.on("/data", HTTP_GET, handleDataDownload);
  
  server.begin();
  Serial.println("Web server started");
}

SensorData readSensors() {
  SensorData data;
  
  // Generate realistic dummy data for testing
  static float basePressure = 35.0;  // Base air pressure
  static float baseTemp = 72.0;      // Base temperature
  
  // Add some realistic variation every reading
  float pressureVariation = (random(-50, 50) / 100.0);  // ±0.5 PSI variation
  float tempVariation = (random(-30, 30) / 10.0);       // ±3°F variation
  
  data.mainAirPressure = basePressure + pressureVariation;
  data.atmosphericPressure = 14.7 + (random(-10, 10) / 100.0);  // ±0.1 PSI atmospheric variation
  data.temperature = baseTemp + tempVariation;
  data.elevation = 1000 + random(-50, 50);  // ±50 ft elevation variation
  
  // GPS coordinates (simulate slow drift like a parked truck)
  static float baseLat = 40.7128;
  static float baseLng = -74.0060;
  data.gpsLat = baseLat + (random(-100, 100) / 100000.0);  // Small GPS drift
  data.gpsLng = baseLng + (random(-100, 100) / 100000.0);
  
  // Ensure realistic ranges
  if (data.mainAirPressure < 0) data.mainAirPressure = 0;
  if (data.mainAirPressure > 150) data.mainAirPressure = 150;
  if (data.temperature < -40) data.temperature = -40;
  if (data.temperature > 150) data.temperature = 150;
  
  // Calculate weight using regression coefficients
  data.weight = coeffs.intercept + 
                (data.mainAirPressure * coeffs.airPressureCoeff) +
                (data.atmosphericPressure * coeffs.ambientPressureCoeff) +
                (data.temperature * coeffs.airTempCoeff);
  
  // Ensure non-negative weight
  if (data.weight < 0) data.weight = 0;
  
  data.timestamp = getCurrentTimestamp();
  
  // Debug output
  Serial.println("Dummy Sensor Reading:");
  Serial.println("  Pressure: " + String(data.mainAirPressure) + " psi");
  Serial.println("  Temperature: " + String(data.temperature) + "F");
  Serial.println("  Calculated Weight: " + String(data.weight) + " lbs");
  
  return data;
}

void sendDataToServer(SensorData data) {
  if (!isConnectedToWiFi) return;
  
  http.begin(String(SERVER_URL) + "/api/microdata");
  http.addHeader("Content-Type", "application/json");
  
  DynamicJsonDocument doc(1024);
  doc["mac_address"] = deviceMAC;
  doc["main_air_pressure"] = data.mainAirPressure;
  doc["atmospheric_pressure"] = data.atmosphericPressure;
  doc["temperature"] = data.temperature;
  doc["elevation"] = data.elevation;
  doc["gps_lat"] = data.gpsLat;
  doc["gps_lng"] = data.gpsLng;
  doc["timestamp"] = data.timestamp;
  doc["weight"] = data.weight;
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  int httpResponseCode = http.POST(jsonString);
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.println("Data sent to server: " + String(httpResponseCode));
    
    // Check for updated regression coefficients in response
    checkForUpdatedCoeffs(response);
  } else {
    Serial.println("Failed to send data: " + String(httpResponseCode));
  }
  
  http.end();
}

void registerDevice() {
  if (!isConnectedToWiFi) return;
  
  http.begin(String(SERVER_URL) + "/api/esp32/register");
  http.addHeader("Content-Type", "application/json");
  
  DynamicJsonDocument doc(512);
  doc["mac_address"] = deviceMAC;
  doc["device_type"] = "FeatherS3";
  doc["firmware_version"] = "1.0.0";
  doc["serial_number"] = "FS3-" + deviceMAC.substring(12);
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  int httpResponseCode = http.POST(jsonString);
  
  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.println("Device registered: " + String(httpResponseCode));
    
    // Parse and save regression coefficients
    parseRegressionCoeffs(response);
  }
  
  http.end();
}

void parseRegressionCoeffs(String response) {
  DynamicJsonDocument doc(1024);
  deserializeJson(doc, response);
  
  if (doc.containsKey("regression_coefficients")) {
    JsonObject regCoeffs = doc["regression_coefficients"];
    
    coeffs.intercept = regCoeffs["intercept"] | 0.0;
    coeffs.airPressureCoeff = regCoeffs["air_pressure_coeff"] | 0.0;
    coeffs.ambientPressureCoeff = regCoeffs["ambient_pressure_coeff"] | 0.0;
    coeffs.airTempCoeff = regCoeffs["air_temp_coeff"] | 0.0;
    
    saveRegressionCoeffs();
    Serial.println("Updated regression coefficients");
  }
}

void saveRegressionCoeffs() {
  preferences.putFloat("intercept", coeffs.intercept);
  preferences.putFloat("air_coeff", coeffs.airPressureCoeff);
  preferences.putFloat("ambient_coeff", coeffs.ambientPressureCoeff);
  preferences.putFloat("temp_coeff", coeffs.airTempCoeff);
}

void loadRegressionCoeffs() {
  coeffs.intercept = preferences.getFloat("intercept", 0.0);
  coeffs.airPressureCoeff = preferences.getFloat("air_coeff", 0.0);
  coeffs.ambientPressureCoeff = preferences.getFloat("ambient_coeff", 0.0);
  coeffs.airTempCoeff = preferences.getFloat("temp_coeff", 0.0);
  
  Serial.println("Loaded regression coefficients:");
  Serial.println("Intercept: " + String(coeffs.intercept));
  Serial.println("Air Pressure: " + String(coeffs.airPressureCoeff));
}

// Web server handlers
void handleStatus() {
    DynamicJsonDocument doc(512);
    doc["status"] = "online";
    doc["mac_address"] = deviceMAC;
    doc["wifi_connected"] = isConnectedToWiFi;
    doc["ap_mode"] = isAPMode;
    doc["ble_enabled"] = bleEnabled;
    doc["ble_connected"] = deviceConnected;
    doc["ble_device_name"] = bleDeviceName;
    doc["free_heap"] = ESP.getFreeHeap();
    doc["uptime"] = millis();
    
    String response;
    serializeJson(doc, response);
    server.send(200, "application/json", response);
}

void handleSensors() {
  SensorData data = readSensors();
  
  DynamicJsonDocument doc(512);
  doc["main_air_pressure"] = data.mainAirPressure;
  doc["atmospheric_pressure"] = data.atmosphericPressure;
  doc["temperature"] = data.temperature;
  doc["weight"] = data.weight;
  doc["timestamp"] = data.timestamp;
  
  String response;
  serializeJson(doc, response);
  server.send(200, "application/json", response);
}

void handleWiFiConfig() {
  DynamicJsonDocument doc(512);
  deserializeJson(doc, server.arg("plain"));
  
  String ssid = doc["ssid"];
  String password = doc["password"];
  
  preferences.putString("wifi_ssid", ssid);
  preferences.putString("wifi_password", password);
  
  server.send(200, "application/json", "{\"message\":\"WiFi saved. Restarting...\"}");
  
  delay(1000);
  ESP.restart();
}

void handleRestart() {
  server.send(200, "application/json", "{\"message\":\"Restarting...\"}");
  delay(1000);
  ESP.restart();
}

void handleDataDownload() {
  // Return stored SPIFFS data as JSON
  File file = SPIFFS.open("/sensor_data.json", "r");
  if (file) {
    server.streamFile(file, "application/json");
    file.close();
  } else {
    server.send(404, "text/plain", "No data found");
  }
}

void storeDataLocally(SensorData data) {
  // Store in SPIFFS for offline capability
  File file = SPIFFS.open("/sensor_data.json", "a");
  if (file) {
    DynamicJsonDocument doc(512);
    doc["timestamp"] = data.timestamp;
    doc["weight"] = data.weight;
    doc["pressure"] = data.mainAirPressure;
    doc["temperature"] = data.temperature;
    
    String jsonLine;
    serializeJson(doc, jsonLine);
    file.println(jsonLine);
    file.close();
  }
}

void broadcastSensorData(SensorData data) {
  DynamicJsonDocument doc(512);
  doc["type"] = "sensor_data";
  doc["weight"] = data.weight;
  doc["main_air_pressure"] = data.mainAirPressure;
  doc["temperature"] = data.temperature;
  doc["timestamp"] = data.timestamp;
  
  String message;
  serializeJson(doc, message);
  webSocket.broadcastTXT(message);
}

void webSocketEvent(uint8_t num, WStype_t type, uint8_t * payload, size_t length) {
  switch(type) {
    case WStype_CONNECTED:
      Serial.println("WebSocket client connected: " + String(num));
      break;
    case WStype_DISCONNECTED:
      Serial.println("WebSocket client disconnected: " + String(num));
      break;
    case WStype_TEXT:
      Serial.println("WebSocket message: " + String((char*)payload));
      break;
  }
}

void sendHeartbeat() {
  // Simple heartbeat to server
  http.begin(String(SERVER_URL) + "/api/esp32/status");
  int httpResponseCode = http.GET();
  http.end();
}

void checkForUpdatedCoeffs(String response) {
  // Check if server response contains updated coefficients
  if (response.indexOf("calculated_weight") > 0) {
    // Could parse for updated coefficients here
  }
}

String getCurrentTimestamp() {
  // For now, return a simple timestamp
  // In production, you'd want to sync with NTP or use RTC
  return String(millis());
}

// BLE Callback Classes
class MyServerCallbacks: public BLEServerCallbacks {
    void onConnect(BLEServer* pServer) {
        deviceConnected = true;
        Serial.println("BLE Client Connected!");
    }

    void onDisconnect(BLEServer* pServer) {
        deviceConnected = false;
        Serial.println("BLE Client Disconnected!");
        // Restart advertising
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
            Serial.println(rxValue.c_str());
            
            // Parse JSON coefficients from PWA
            DynamicJsonDocument doc(512);
            DeserializationError error = deserializeJson(doc, rxValue.c_str());
            
            if (!error) {
                coeffs.intercept = doc["intercept"] | 0.0;
                coeffs.airPressureCoeff = doc["air_pressure_coeff"] | 0.0;
                coeffs.ambientPressureCoeff = doc["ambient_pressure_coeff"] | 0.0;
                coeffs.airTempCoeff = doc["air_temp_coeff"] | 0.0;
                
                saveRegressionCoeffs();
                
                Serial.println("Updated coefficients via BLE:");
                Serial.println("  Intercept: " + String(coeffs.intercept));
                Serial.println("  Air Pressure: " + String(coeffs.airPressureCoeff));
                Serial.println("  Ambient Pressure: " + String(coeffs.ambientPressureCoeff));
                Serial.println("  Temperature: " + String(coeffs.airTempCoeff));
            } else {
                Serial.println("Failed to parse coefficients JSON");
            }
        }
    }
};

// BLE Functions (add these new functions)
void initBLE() {
    // Create unique device name with MAC address
    bleDeviceName = DEVICE_NAME_PREFIX + deviceMAC;
    
    Serial.println("Initializing BLE...");
    Serial.println("Device name: " + bleDeviceName);
    
    BLEDevice::init(bleDeviceName.c_str());
    
    // Create BLE Server
    pServer = BLEDevice::createServer();
    pServer->setCallbacks(new MyServerCallbacks());
    
    // Create BLE Service
    BLEService *pService = pServer->createService(SERVICE_UUID);
    
    // Create Sensor Data Characteristic (Read + Notify)
    pSensorCharacteristic = pService->createCharacteristic(
        SENSOR_CHAR_UUID,
        BLECharacteristic::PROPERTY_READ |
        BLECharacteristic::PROPERTY_NOTIFY
    );
    pSensorCharacteristic->addDescriptor(new BLE2902());
    
    // Create Coefficients Characteristic (Write)
    pCoeffsCharacteristic = pService->createCharacteristic(
        COEFFS_CHAR_UUID,
        BLECharacteristic::PROPERTY_WRITE
    );
    pCoeffsCharacteristic->setCallbacks(new CoeffsCallbacks());
    
    // Start the service
    pService->start();
    
    // Start advertising
    BLEAdvertising *pAdvertising = BLEDevice::getAdvertising();
    pAdvertising->addServiceUUID(SERVICE_UUID);
    pAdvertising->setScanResponse(false);
    pAdvertising->setMinPreferred(0x0);
    
    pServer->startAdvertising();
    Serial.println("BLE advertising started");
    
    bleEnabled = true;
}

void sendSensorDataViaBLE(SensorData data) {
    if (!bleEnabled || !deviceConnected) return;
    
    // Create JSON payload for PWA
    DynamicJsonDocument doc(1024);
    doc["mac_address"] = deviceMAC;
    doc["main_air_pressure"] = data.mainAirPressure;
    doc["atmospheric_pressure"] = data.atmosphericPressure;
    doc["temperature"] = data.temperature;
    doc["elevation"] = data.elevation;
    doc["gps_lat"] = data.gpsLat;
    doc["gps_lng"] = data.gpsLng;
    doc["weight"] = data.weight;
    doc["timestamp"] = data.timestamp;
    doc["device_type"] = "FeatherS3";
    
    String jsonString;
    serializeJson(doc, jsonString);
    
    // Send via BLE
    pSensorCharacteristic->setValue(jsonString.c_str());
    pSensorCharacteristic->notify();
    
    Serial.println("Sent via BLE: " + jsonString);
}

void initMeshNetworkingFixed() {
  Serial.println("Initializing Fixed Mesh Networking...");
  
  // Load saved truck configuration
  loadTruckConfiguration();
  
  // CRITICAL: If WiFi is connected, we need to handle this carefully
  if (isConnectedToWiFi) {
    Serial.println("WiFi is connected - configuring for WiFi+ESPNow coexistence");
    
    // Get the current WiFi channel
    int wifiChannel = WiFi.channel();
    Serial.printf("Current WiFi channel: %d\n", wifiChannel);
    
    // Set ESPNow to use the same channel as WiFi
    esp_wifi_set_channel(wifiChannel, WIFI_SECOND_CHAN_NONE);
    Serial.printf("Set ESPNow channel to: %d\n", wifiChannel);
  } else {
    Serial.println("No WiFi - using dedicated ESPNow channel");
    // Set WiFi mode for ESPNow only
    WiFi.mode(WIFI_AP_STA);
    esp_wifi_set_channel(MESH_CHANNEL, WIFI_SECOND_CHAN_NONE);
  }
  
  // Initialize ESPNow
  if (esp_now_init() != ESP_OK) {
    Serial.println("Error initializing ESP-NOW");
    return;
  }
  
  // Register callbacks
  esp_now_register_send_cb(onESPNowDataSent);
  esp_now_register_recv_cb(onESPNowDataReceived);
  
  // Setup broadcast peer with current channel
  setupBroadcastPeerFixed();
  
  // Start in discovery mode
  currentRole = ROLE_DISCOVERY;
  meshInitialized = true;
  
  Serial.println("Fixed mesh networking initialized");
  Serial.println("Device Role: DISCOVERY");
}

void setupBroadcastPeerFixed() {
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  // Remove existing peer if it exists (for reconfiguration)
  if (esp_now_is_peer_exist(broadcastAddress)) {
    esp_now_del_peer(broadcastAddress);
    Serial.println("Removed existing broadcast peer");
  }
  
  esp_now_peer_info_t peerInfo;
  memset(&peerInfo, 0, sizeof(peerInfo));
  memcpy(peerInfo.peer_addr, broadcastAddress, 6);
  
  // Use current WiFi channel if connected, otherwise use mesh channel
  peerInfo.channel = isConnectedToWiFi ? WiFi.channel() : MESH_CHANNEL;
  peerInfo.encrypt = false;
  peerInfo.ifidx = WIFI_IF_STA;
  
  esp_err_t result = esp_now_add_peer(&peerInfo);
  if (result == ESP_OK) {
    Serial.printf("Broadcast peer added on channel %d\n", peerInfo.channel);
    meshPeerSetup = true;
  } else {
    Serial.printf("Failed to add broadcast peer: %d\n", result);
    
    // Try alternative setup
    delay(100);
    peerInfo.ifidx = WIFI_IF_AP;
    result = esp_now_add_peer(&peerInfo);
    if (result == ESP_OK) {
      Serial.println("Broadcast peer added via AP interface");
      meshPeerSetup = true;
    } else {
      Serial.printf("Failed both interfaces: %d\n", result);
    }
  }
}

void broadcastMeshMessageFixed(MeshMessage* msg) {
  if (!meshPeerSetup) {
    Serial.println("Mesh not setup, cannot broadcast");
    return;
  }
  
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  // Copy our MAC address into the message
  uint8_t mac[6];
  esp_read_mac(mac, ESP_MAC_WIFI_STA);
  memcpy(msg->senderMAC, mac, 6);
  
  // Try sending with retry logic
  esp_err_t result = esp_now_send(broadcastAddress, (uint8_t*)msg, sizeof(MeshMessage));
  
  if (result == ESP_OK) {
    Serial.printf("Broadcast sent successfully (type: %d)\n", msg->messageType);
  } else {
    Serial.printf("Broadcast failed: %d", result);
    
    // Decode common errors
    switch (result) {
      case ESP_ERR_ESPNOW_NO_MEM:
        Serial.println(" (No memory - WiFi interference)");
        // Try to free some memory and retry
        delay(10);
        result = esp_now_send(broadcastAddress, (uint8_t*)msg, sizeof(MeshMessage));
        if (result == ESP_OK) {
          Serial.println("Retry successful!");
        }
        break;
      case ESP_ERR_ESPNOW_NOT_INIT:
        Serial.println(" (ESPNow not initialized)");
        break;
      case ESP_ERR_ESPNOW_ARG:
        Serial.println(" (Invalid argument)");
        break;
      case ESP_ERR_ESPNOW_INTERNAL:
        Serial.println(" (Internal error)");
        break;
      default:
        Serial.printf(" (Unknown error: %d)\n", result);
    }
  }
}

// FIXED: Handle role assignment with WiFi priority
void handleDiscoveryResponseFixed(const uint8_t *mac_addr, MeshMessage* msg) {
  Serial.printf("Discovery response from: %s\n", msg->deviceName);
  
  addOrUpdateDevice(mac_addr, msg);
  
  // Enhanced role assignment logic
  bool shouldBecomeMaster = false;
  
  // Priority 1: Device with WiFi connection becomes master
  if (isConnectedToWiFi && currentRole != ROLE_MASTER) {
    shouldBecomeMaster = true;
    Serial.println("Becoming master - WiFi connected (highest priority)");
  }
  // Priority 2: Device with BLE connection becomes master (if no WiFi device)
  else if (deviceConnected && currentRole != ROLE_MASTER && !hasWiFiMaster()) {
    shouldBecomeMaster = true;
    Serial.println("Becoming master - BLE connected");
  }
  // Priority 3: First device to see others becomes master (if no WiFi/BLE device)
  else if (knownDeviceCount > 0 && currentRole == ROLE_DISCOVERY && !hasAnyMaster()) {
    shouldBecomeMaster = true;
    Serial.println("Becoming master - first to discover others");
  }
  
  if (shouldBecomeMaster) {
    becomeMaster();
  } else if (currentRole == ROLE_DISCOVERY) {
    // If we see a master, become a slave
    for (int i = 0; i < knownDeviceCount; i++) {
      if (knownDevices[i].role == ROLE_MASTER) {
        becomeSlave(knownDevices[i].macAddress);
        break;
      }
    }
  }
}

// Helper functions
bool hasWiFiMaster() {
  // Check if any known device is a WiFi-connected master
  for (int i = 0; i < knownDeviceCount; i++) {
    if (knownDevices[i].role == ROLE_MASTER) {
      // Assume master with server connection has WiFi
      return true;
    }
  }
  return false;
}

bool hasAnyMaster() {
  for (int i = 0; i < knownDeviceCount; i++) {
    if (knownDevices[i].role == ROLE_MASTER) {
      return true;
    }
  }
  return false;
}
// ESPNow Mesh Implementation
void initMeshNetworking() {
  Serial.println("Initializing Mesh Networking...");
  
  // Load saved truck configuration
  loadTruckConfiguration();
  
  // Set WiFi mode to STA+AP for ESPNow
  WiFi.mode(WIFI_AP_STA);
  
  // Initialize ESPNow
  if (esp_now_init() != ESP_OK) {
    Serial.println("Error initializing ESP-NOW");
    return;
  }
  
  // Set WiFi channel
  esp_wifi_set_channel(MESH_CHANNEL, WIFI_SECOND_CHAN_NONE);
  
  // Register callbacks
  esp_now_register_send_cb(onESPNowDataSent);
  esp_now_register_recv_cb(onESPNowDataReceived);

  setupBroadcastPeer();
  
  // Start in discovery mode
  currentRole = ROLE_DISCOVERY;
  meshInitialized = true;
  registerMeshStatus();
  
  Serial.println("Mesh networking initialized");
  Serial.println("Device Role: DISCOVERY");

  handleDiscoveryBroadcast();
}

void onESPNowDataReceived(const uint8_t *mac_addr, const uint8_t *data, int data_len) {
  if (data_len != sizeof(MeshMessage)) return;
  
  MeshMessage* msg = (MeshMessage*)data;
  
  Serial.println("ESPNow message received from: " + String(msg->deviceName));
  
  switch (msg->messageType) {
    case MSG_DISCOVERY_BROADCAST:
      handleDiscoveryResponse(mac_addr, msg);
      break;
    case MSG_DISCOVERY_RESPONSE:
      handleDiscoveryResponse(mac_addr, msg);
      break;
    case MSG_PAIRING_REQUEST:
      handlePairingRequest(mac_addr, msg);
      break;
    case MSG_SENSOR_DATA:
      handleSensorData(mac_addr, msg);
      break;
    case MSG_HEARTBEAT:
      addOrUpdateDevice(mac_addr, msg);
      break;
  }
}

void onESPNowDataSent(const uint8_t *mac_addr, esp_now_send_status_t status) {
  Serial.println("ESPNow Send Status: " + String(status == ESP_NOW_SEND_SUCCESS ? "Success" : "Failed"));
}

void handleMeshLoop() {
  if (!meshInitialized) return;
  
  unsigned long now = millis();
  
  // Discovery mode: broadcast every 10 seconds instead of 30
  if (currentRole == ROLE_DISCOVERY && now - lastDiscovery > 10000) {
    handleDiscoveryBroadcast();
    lastDiscovery = now;
  }
  
  // Master mode: aggregate data and send via BLE
  if (currentRole == ROLE_MASTER) {
    // Send aggregated data to phone every 2 seconds
    static unsigned long lastBLEUpdate = 0;
    if (now - lastBLEUpdate > 2000) {
      sendAggregatedDataViaBLE();
      lastBLEUpdate = now;
    }
  }
  
  // Slave mode: send data to master every 5 seconds
  if (currentRole == ROLE_SLAVE && now - lastHeartbeat > 5000) {
    sendSensorDataToMaster();
    lastHeartbeat = now;
  }
  
  // Remove inactive devices every 30 seconds
  static unsigned long lastCleanup = 0;
  if (now - lastCleanup > 30000) {
    removeInactiveDevices();
    lastCleanup = now;
  }
}

void handleDiscoveryBroadcast() {
  Serial.println("Broadcasting discovery...");
  
  MeshMessage msg;
  msg.messageType = MSG_DISCOVERY_BROADCAST;
  memcpy(msg.senderMAC, WiFi.macAddress().c_str(), 6);
  strcpy(msg.deviceName, bleDeviceName.c_str());
  strcpy(msg.truckConfig, truckConfiguration.c_str());
  msg.timestamp = millis();
  
  broadcastMeshMessage(&msg);
}

void handleDiscoveryResponse(const uint8_t *mac_addr, MeshMessage* msg) {
  Serial.printf("Discovery response from: %s\n", msg->deviceName);
  
  addOrUpdateDevice(mac_addr, msg);
  
  // Auto role assignment logic
  bool shouldBecomeMaster = false;
  
  // Priority 1: Device with BLE connection becomes master
  if (deviceConnected && currentRole != ROLE_MASTER) {
    shouldBecomeMaster = true;
    Serial.println("Becoming master - BLE connected");
  }
  // Priority 2: First device to see others becomes master
  else if (knownDeviceCount > 0 && currentRole == ROLE_DISCOVERY) {
    shouldBecomeMaster = true;
    Serial.println("Becoming master - first to discover others");
  }
  
  if (shouldBecomeMaster) {
    becomeMaster();
  } else if (currentRole == ROLE_DISCOVERY) {
    // If we see a master, become a slave
    for (int i = 0; i < knownDeviceCount; i++) {
      if (knownDevices[i].role == ROLE_MASTER) {
        becomeSlave(knownDevices[i].macAddress);
        break;
      }
    }
  }
}
void handlePairingRequest(const uint8_t *mac_addr, MeshMessage* msg) {
  if (currentRole == ROLE_MASTER) {
    // Accept pairing request
    MeshMessage response;
    response.messageType = MSG_PAIRING_RESPONSE;
    memcpy(response.senderMAC, WiFi.macAddress().c_str(), 6);
    strcpy(response.deviceName, bleDeviceName.c_str());
    strcpy(response.truckConfig, truckConfiguration.c_str());
    response.timestamp = millis();
    
    sendMeshMessage(mac_addr, &response);
    addOrUpdateDevice(mac_addr, msg);
    
    Serial.println("Accepted pairing from: " + String(msg->deviceName));
  }
}

void handleSensorData(const uint8_t *mac_addr, MeshMessage* msg) {
  if (currentRole == ROLE_MASTER) {
    // Store slave sensor data
    addOrUpdateDevice(mac_addr, msg);
    
    // Forward to dashboard via BLE/WiFi
    if (deviceConnected) {
      // Create combined sensor data for dashboard
      SensorData combinedData;
      combinedData.mainAirPressure = msg->sensorData[0];
      combinedData.temperature = msg->sensorData[1];
      combinedData.weight = msg->sensorData[2];
      combinedData.gpsLat = msg->sensorData[3];
      combinedData.gpsLng = msg->sensorData[4];
      combinedData.elevation = msg->sensorData[5];
      combinedData.atmosphericPressure = msg->sensorData[6];
      combinedData.timestamp = String(msg->timestamp);
      
      sendSensorDataViaBLE(combinedData);
    }
    
    Serial.println("Received sensor data from slave: " + String(msg->deviceName));
  }
}

void becomeMaster() {
  if (currentRole == ROLE_MASTER) return;
  
  Serial.println("Becoming MASTER device");
  currentRole = ROLE_MASTER;

  // Register new role with server
  registerMeshStatus();
  
  // Send role assignment to known devices
  MeshMessage msg;
  msg.messageType = MSG_ROLE_ASSIGNMENT;
  memcpy(msg.senderMAC, WiFi.macAddress().c_str(), 6);
  strcpy(msg.deviceName, bleDeviceName.c_str());
  strcpy(msg.truckConfig, truckConfiguration.c_str());
  msg.timestamp = millis();
  
  broadcastMeshMessage(&msg);
}

void becomeSlave(const uint8_t *masterMAC) {
  if (currentRole == ROLE_SLAVE) return;
  
  Serial.println("Becoming SLAVE device");
  currentRole = ROLE_SLAVE;

  // Register new role with server
  registerMeshStatus();
  
  // Add master to peer list
  esp_now_peer_info_t peerInfo;
  memcpy(peerInfo.peer_addr, masterMAC, 6);
  peerInfo.channel = MESH_CHANNEL;
  peerInfo.encrypt = false;
  
  if (esp_now_add_peer(&peerInfo) != ESP_OK) {
    Serial.println("Failed to add master peer");
  }
}

void aggregateSlaveData() {
  // Calculate total weight from all devices
  float totalWeight = 0;
  int activeDevices = 0;
  
  // Add our own data
  SensorData ourData = readSensors();
  totalWeight += ourData.weight;
  activeDevices++;
  
  // Add slave data
  for (int i = 0; i < knownDeviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 30000) {
      totalWeight += knownDevices[i].lastSensorData[2]; // weight is index 2
      activeDevices++;
    }
  }
  
  // Update our weight calculation with total
  // This would be sent to the dashboard as the combined truck weight
  Serial.println("Total truck weight: " + String(totalWeight) + " lbs from " + String(activeDevices) + " devices");
}

void saveTruckConfiguration() {
  // Save current truck configuration to preferences
  preferences.putString("truck_config", truckConfiguration);
  preferences.putUInt("device_role", currentRole);
  
  // Save known devices
  for (int i = 0; i < knownDeviceCount; i++) {
    String key = "device_" + String(i);
    String deviceData = String((char*)knownDevices[i].macAddress) + "," + 
                       String(knownDevices[i].deviceName) + "," + 
                       String(knownDevices[i].role);
    preferences.putString(key.c_str(), deviceData);
  }
  preferences.putUInt("device_count", knownDeviceCount);
}

void loadTruckConfiguration() {
  truckConfiguration = preferences.getString("truck_config", "");
  currentRole = (DeviceRole)preferences.getUInt("device_role", ROLE_UNDEFINED);
  
  // Load known devices
  knownDeviceCount = preferences.getUInt("device_count", 0);
  for (int i = 0; i < knownDeviceCount && i < MAX_SLAVES; i++) {
    String key = "device_" + String(i);
    String deviceData = preferences.getString(key.c_str(), "");
    // Parse and restore device info (simplified)
    if (deviceData.length() > 0) {
      knownDevices[i].isActive = false; // Will be activated when device is seen
    }
  }
}

// Utility functions
DeviceInfo* findDevice(const uint8_t *mac_addr) {
  for (int i = 0; i < knownDeviceCount; i++) {
    if (memcmp(knownDevices[i].macAddress, mac_addr, 6) == 0) {
      return &knownDevices[i];
    }
  }
  return nullptr;
}

void addOrUpdateDevice(const uint8_t *mac_addr, MeshMessage* msg) {
  DeviceInfo* device = findDevice(mac_addr);
  
  if (device == nullptr && knownDeviceCount < MAX_SLAVES) {
    // Add new device
    device = &knownDevices[knownDeviceCount++];
    memcpy(device->macAddress, mac_addr, 6);
  }
  
  if (device != nullptr) {
    strcpy(device->deviceName, msg->deviceName);
    device->lastSeen = millis();
    device->isActive = true;
    
    // Copy sensor data if present
    if (msg->messageType == MSG_SENSOR_DATA) {
      memcpy(device->lastSensorData, msg->sensorData, sizeof(msg->sensorData));
    }
  }
}

void removeInactiveDevices() {
  unsigned long timeout = 60000; // 1 minute timeout
  
  for (int i = 0; i < knownDeviceCount; i++) {
    if (millis() - knownDevices[i].lastSeen > timeout) {
      knownDevices[i].isActive = false;
    }
  }
}

void sendMeshMessage(const uint8_t *mac_addr, MeshMessage* msg) {
  esp_err_t result = esp_now_send(mac_addr, (uint8_t*)msg, sizeof(MeshMessage));
  if (result != ESP_OK) {
    Serial.println("Error sending mesh message");
  }
}

void broadcastMeshMessage(MeshMessage* msg) {
  if (!meshPeerSetup) {
    Serial.println("Mesh not setup, cannot broadcast");
    return;
  }
  
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  // Copy our MAC address into the message
  uint8_t mac[6];
  esp_read_mac(mac, ESP_MAC_WIFI_STA);
  memcpy(msg->senderMAC, mac, 6);
  
  esp_err_t result = esp_now_send(broadcastAddress, (uint8_t*)msg, sizeof(MeshMessage));
  
  if (result == ESP_OK) {
    Serial.printf("Broadcast sent successfully (type: %d)\n", msg->messageType);
  } else {
    Serial.printf("Broadcast failed: %d\n", result);
  }
}

void registerMeshStatus() {
  if (!isConnectedToWiFi) return;
  
  Serial.println("=== MESH REGISTRATION DEBUG ===");
  Serial.println("Device MAC: " + deviceMAC);
  Serial.println("Calling: " + String(SERVER_URL) + "/api/esp32/mesh/register");

  http.begin(String(SERVER_URL) + "/api/esp32/mesh/register");
  http.addHeader("Content-Type", "application/json");
  
  DynamicJsonDocument doc(512);
  doc["mac_address"] = deviceMAC;
  doc["role"] = currentRole == ROLE_MASTER ? "master" : 
                currentRole == ROLE_SLAVE ? "slave" : "discovery";
  doc["device_type"] = "FeatherS3";
  doc["signal_strength"] = WiFi.RSSI();
  
  // Add connected slaves if master
  if (currentRole == ROLE_MASTER) {
    JsonArray slaves = doc.createNestedArray("connected_slaves");
    for (int i = 0; i < knownDeviceCount; i++) {
      if (knownDevices[i].isActive) {
        slaves.add(String((char*)knownDevices[i].macAddress));
      }
    }
  }
  
  // Add master MAC if slave
  if (currentRole == ROLE_SLAVE) {
    // Add master MAC here when you know it
  }
  
  String jsonString;
  serializeJson(doc, jsonString);

  Serial.println("Sending JSON: " + jsonString);
  
  int httpResponseCode = http.POST(jsonString);
  String response = http.getString();
  
  if (httpResponseCode > 0) {
    Serial.println("Mesh status registered: " + String(httpResponseCode));
  } else {
    Serial.println("Failed to register mesh status");
  }
  
  Serial.println("Response Code: " + String(httpResponseCode));
  Serial.println("Response Body: " + response);
  
  http.end();
}

void setupBroadcastPeer() {
  uint8_t broadcastAddress[] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  // Check if broadcast peer already exists
  if (!esp_now_is_peer_exist(broadcastAddress)) {
    esp_now_peer_info_t peerInfo;
    memset(&peerInfo, 0, sizeof(peerInfo));
    memcpy(peerInfo.peer_addr, broadcastAddress, 6);
    peerInfo.channel = MESH_CHANNEL;
    peerInfo.encrypt = false;
    peerInfo.ifidx = WIFI_IF_STA;  // Use STA interface for ESPNow
    
    esp_err_t result = esp_now_add_peer(&peerInfo);
    if (result == ESP_OK) {
      Serial.println("Broadcast peer added successfully");
      meshPeerSetup = true;
    } else {
      Serial.printf("Failed to add broadcast peer: %d\n", result);
    }
  } else {
    Serial.println("Broadcast peer already exists");
    meshPeerSetup = true;
  }
}

void sendSensorDataToMaster() {
  if (currentRole != ROLE_SLAVE) return;
  
  Serial.println("Sending sensor data to master...");
  
  SensorData data = readSensors();
  
  MeshMessage msg;
  msg.messageType = MSG_SENSOR_DATA;
  memcpy(msg.senderMAC, WiFi.macAddress().c_str(), 6);
  strcpy(msg.deviceName, bleDeviceName.c_str());
  strcpy(msg.truckConfig, truckConfiguration.c_str());
  
  // Pack sensor data
  msg.sensorData[0] = data.mainAirPressure;
  msg.sensorData[1] = data.temperature;
  msg.sensorData[2] = data.weight;
  msg.sensorData[3] = data.gpsLat;
  msg.sensorData[4] = data.gpsLng;
  msg.sensorData[5] = data.elevation;
  msg.sensorData[6] = data.atmosphericPressure;
  msg.sensorData[7] = millis(); // timestamp
  
  msg.timestamp = millis();
  msg.batteryLevel = 85; // Mock battery level
  msg.isCharging = false;
  
  broadcastMeshMessage(&msg);
}

// NEW: Send aggregated data via BLE (master only)
void sendAggregatedDataViaBLE() {
  if (currentRole != ROLE_MASTER) return;
  
  // Get our own sensor data
  SensorData ourData = readSensors();
  float totalWeight = ourData.weight;
  int deviceCount = 1;
  
  // Add slave data
  for (int i = 0; i < knownDeviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 15000) {
      totalWeight += knownDevices[i].lastSensorData[2]; // weight is index 2
      deviceCount++;
    }
  }
  
  // Create aggregated JSON for PWA
  DynamicJsonDocument doc(2048);
  doc["mac_address"] = deviceMAC;
  doc["role"] = "master";
  doc["device_count"] = deviceCount;
  doc["total_weight"] = totalWeight;
  doc["timestamp"] = millis();
  
  // Our device data
  doc["master_device"]["main_air_pressure"] = ourData.mainAirPressure;
  doc["master_device"]["temperature"] = ourData.temperature;
  doc["master_device"]["weight"] = ourData.weight;
  
  // Slave devices data
  JsonArray slaves = doc.createNestedArray("slave_devices");
  for (int i = 0; i < knownDeviceCount; i++) {
    if (knownDevices[i].isActive && millis() - knownDevices[i].lastSeen < 15000) {
      JsonObject slave = slaves.createNestedObject();
      slave["mac_address"] = String((char*)knownDevices[i].macAddress);
      slave["device_name"] = knownDevices[i].deviceName;
      slave["weight"] = knownDevices[i].lastSensorData[2];
      slave["main_air_pressure"] = knownDevices[i].lastSensorData[0];
      slave["temperature"] = knownDevices[i].lastSensorData[1];
      slave["last_seen"] = millis() - knownDevices[i].lastSeen;
    }
  }
  
  String jsonString;
  serializeJson(doc, jsonString);
  
  // Send via BLE if connected
  if (bleEnabled && deviceConnected) {
    pSensorCharacteristic->setValue(jsonString.c_str());
    pSensorCharacteristic->notify();
    Serial.printf("Sent aggregated data via BLE: %d devices, %.1f lbs total\n", deviceCount, totalWeight);
  }
}
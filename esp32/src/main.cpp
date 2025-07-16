#include <WiFi.h>
#include <WebServer.h>
#include <WebSocketsServer.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPIFFS.h>
#include <Preferences.h>

// Hardware Configuration
#define STATUS_LED_PIN 13

// Network Configuration
const char* SERVER_URL = "https://beaker.ca";
const char* FALLBACK_SSID = "YourHomeWiFi";    // Replace with your WiFi
const char* FALLBACK_PASSWORD = "YourPassword"; // Replace with your password

// Global Objects
WebServer server(80);
WebSocketsServer webSocket(81);
Preferences preferences;
HTTPClient http;

// Device State
String deviceMAC;
String apSSID;
bool isConnectedToWiFi = false;
bool isAPMode = false;
unsigned long lastDataSend = 0;
unsigned long lastHeartbeat = 0;

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
  
  // If no WiFi connection, start AP mode
  if (!isConnectedToWiFi) {
    startAPMode();
  }
  
  // Setup web server routes
  setupWebServer();
  
  // Start WebSocket server
  webSocket.begin();
  webSocket.onEvent(webSocketEvent);
  
  // Register device with server (if connected)
  if (isConnectedToWiFi) {
    registerDevice();
  }
  
  Serial.println("AirScales Ready!");
  digitalWrite(STATUS_LED_PIN, HIGH);
}

void loop() {
  // Handle web server
  server.handleClient();
  webSocket.loop();
  
  // Read sensors and send data every 30 seconds
  if (millis() - lastDataSend > 30000) {
    SensorData data = readSensors();
    
    // Store locally
    storeDataLocally(data);
    
    // Send to server if connected
    if (isConnectedToWiFi) {
      sendDataToServer(data);
    }
    
    // Send via WebSocket to connected clients
    broadcastSensorData(data);
    
    lastDataSend = millis();
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
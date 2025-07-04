// Air Scales - Unified ESP32 Firmware
// Every ESP32 runs identical code - Master selected by phone connection
#include <Arduino.h>
#include <WiFi.h>
#include <ESPAsyncWebServer.h>
#include <SPIFFS.h>
#include "DataStructures.h"
#include "ESPNowHandler.h"
#include "WebSocketHandler.h"

#define WIFI_RESET_PIN 0

AsyncWebServer server(80);
bool systemInitialized = false;

void initializeSystem() {
  Serial.println("");
  Serial.println("🚀 ================================");
  Serial.println("🚀 AIR SCALES ESP32 STARTING UP");
  Serial.println("🚀 ================================");
  Serial.printf("🔧 Device MAC: %s\n", WiFi.macAddress().c_str());
  
  // Mount SPIFFS for web files
  Serial.println("[SYSTEM] 📁 Mounting SPIFFS...");
  if (!SPIFFS.begin(true)) {
    Serial.println("[SYSTEM] ❌ SPIFFS mount failed!");
    return;
  }
  Serial.println("[SYSTEM] ✅ SPIFFS mounted successfully");
  
  // List available files
  File root = SPIFFS.open("/");
  File file = root.openNextFile();
  while(file) {
    Serial.printf("[SYSTEM] 📄 Found file: %s (%d bytes)\n", file.name(), file.size());
    file = root.openNextFile();
  }
  
  // Setup WiFi Access Point FIRST
  String apName = "AirScales-" + WiFi.macAddress();
  Serial.printf("[WiFi] 📶 Creating AP: %s\n", apName.c_str());
  
  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(apName.c_str(), "", 1); // Try channel 1 first
  
  // Wait for AP to be fully established
  Serial.println("[WiFi] ⏳ Waiting for AP to stabilize...");
  delay(2000);
  
  // Check what channel WiFi actually chose
  int finalChannel = WiFi.channel();
  Serial.printf("[WiFi] ✅ AP started successfully on channel: %d\n", finalChannel);
  Serial.printf("[WiFi] 🌐 IP Address: %s\n", WiFi.softAPIP().toString().c_str());
  Serial.printf("[WiFi] 📡 SSID: %s\n", apName.c_str());
  
  // Setup web server routes
  Serial.println("[WebServer] 🌐 Setting up web routes...");
  
  server.on("/", HTTP_GET, [](AsyncWebServerRequest *request){
    request->send(SPIFFS, "/index.html", "text/html");
  });
  
  server.on("/test", HTTP_GET, [](AsyncWebServerRequest *request){
    request->send(200, "text/plain", "Air Scales ESP32 is working!");
  });
  
  server.on("/debug", HTTP_GET, [](AsyncWebServerRequest *request){
    String response = "Device MAC: " + WiFi.macAddress() + "\n";
    response += "AP IP: " + WiFi.softAPIP().toString() + "\n";
    response += "WiFi Channel: " + String(WiFi.channel()) + "\n";
    response += "Connected Clients: " + String(WiFi.softAPgetStationNum()) + "\n";
    response += "Phone Connected: " + String(hasPhoneConnected() ? "YES" : "NO") + "\n";
    response += "ESP-NOW Devices: " + String(getTotalOnlineDevices()) + "\n";
    response += "Total Weight: " + String(getTotalWeight()) + " kg\n";
    response += "Uptime: " + String(millis() / 1000) + " seconds\n";
    request->send(200, "text/plain", response);
  });
  
  server.serveStatic("/", SPIFFS, "/");
  
  // Setup WebSocket for phone communication
  setupWebSocket(server);
  
  // Start web server
  server.begin();
  Serial.println("[WebServer] ✅ Web server started");
  
  // Initialize ESP-NOW AFTER WiFi AP is stable and we know the channel
  Serial.println("[SYSTEM] 🔗 Initializing ESP-NOW with WiFi channel synchronization...");
  initializeESPNow();
  
  // WiFi client connection monitoring
  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
    Serial.printf("[WiFi] 📱 Device connected: MAC %02x:%02x:%02x:%02x:%02x:%02x\n",
                  info.wifi_ap_staconnected.mac[0], info.wifi_ap_staconnected.mac[1],
                  info.wifi_ap_staconnected.mac[2], info.wifi_ap_staconnected.mac[3],
                  info.wifi_ap_staconnected.mac[4], info.wifi_ap_staconnected.mac[5]);
    Serial.printf("[WiFi] 👥 Total connected devices: %d\n", WiFi.softAPgetStationNum());
  }, ARDUINO_EVENT_WIFI_AP_STACONNECTED);

  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
    Serial.printf("[WiFi] 📱 Device disconnected: MAC %02x:%02x:%02x:%02x:%02x:%02x\n",
                  info.wifi_ap_stadisconnected.mac[0], info.wifi_ap_stadisconnected.mac[1],
                  info.wifi_ap_stadisconnected.mac[2], info.wifi_ap_stadisconnected.mac[3],
                  info.wifi_ap_stadisconnected.mac[4], info.wifi_ap_stadisconnected.mac[5]);
    Serial.printf("[WiFi] 👥 Total connected devices: %d\n", WiFi.softAPgetStationNum());
  }, ARDUINO_EVENT_WIFI_AP_STADISCONNECTED);
  
  systemInitialized = true;
  Serial.println("");
  Serial.println("✅ ================================");
  Serial.println("✅ SYSTEM INITIALIZATION COMPLETE");
  Serial.println("✅ ================================");
  Serial.printf("📡 WiFi Channel: %d\n", WiFi.channel());
  Serial.println("🔍 Listening for other Air Scales devices...");
  Serial.println("📱 Ready for phone connection!");
  Serial.println("");
}

void setup() {
  Serial.begin(115200);
  pinMode(WIFI_RESET_PIN, INPUT_PULLUP);
  
  delay(1000); // Give serial time to connect
  initializeSystem();
}

void loop() {
  static unsigned long lastESPNowBroadcast = 0;
  static unsigned long lastWebSocketBroadcast = 0;
  static unsigned long lastStatusReport = 0;
  
  // Broadcast own sensor data via ESP-NOW every 3 seconds
  if (millis() - lastESPNowBroadcast > 3000) {
    lastESPNowBroadcast = millis();
    broadcastOwnSensorData();
  }
  
  // If phone is connected, aggregate and send data every 2 seconds
  if (hasPhoneConnected()) {
    if (millis() - lastWebSocketBroadcast > 2000) {
      lastWebSocketBroadcast = millis();
      broadcastAggregatedData();
    }
  }
  
  // Status report every 30 seconds
  if (millis() - lastStatusReport > 30000) {
    lastStatusReport = millis();
    
    Serial.println("");
    Serial.println("📊 ===== STATUS REPORT =====");
    Serial.printf("⏱️  Uptime: %lu seconds\n", millis() / 1000);
    Serial.printf("📡 WiFi Channel: %d\n", WiFi.channel());
    Serial.printf("📶 WiFi Clients: %d\n", WiFi.softAPgetStationNum());
    Serial.printf("📱 Phone Connected: %s\n", hasPhoneConnected() ? "YES" : "NO");
    Serial.printf("🔗 ESP-NOW Devices: %d\n", getTotalOnlineDevices());
    Serial.printf("⚖️  Total Weight: %.1f kg\n", getTotalWeight());
    Serial.printf("🧠 Free Heap: %d bytes\n", ESP.getFreeHeap());
    
    if (hasPhoneConnected()) {
      Serial.println("👑 Role: MASTER (phone connected)");
    } else {
      Serial.println("🤝 Role: PEER (broadcasting to network)");
    }
    Serial.println("========================");
    Serial.println("");
  }
  
  // Handle WiFi reset button
  static bool buttonPressed = false;
  if (digitalRead(WIFI_RESET_PIN) == LOW && !buttonPressed) {
    buttonPressed = true;
    Serial.println("");
    Serial.println("[BUTTON] 🔄 Reset button pressed - restarting...");
    delay(1000);
    ESP.restart();
  }
  if (digitalRead(WIFI_RESET_PIN) == HIGH) {
    buttonPressed = false;
  }
}
// src/main.cpp
#include <Arduino.h>
#include <WiFi.h>
#include <ESPAsyncWebServer.h>
#include <SPIFFS.h>
#include <WebSocketHandler.h>

#define WIFI_RESET_PIN 0

AsyncWebServer server(80);
bool wifiResetTriggered = false;

void setup() {
  Serial.begin(115200);
  pinMode(WIFI_RESET_PIN, INPUT_PULLUP);

  Serial.println("[STARTUP] Air Scales IoT Device Starting...");

  // Mount SPIFFS
  if (!SPIFFS.begin(true)) {
    Serial.println("[ERROR] SPIFFS mount failed");
    return;
  }
  Serial.println("[SUCCESS] SPIFFS mounted successfully");
  // Add after SPIFFS.begin(true)
  File root = SPIFFS.open("/");
  File file = root.openNextFile();
  while(file){
      Serial.printf("[SPIFFS] Found file: %s\n", file.name());
      file = root.openNextFile();
  }

  // Attempt to connect to WiFi
  // Force AP mode only for now
WiFi.mode(WIFI_AP);
WiFi.softAP("AirScales-" + WiFi.macAddress());
Serial.println("[INFO] Started AP Mode");
Serial.println(WiFi.softAPIP());
  // Add WiFi event handlers after WiFi.softAP() line
  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
      Serial.printf("[WiFi] Station connected: MAC %02x:%02x:%02x:%02x:%02x:%02x\n",
                    info.wifi_ap_staconnected.mac[0], info.wifi_ap_staconnected.mac[1],
                    info.wifi_ap_staconnected.mac[2], info.wifi_ap_staconnected.mac[3],
                    info.wifi_ap_staconnected.mac[4], info.wifi_ap_staconnected.mac[5]);
  }, ARDUINO_EVENT_WIFI_AP_STACONNECTED);

  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
      Serial.printf("[WiFi] Station disconnected: MAC %02x:%02x:%02x:%02x:%02x:%02x\n",
                    info.wifi_ap_stadisconnected.mac[0], info.wifi_ap_stadisconnected.mac[1],
                    info.wifi_ap_stadisconnected.mac[2], info.wifi_ap_stadisconnected.mac[3],
                    info.wifi_ap_stadisconnected.mac[4], info.wifi_ap_stadisconnected.mac[5]);
  }, ARDUINO_EVENT_WIFI_AP_STADISCONNECTED);
  // Serve static files
  // Serve specific files
  server.on("/", HTTP_GET, [](AsyncWebServerRequest *request){
      request->send(SPIFFS, "/index.html", "text/html");
  });

  server.on("/wifi.html", HTTP_GET, [](AsyncWebServerRequest *request){
      request->send(SPIFFS, "/wifi.html", "text/html");
  });

  // Serve static files for anything else
  server.serveStatic("/", SPIFFS, "/");

  // WebSocket
  setupWebSocket(server);

  // Add this after setupWebSocket(server); in your setup()
  server.on("/test", HTTP_GET, [](AsyncWebServerRequest *request){
      request->send(200, "text/plain", "ESP32 Server is working!");
  });

  server.on("/debug", HTTP_GET, [](AsyncWebServerRequest *request){
      String response = "Mode: " + String(WiFi.getMode()) + "\n";
      response += "Station IP: " + WiFi.localIP().toString() + "\n";
      response += "AP IP: " + WiFi.softAPIP().toString() + "\n";
      response += "Clients: " + String(WiFi.softAPgetStationNum()) + "\n";
      response += "WebSocket clients: " + String(ws.count()) + "\n";
      request->send(200, "text/plain", response);
  });

  server.begin();
  Serial.println("[SUCCESS] Web server started");
}

void loop() {
  static unsigned long lastStatus = 0;
  if (millis() - lastStatus > 30000) {
    lastStatus = millis();
    if (WiFi.getMode() == WIFI_AP) {
      Serial.printf("[STATUS] AP Mode - Connected clients: %d\n", WiFi.softAPgetStationNum());
    }
  }

  if (digitalRead(WIFI_RESET_PIN) == LOW && !wifiResetTriggered) {
    wifiResetTriggered = true;
    Serial.println("[BUTTON] BOOT pressed — waiting for release...");
  }

  if (wifiResetTriggered && digitalRead(WIFI_RESET_PIN) == HIGH) {
    Serial.println("[BUTTON] BOOT released — clearing Wi-Fi and rebooting...");
    WiFi.disconnect(true, true);
    delay(1000);
    ESP.restart();
  }

  static unsigned long lastBroadcast = 0;
  if (millis() - lastBroadcast > 10000) {
    lastBroadcast = millis();
    broadcastSensorData();
  }
}

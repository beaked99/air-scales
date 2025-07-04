#include "WebSocketHandler.h"
#include <ArduinoJson.h>

AsyncWebSocket ws("/ws");

void setupWebSocket(AsyncWebServer& server) {
  ws.onEvent([](AsyncWebSocket *server, AsyncWebSocketClient *client, AwsEventType type,
                void *arg, uint8_t *data, size_t len) {
    if (type == WS_EVT_CONNECT) {
      Serial.printf("[WebSocket] Client #%u connected\n", client->id());
    } else if (type == WS_EVT_DISCONNECT) {
      Serial.printf("[WebSocket] Client #%u disconnected\n", client->id());
    }
  });

  server.addHandler(&ws);
}

void broadcastSensorData() {
  StaticJsonDocument<256> doc;

  doc["mac_address"] = WiFi.macAddress();
  doc["main_air_pressure"] = random(1000, 1200) / 10.0;
  doc["atmospheric_pressure"] = random(950, 1050) / 10.0;
  doc["temperature"] = random(180, 300) / 10.0;
  doc["elevation"] = random(0, 500);
  doc["gps_lat"] = 49.2827 + random(-100, 100) / 10000.0;
  doc["gps_lng"] = -123.1207 + random(-100, 100) / 10000.0;
  doc["weight"] = random(0, 2000) / 10.0;

  String json;
  serializeJson(doc, json);
  ws.textAll(json);

  Serial.printf("[WebSocket] Broadcasting: %s\n", json.c_str());
}

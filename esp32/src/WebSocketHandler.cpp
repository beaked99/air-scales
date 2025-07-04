#include "WebSocketHandler.h"
#include "ESPNowHandler.h"

AsyncWebSocket ws("/ws");

void setupWebSocket(AsyncWebServer& server) {
  ws.onEvent([](AsyncWebSocket *server, AsyncWebSocketClient *client, AwsEventType type,
                void *arg, uint8_t *data, size_t len) {
    switch (type) {
      case WS_EVT_CONNECT:
        Serial.printf("[WebSocket] 📱 Phone connected! Client #%u from %s\n", 
                      client->id(), client->remoteIP().toString().c_str());
        Serial.println("[WebSocket] 👑 This device is now the MASTER!");
        break;
        
      case WS_EVT_DISCONNECT:
        Serial.printf("[WebSocket] 📱 Phone disconnected! Client #%u\n", client->id());
        if (ws.count() == 0) {
          Serial.println("[WebSocket] 📵 No phones connected - back to normal operation");
        }
        break;
        
      case WS_EVT_ERROR:
        Serial.printf("[WebSocket] ❌ Error on client #%u\n", client->id());
        break;
        
      default:
        break;
    }
  });

  server.addHandler(&ws);
  Serial.println("[WebSocket] ✅ WebSocket handler initialized");
}

void broadcastAggregatedData() {
  if (ws.count() == 0) {
    // No phones connected, don't waste cycles
    return;
  }
  
  StaticJsonDocument<2048> doc;
  JsonArray devices = doc.createNestedArray("devices");
  
  // Add this device (Master) data
  JsonObject masterDevice = devices.createNestedObject();
  masterDevice["mac_address"] = WiFi.macAddress();
  masterDevice["role"] = "master";
  masterDevice["main_air_pressure"] = random(1000, 1200) / 10.0;
  masterDevice["atmospheric_pressure"] = random(950, 1050) / 10.0;
  masterDevice["temperature"] = random(180, 300) / 10.0;
  masterDevice["elevation"] = random(0, 500);
  masterDevice["gps_lat"] = 49.2827 + random(-100, 100) / 10000.0;
  masterDevice["gps_lng"] = -123.1207 + random(-100, 100) / 10000.0;
  masterDevice["weight"] = random(0, 2000) / 10.0;
  masterDevice["online"] = true;
  
  float masterWeight = masterDevice["weight"];
  
  // Add nearby devices data
  cleanupOfflineDevices();
  for (int i = 0; i < nearbyDeviceCount; i++) {
    JsonObject slaveDevice = devices.createNestedObject();
    slaveDevice["mac_address"] = nearbyDevices[i].mac_address;
    slaveDevice["role"] = "slave";
    slaveDevice["main_air_pressure"] = nearbyDevices[i].main_air_pressure;
    slaveDevice["atmospheric_pressure"] = nearbyDevices[i].atmospheric_pressure;
    slaveDevice["temperature"] = nearbyDevices[i].temperature;
    slaveDevice["elevation"] = nearbyDevices[i].elevation;
    slaveDevice["gps_lat"] = nearbyDevices[i].gps_lat;
    slaveDevice["gps_lng"] = nearbyDevices[i].gps_lng;
    slaveDevice["weight"] = nearbyDevices[i].weight;
    slaveDevice["online"] = nearbyDevices[i].is_online;
  }
  
  // Add summary data
  doc["total_weight"] = getTotalWeight();
  doc["device_count"] = getTotalOnlineDevices();
  doc["online_count"] = getTotalOnlineDevices();
  doc["master_mac"] = WiFi.macAddress();
  doc["timestamp"] = millis();
  
  String json;
  serializeJson(doc, json);
  ws.textAll(json);

  Serial.printf("[WebSocket] 📤 Data sent to %d phones - %d devices online, total weight: %.1f kg\n", 
                ws.count(), getTotalOnlineDevices(), getTotalWeight());
}

bool hasPhoneConnected() {
  return ws.count() > 0;
}
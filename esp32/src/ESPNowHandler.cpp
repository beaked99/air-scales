#include "ESPNowHandler.h"

#define MAX_SLAVE_DEVICES 10
#define SLAVE_TIMEOUT_MS 15000  // 15 seconds timeout

// Hardcoded known peers (revert to working version)
uint8_t knownPeers[][6] = {
  {0xEC, 0xDA, 0x3B, 0x5C, 0x19, 0x84}, // Device 1 MAC
  {0xEC, 0xDA, 0x3B, 0x5C, 0x1A, 0x1C}  // Device 2 MAC
};
int numKnownPeers = 2;

// Storage for nearby device data
sensor_data_t nearbyDevices[MAX_SLAVE_DEVICES];
int nearbyDeviceCount = 0;
unsigned long lastSeenTime[MAX_SLAVE_DEVICES];

void initializeESPNow() {
  Serial.println("[ESP-NOW] Initializing ESP-NOW communication...");
  Serial.printf("[ESP-NOW] 🔍 WiFi Mode: %d\n", WiFi.getMode());
  
  // Get the actual WiFi channel being used
  int actualChannel = WiFi.channel();
  Serial.printf("[ESP-NOW] 📍 WiFi actual channel: %d\n", actualChannel);
  
  delay(500);
  
  esp_err_t initResult = esp_now_init();
  Serial.printf("[ESP-NOW] 🔧 ESP-NOW init result: %d\n", initResult);
  
  if (initResult != ESP_OK) {
    Serial.printf("[ESP-NOW] ❌ ERROR: ESP-NOW initialization failed with error: %d\n", initResult);
    return;
  }
  
  esp_err_t callbackResult = esp_now_register_recv_cb(onDataReceived);
  Serial.printf("[ESP-NOW] 📞 Callback registration result: %d\n", callbackResult);
  
  // Add peers using WiFi's ACTUAL channel
  String myMac = WiFi.macAddress();
  myMac.toLowerCase(); // Ensure consistent case
  
  for (int i = 0; i < numKnownPeers; i++) {
    char peerMacStr[18];
    snprintf(peerMacStr, sizeof(peerMacStr), "%02x:%02x:%02x:%02x:%02x:%02x",
             knownPeers[i][0], knownPeers[i][1], knownPeers[i][2],
             knownPeers[i][3], knownPeers[i][4], knownPeers[i][5]);
    
    // Skip self
    if (myMac.equals(String(peerMacStr))) {
      Serial.printf("[ESP-NOW] ⏭️  Skipping self: %s\n", peerMacStr);
      continue;
    }
    
    esp_now_peer_info_t peerInfo;
    memcpy(peerInfo.peer_addr, knownPeers[i], 6);
    peerInfo.channel = actualChannel;  // Use WiFi's actual channel
    peerInfo.encrypt = false;
    
    esp_err_t addResult = esp_now_add_peer(&peerInfo);
    Serial.printf("[ESP-NOW] 🤝 Added peer %s (channel %d): result %d\n", 
                  peerMacStr, actualChannel, addResult);
  }
  
  Serial.println("[ESP-NOW] ✅ ESP-NOW initialized successfully");
  Serial.printf("[ESP-NOW] 📡 Device MAC: %s\n", WiFi.macAddress().c_str());
}

void onDataReceived(const uint8_t *mac, const uint8_t *incomingData, int len) {
  // Convert MAC to string
  char macStr[18];
  snprintf(macStr, sizeof(macStr), "%02x:%02x:%02x:%02x:%02x:%02x",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
  
  Serial.printf("[ESP-NOW] 📥 Data received from: %s (length: %d bytes)\n", macStr, len);
  
  if (len != sizeof(sensor_data_t)) {
    Serial.printf("[ESP-NOW] ❌ Invalid data size: %d (expected %d)\n", len, sizeof(sensor_data_t));
    return;
  }
  
  sensor_data_t* receivedData = (sensor_data_t*)incomingData;
  Serial.printf("[ESP-NOW] 📊 Device data - Weight: %.1f kg, Temp: %.1f°C\n", 
                receivedData->weight, receivedData->temperature);
  
  // Find existing device or create new slot
  int deviceIndex = -1;
  for (int i = 0; i < nearbyDeviceCount; i++) {
    if (strcmp(nearbyDevices[i].mac_address, macStr) == 0) {
      deviceIndex = i;
      break;
    }
  }
  
  // Create new device slot if needed
  if (deviceIndex == -1) {
    if (nearbyDeviceCount < MAX_SLAVE_DEVICES) {
      deviceIndex = nearbyDeviceCount;
      nearbyDeviceCount++;
      Serial.printf("[ESP-NOW] 🆕 New device registered: %s (total: %d)\n", macStr, nearbyDeviceCount);
    } else {
      Serial.println("[ESP-NOW] ⚠️  Maximum device limit reached, ignoring new device");
      return;
    }
  }
  
  // Update device data
  nearbyDevices[deviceIndex] = *receivedData;
  strcpy(nearbyDevices[deviceIndex].mac_address, macStr); // Ensure MAC is correct
  lastSeenTime[deviceIndex] = millis();
  
  Serial.printf("[ESP-NOW] ✅ Updated device %s data\n", macStr);
}

void broadcastOwnSensorData() {
  sensor_data_t ownData;
  
  // Fill with current device's sensor data
  strcpy(ownData.mac_address, WiFi.macAddress().c_str());
  ownData.main_air_pressure = random(1000, 1200) / 10.0;
  ownData.atmospheric_pressure = random(950, 1050) / 10.0;
  ownData.temperature = random(180, 300) / 10.0;
  ownData.elevation = random(0, 500);
  ownData.gps_lat = 49.2827 + random(-100, 100) / 10000.0;
  ownData.gps_lng = -123.1207 + random(-100, 100) / 10000.0;
  ownData.weight = random(0, 2000) / 10.0;
  ownData.timestamp = millis();
  ownData.is_online = true;
  
  // Send to all known peers except self
  String myMac = WiFi.macAddress();
  myMac.toLowerCase(); // Ensure consistent case
  
  for (int i = 0; i < numKnownPeers; i++) {
    char peerMacStr[18];
    snprintf(peerMacStr, sizeof(peerMacStr), "%02x:%02x:%02x:%02x:%02x:%02x",
             knownPeers[i][0], knownPeers[i][1], knownPeers[i][2],
             knownPeers[i][3], knownPeers[i][4], knownPeers[i][5]);
    
    // Skip self - proper case handling
    if (myMac.equals(String(peerMacStr))) {
      Serial.printf("[ESP-NOW] ⏭️  Skipping send to self: %s\n", peerMacStr);
      continue;
    }
    
    // WORKING TECHNIQUE: Remove and re-add peer before sending
    esp_now_del_peer(knownPeers[i]);
    
    esp_now_peer_info_t peerInfo;
    memcpy(peerInfo.peer_addr, knownPeers[i], 6);
    peerInfo.channel = WiFi.channel(); // Use current WiFi channel
    peerInfo.encrypt = false;
    
    esp_err_t addResult = esp_now_add_peer(&peerInfo);
    Serial.printf("[ESP-NOW] 🔄 Re-added peer %s: result %d\n", peerMacStr, addResult);
    
    Serial.printf("[ESP-NOW] 🎯 Attempting send to: %s\n", peerMacStr);
    esp_err_t result = esp_now_send(knownPeers[i], (uint8_t*)&ownData, sizeof(ownData));
    
    if (result == ESP_OK) {
      Serial.printf("[ESP-NOW] 📤 SUCCESS! Sent to %s - Weight: %.1f kg\n", peerMacStr, ownData.weight);
    } else {
      Serial.printf("[ESP-NOW] ❌ Send to %s failed, error: %d\n", peerMacStr, result);
    }
  }
}

void cleanupOfflineDevices() {
  unsigned long currentTime = millis();
  
  for (int i = 0; i < nearbyDeviceCount; i++) {
    if ((currentTime - lastSeenTime[i]) > SLAVE_TIMEOUT_MS) {
      nearbyDevices[i].is_online = false;
    } else {
      nearbyDevices[i].is_online = true;
    }
  }
}

int getTotalOnlineDevices() {
  cleanupOfflineDevices();
  
  int onlineCount = 1; // Always count self as online
  for (int i = 0; i < nearbyDeviceCount; i++) {
    if (nearbyDevices[i].is_online) {
      onlineCount++;
    }
  }
  return onlineCount;
}

float getTotalWeight() {
  cleanupOfflineDevices();
  
  // Start with own weight (random for now)
  float totalWeight = random(0, 2000) / 10.0;
  
  for (int i = 0; i < nearbyDeviceCount; i++) {
    if (nearbyDevices[i].is_online) {
      totalWeight += nearbyDevices[i].weight;
    }
  }
  
  return totalWeight;
}
#include "ESPNowHandler.h"
#include "DeviceManager.h"

#define MAX_SLAVE_DEVICES 10
#define SLAVE_TIMEOUT_MS 15000  // 15 seconds timeout

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
  
  // Register callback for ALL data (sensor data + announcements)
  esp_err_t callbackResult = esp_now_register_recv_cb(onDataReceived);
  Serial.printf("[ESP-NOW] 📞 Callback registration result: %d\n", callbackResult);
  
  // Add broadcast peer for announcements
  esp_now_peer_info_t broadcastPeer;
  memset(&broadcastPeer, 0, sizeof(broadcastPeer));
  uint8_t broadcastAddr[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  memcpy(broadcastPeer.peer_addr, broadcastAddr, 6);
  broadcastPeer.channel = actualChannel;
  broadcastPeer.encrypt = false;
  
  esp_err_t broadcastResult = esp_now_add_peer(&broadcastPeer);
  Serial.printf("[ESP-NOW] 📢 Broadcast peer added: result %d\n", broadcastResult);
  
  // Add linked devices as peers
  addLinkedDevicesAsPeers();
  
  Serial.println("[ESP-NOW] ✅ ESP-NOW initialized successfully");
  Serial.printf("[ESP-NOW] 📡 Device MAC: %s\n", WiFi.macAddress().c_str());
}

void addLinkedDevicesAsPeers() {
  Serial.println("[ESP-NOW] 🔗 Adding linked devices as peers...");
  
  int actualChannel = WiFi.channel();
  String myMac = WiFi.macAddress();
  myMac.toLowerCase();
  
  for (int i = 0; i < getLinkedDeviceCount(); i++) {
    String deviceMac = linkedDevices[i];
    deviceMac.toLowerCase();
    
    // Skip self
    if (myMac.equals(deviceMac)) {
      Serial.printf("[ESP-NOW] ⏭️  Skipping self: %s\n", deviceMac.c_str());
      continue;
    }
    
    // Convert string MAC to byte array
    uint8_t peerMac[6];
    if (parseMacAddress(deviceMac.c_str(), peerMac)) {
      esp_now_peer_info_t peerInfo;
      memcpy(peerInfo.peer_addr, peerMac, 6);
      peerInfo.channel = actualChannel;
      peerInfo.encrypt = false;
      
      esp_err_t addResult = esp_now_add_peer(&peerInfo);
      Serial.printf("[ESP-NOW] 🤝 Added linked device %s (channel %d): result %d\n", 
                    deviceMac.c_str(), actualChannel, addResult);
    } else {
      Serial.printf("[ESP-NOW] ❌ Invalid MAC address format: %s\n", deviceMac.c_str());
    }
  }
  
  Serial.printf("[ESP-NOW] ✅ Added %d linked devices as peers\n", getLinkedDeviceCount());
}

bool parseMacAddress(const char* macStr, uint8_t* macBytes) {
  int values[6];
  int result = sscanf(macStr, "%x:%x:%x:%x:%x:%x", 
                      &values[0], &values[1], &values[2], 
                      &values[3], &values[4], &values[5]);
  
  if (result == 6) {
    for (int i = 0; i < 6; i++) {
      macBytes[i] = (uint8_t) values[i];
    }
    return true;
  }
  return false;
}

void onDataReceived(const uint8_t *mac, const uint8_t *incomingData, int len) {
  // Convert MAC to string
  char macStr[18];
  snprintf(macStr, sizeof(macStr), "%02x:%02x:%02x:%02x:%02x:%02x",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
  
  // Check if this is an announcement packet
  if (len == sizeof(announcement_packet_t)) {
    onAnnouncementReceived(mac, incomingData, len);
    return;
  }
  
  // Check if this is a pairing packet
  if (len == sizeof(pairing_packet_t)) {
    onPairingPacketReceived(mac, incomingData, len);
    return;
  }
  
  // Check if this is sensor data
  if (len != sizeof(sensor_data_t)) {
    Serial.printf("[ESP-NOW] ❌ Unknown data size: %d (expected %d, %d, or %d)\n", 
                  len, sizeof(sensor_data_t), sizeof(announcement_packet_t), sizeof(pairing_packet_t));
    return;
  }
  
  Serial.printf("[ESP-NOW] 📥 Sensor data received from: %s (length: %d bytes)\n", macStr, len);
  
  // Only process sensor data from linked devices
  if (!isDeviceLinked(macStr)) {
    Serial.printf("[ESP-NOW] ⚠️  Ignoring sensor data from unlinked device: %s\n", macStr);
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
      Serial.printf("[ESP-NOW] 🆕 New linked device registered: %s (total: %d)\n", macStr, nearbyDeviceCount);
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
  
  // Send to all linked devices
  String myMac = WiFi.macAddress();
  myMac.toLowerCase();
  
  for (int i = 0; i < getLinkedDeviceCount(); i++) {
    String deviceMac = linkedDevices[i];
    deviceMac.toLowerCase();
    
    // Skip self
    if (myMac.equals(deviceMac)) {
      Serial.printf("[ESP-NOW] ⏭️  Skipping send to self: %s\n", deviceMac.c_str());
      continue;
    }
    
    // Convert string MAC to byte array
    uint8_t peerMac[6];
    if (!parseMacAddress(deviceMac.c_str(), peerMac)) {
      Serial.printf("[ESP-NOW] ❌ Invalid MAC format: %s\n", deviceMac.c_str());
      continue;
    }
    
    // Remove and re-add peer before sending (working technique)
    esp_now_del_peer(peerMac);
    
    esp_now_peer_info_t peerInfo;
    memcpy(peerInfo.peer_addr, peerMac, 6);
    peerInfo.channel = WiFi.channel();
    peerInfo.encrypt = false;
    
    esp_err_t addResult = esp_now_add_peer(&peerInfo);
    Serial.printf("[ESP-NOW] 🔄 Re-added peer %s: result %d\n", deviceMac.c_str(), addResult);
    
    Serial.printf("[ESP-NOW] 🎯 Attempting send to: %s\n", deviceMac.c_str());
    esp_err_t result = esp_now_send(peerMac, (uint8_t*)&ownData, sizeof(ownData));
    
    if (result == ESP_OK) {
      Serial.printf("[ESP-NOW] 📤 SUCCESS! Sent to %s - Weight: %.1f kg\n", deviceMac.c_str(), ownData.weight);
    } else {
      Serial.printf("[ESP-NOW] ❌ Send to %s failed, error: %d\n", deviceMac.c_str(), result);
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

void refreshPeersFromLinkedDevices() {
  Serial.println("[ESP-NOW] 🔄 Refreshing peers from linked devices...");
  
  // Clear all existing peers except broadcast
  esp_now_peer_num_t peerNum;
  esp_now_get_peer_num(&peerNum);
  
  // Get all current peers and remove them (except broadcast)
  esp_now_peer_info_t peerInfo;
  esp_now_peer_info_t *peer = &peerInfo;
  
  for (int i = 0; i < peerNum.total_num; i++) {
    if (esp_now_fetch_peer(true, peer) == ESP_OK) {
      // Don't remove broadcast peer (FF:FF:FF:FF:FF:FF)
      bool isBroadcast = true;
      for (int j = 0; j < 6; j++) {
        if (peer->peer_addr[j] != 0xFF) {
          isBroadcast = false;
          break;
        }
      }
      
      if (!isBroadcast) {
        esp_now_del_peer(peer->peer_addr);
      }
    }
  }
  
  // Re-add all linked devices
  addLinkedDevicesAsPeers();
}
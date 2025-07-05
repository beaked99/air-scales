#include "DeviceManager.h"
#include "ESPNowHandler.h" // Include this to get parseMacAddress declaration

// Global variables
String linkedDevices[MAX_DISCOVERED_DEVICES];
int linkedDeviceCount = 0;
discovered_device_t discoveredDevices[MAX_DISCOVERED_DEVICES];
int discoveredDeviceCount = 0;
bool discoveryMode = false;
unsigned long discoveryStartTime = 0;

void initializeDeviceManager() {
  Serial.println("[DeviceManager] 📋 Initializing Device Manager...");
  
  // Create devices.json if it doesn't exist
  if (!SPIFFS.exists(DEVICES_JSON_FILE)) {
    Serial.println("[DeviceManager] 📄 Creating devices.json file...");
    
    StaticJsonDocument<1024> doc;
    JsonArray devices = doc.createNestedArray("devices");
    
    File file = SPIFFS.open(DEVICES_JSON_FILE, FILE_WRITE);
    if (file) {
      serializeJson(doc, file);
      file.close();
      Serial.println("[DeviceManager] ✅ devices.json created successfully");
    } else {
      Serial.println("[DeviceManager] ❌ Failed to create devices.json");
    }
  }
  
  // Load existing linked devices
  if (loadLinkedDevices()) {
    Serial.printf("[DeviceManager] ✅ Loaded %d linked devices\n", linkedDeviceCount);
  } else {
    Serial.println("[DeviceManager] ⚠️  No linked devices found or failed to load");
  }
  
  Serial.println("[DeviceManager] ✅ Device Manager initialized");
}

bool loadLinkedDevices() {
  File file = SPIFFS.open(DEVICES_JSON_FILE, FILE_READ);
  if (!file) {
    Serial.println("[DeviceManager] ❌ Failed to open devices.json for reading");
    return false;
  }
  
  StaticJsonDocument<2048> doc;
  DeserializationError error = deserializeJson(doc, file);
  file.close();
  
  if (error) {
    Serial.printf("[DeviceManager] ❌ JSON parsing error: %s\n", error.c_str());
    return false;
  }
  
  linkedDeviceCount = 0;
  JsonArray devices = doc["devices"];
  
  for (JsonObject device : devices) {
    if (linkedDeviceCount < MAX_DISCOVERED_DEVICES) {
      linkedDevices[linkedDeviceCount] = device["mac_address"].as<String>();
      linkedDeviceCount++;
      Serial.printf("[DeviceManager] 📱 Loaded linked device: %s\n", 
                    linkedDevices[linkedDeviceCount-1].c_str());
    }
  }
  
  return true;
}

bool saveLinkedDevices() {
  StaticJsonDocument<2048> doc;
  JsonArray devices = doc.createNestedArray("devices");
  
  for (int i = 0; i < linkedDeviceCount; i++) {
    JsonObject device = devices.createNestedObject();
    device["mac_address"] = linkedDevices[i];
    device["date_added"] = millis(); // You might want to store actual timestamps
  }
  
  File file = SPIFFS.open(DEVICES_JSON_FILE, FILE_WRITE);
  if (!file) {
    Serial.println("[DeviceManager] ❌ Failed to open devices.json for writing");
    return false;
  }
  
  serializeJson(doc, file);
  file.close();
  
  Serial.printf("[DeviceManager] 💾 Saved %d linked devices to SPIFFS\n", linkedDeviceCount);
  return true;
}

bool addLinkedDevice(const char* macAddress) {
  // Check if device is already linked
  for (int i = 0; i < linkedDeviceCount; i++) {
    if (linkedDevices[i].equals(macAddress)) {
      Serial.printf("[DeviceManager] ⚠️  Device %s already linked\n", macAddress);
      return false;
    }
  }
  
  // Check if we have space
  if (linkedDeviceCount >= MAX_DISCOVERED_DEVICES) {
    Serial.println("[DeviceManager] ❌ Maximum linked devices reached");
    return false;
  }
  
  // Add device
  linkedDevices[linkedDeviceCount] = String(macAddress);
  linkedDeviceCount++;
  
  // Save to SPIFFS
  if (saveLinkedDevices()) {
    Serial.printf("[DeviceManager] ✅ Linked device: %s\n", macAddress);
    
    // Send pairing request to establish bidirectional link
    sendPairingRequest(macAddress);
    
    // Remove from discovered devices
    for (int i = 0; i < discoveredDeviceCount; i++) {
      if (strcmp(discoveredDevices[i].mac_address, macAddress) == 0) {
        // Shift remaining devices
        for (int j = i; j < discoveredDeviceCount - 1; j++) {
          discoveredDevices[j] = discoveredDevices[j + 1];
        }
        discoveredDeviceCount--;
        break;
      }
    }
    
    return true;
  }
  
  // If save failed, remove from memory
  linkedDeviceCount--;
  return false;
}

bool removeLinkedDevice(const char* macAddress) {
  // Find and remove device
  for (int i = 0; i < linkedDeviceCount; i++) {
    if (linkedDevices[i].equals(macAddress)) {
      // Shift remaining devices
      for (int j = i; j < linkedDeviceCount - 1; j++) {
        linkedDevices[j] = linkedDevices[j + 1];
      }
      linkedDeviceCount--;
      
      // Save to SPIFFS
      if (saveLinkedDevices()) {
        Serial.printf("[DeviceManager] ✅ Unlinked device: %s\n", macAddress);
        return true;
      } else {
        // If save failed, restore device
        for (int j = linkedDeviceCount; j > i; j--) {
          linkedDevices[j] = linkedDevices[j - 1];
        }
        linkedDevices[i] = String(macAddress);
        linkedDeviceCount++;
        return false;
      }
    }
  }
  
  Serial.printf("[DeviceManager] ⚠️  Device %s not found in linked devices\n", macAddress);
  return false;
}

void startDeviceDiscovery() {
  Serial.println("[DeviceManager] 🔍 Starting device discovery mode...");
  discoveryMode = true;
  discoveryStartTime = millis();
  discoveredDeviceCount = 0; // Clear previous discoveries
}

void stopDeviceDiscovery() {
  if (discoveryMode) {
    Serial.println("[DeviceManager] 🔍 Stopping device discovery mode...");
    discoveryMode = false;
  }
}

void onAnnouncementReceived(const uint8_t *mac, const uint8_t *incomingData, int len) {
  if (len != sizeof(announcement_packet_t)) {
    return; // Not an announcement packet
  }
  
  announcement_packet_t* announcement = (announcement_packet_t*)incomingData;
  
  // Verify this is an Air Scales device
  if (strcmp(announcement->device_type, "AIR_SCALES") != 0) {
    return;
  }
  
  // Convert MAC to string
  char macStr[18];
  snprintf(macStr, sizeof(macStr), "%02x:%02x:%02x:%02x:%02x:%02x",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
  
  // Skip self
  if (WiFi.macAddress().equals(macStr)) {
    return;
  }
  
  // Skip already linked devices
  if (isDeviceLinked(macStr)) {
    return;
  }
  
  // Only process during discovery mode
  if (!discoveryMode) {
    return;
  }
  
  Serial.printf("[DeviceManager] 📡 Announcement from: %s\n", macStr);
  
  // Check if already in discovered list
  for (int i = 0; i < discoveredDeviceCount; i++) {
    if (strcmp(discoveredDevices[i].mac_address, macStr) == 0) {
      // Update last seen time
      discoveredDevices[i].last_seen = millis();
      return;
    }
  }
  
  // Add new discovered device
  if (discoveredDeviceCount < MAX_DISCOVERED_DEVICES) {
    strcpy(discoveredDevices[discoveredDeviceCount].mac_address, macStr);
    discoveredDevices[discoveredDeviceCount].last_seen = millis();
    discoveredDevices[discoveredDeviceCount].is_linked = false;
    discoveredDeviceCount++;
    
    Serial.printf("[DeviceManager] 🆕 New device discovered: %s (total: %d)\n", 
                  macStr, discoveredDeviceCount);
  }
}

void broadcastAnnouncement() {
  announcement_packet_t announcement;
  strcpy(announcement.device_type, "AIR_SCALES");
  strcpy(announcement.mac_address, WiFi.macAddress().c_str());
  announcement.timestamp = millis();
  announcement.version = 1;
  
  // Broadcast to all (0xFF:0xFF:0xFF:0xFF:0xFF:0xFF)
  uint8_t broadcastMac[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
  
  esp_err_t result = esp_now_send(broadcastMac, (uint8_t*)&announcement, sizeof(announcement));
  
  if (result == ESP_OK) {
    Serial.printf("[DeviceManager] 📢 Announcement broadcasted: %s\n", WiFi.macAddress().c_str());
  } else {
    Serial.printf("[DeviceManager] ❌ Announcement broadcast failed: %d\n", result);
  }
}

bool isDeviceLinked(const char* macAddress) {
  for (int i = 0; i < linkedDeviceCount; i++) {
    if (linkedDevices[i].equals(macAddress)) {
      return true;
    }
  }
  return false;
}

int getLinkedDeviceCount() {
  return linkedDeviceCount;
}

int getDiscoveredDeviceCount() {
  cleanupDiscoveredDevices();
  return discoveredDeviceCount;
}

void cleanupDiscoveredDevices() {
  unsigned long currentTime = millis();
  
  for (int i = 0; i < discoveredDeviceCount; i++) {
    if ((currentTime - discoveredDevices[i].last_seen) > DISCOVERY_TIMEOUT_MS) {
      // Remove expired device
      for (int j = i; j < discoveredDeviceCount - 1; j++) {
        discoveredDevices[j] = discoveredDevices[j + 1];
      }
      discoveredDeviceCount--;
      i--; // Check the same index again
    }
  }
}

String getLinkedDevicesJson() {
  StaticJsonDocument<2048> doc;
  JsonArray devices = doc.createNestedArray("devices");
  
  for (int i = 0; i < linkedDeviceCount; i++) {
    JsonObject device = devices.createNestedObject();
    device["mac_address"] = linkedDevices[i];
    device["date_added"] = millis(); // You might want to store actual timestamps
  }
  
  String json;
  serializeJson(doc, json);
  return json;
}

void onPairingPacketReceived(const uint8_t *mac, const uint8_t *incomingData, int len) {
  if (len != sizeof(pairing_packet_t)) {
    return; // Not a pairing packet
  }
  
  pairing_packet_t* pairingPacket = (pairing_packet_t*)incomingData;
  
  // Convert MAC to string
  char macStr[18];
  snprintf(macStr, sizeof(macStr), "%02x:%02x:%02x:%02x:%02x:%02x",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
  
  if (strcmp(pairingPacket->packet_type, "PAIR_REQUEST") == 0) {
    Serial.printf("[DeviceManager] 🤝 Received pairing request from: %s\n", macStr);
    
    // Verify this request is for us
    String myMac = WiFi.macAddress();
    if (!myMac.equalsIgnoreCase(pairingPacket->slave_mac)) {
      Serial.printf("[DeviceManager] ⚠️  Pairing request not for us (target: %s, us: %s)\n", 
                    pairingPacket->slave_mac, myMac.c_str());
      return;
    }
    
    // Auto-link the master device that sent the request
    if (!isDeviceLinked(pairingPacket->master_mac)) {
      linkedDevices[linkedDeviceCount] = String(pairingPacket->master_mac);
      linkedDeviceCount++;
      
      if (saveLinkedDevices()) {
        Serial.printf("[DeviceManager] 🔗 Auto-linked master device: %s\n", pairingPacket->master_mac);
        
        // Send pairing response
        pairing_packet_t response;
        strcpy(response.packet_type, "PAIR_RESPONSE");
        strcpy(response.master_mac, pairingPacket->master_mac);
        strcpy(response.slave_mac, WiFi.macAddress().c_str());
        response.timestamp = millis();
        response.version = 1;
        
        // Convert master MAC string to bytes for sending
        uint8_t masterMacBytes[6];
        if (parseMacAddress(pairingPacket->master_mac, masterMacBytes)) {
          esp_err_t result = esp_now_send(masterMacBytes, (uint8_t*)&response, sizeof(response));
          Serial.printf("[DeviceManager] 📤 Pairing response sent: result %d\n", result);
        }
      } else {
        linkedDeviceCount--; // Rollback on save failure
      }
    }
    
  } else if (strcmp(pairingPacket->packet_type, "PAIR_RESPONSE") == 0) {
    Serial.printf("[DeviceManager] ✅ Received pairing response from: %s\n", macStr);
    Serial.println("[DeviceManager] 🎉 Bidirectional pairing completed!");
  }
}

void sendPairingRequest(const char* targetMac) {
  Serial.printf("[DeviceManager] 🤝 Sending pairing request to: %s\n", targetMac);
  
  pairing_packet_t pairingRequest;
  strcpy(pairingRequest.packet_type, "PAIR_REQUEST");
  strcpy(pairingRequest.master_mac, WiFi.macAddress().c_str());
  strcpy(pairingRequest.slave_mac, targetMac);
  pairingRequest.timestamp = millis();
  pairingRequest.version = 1;
  
  // Convert target MAC string to bytes
  uint8_t targetMacBytes[6];
  if (parseMacAddress(targetMac, targetMacBytes)) {
    // Remove peer if it exists
    esp_now_del_peer(targetMacBytes);
    
    // Add as peer for pairing with proper configuration
    esp_now_peer_info_t peerInfo;
    memset(&peerInfo, 0, sizeof(peerInfo));
    memcpy(peerInfo.peer_addr, targetMacBytes, 6);
    peerInfo.channel = WiFi.channel();
    peerInfo.encrypt = false;
    peerInfo.ifidx = WIFI_IF_AP; // Use AP interface
    
    esp_err_t addResult = esp_now_add_peer(&peerInfo);
    Serial.printf("[DeviceManager] 🔄 Added peer for pairing: result %d\n", addResult);
    
    // Small delay to ensure peer is properly added
    delay(10);
    
    esp_err_t result = esp_now_send(targetMacBytes, (uint8_t*)&pairingRequest, sizeof(pairingRequest));
    
    if (result == ESP_OK) {
      Serial.printf("[DeviceManager] 📤 Pairing request sent successfully to %s\n", targetMac);
    } else {
      Serial.printf("[DeviceManager] ❌ Failed to send pairing request to %s: %d\n", targetMac, result);
      
      // Try using broadcast as fallback
      Serial.printf("[DeviceManager] 🔄 Trying broadcast fallback for pairing...\n");
      uint8_t broadcastMac[6] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
      esp_err_t broadcastResult = esp_now_send(broadcastMac, (uint8_t*)&pairingRequest, sizeof(pairingRequest));
      Serial.printf("[DeviceManager] 📡 Broadcast pairing result: %d\n", broadcastResult);
    }
  } else {
    Serial.printf("[DeviceManager] ❌ Invalid MAC address format for pairing: %s\n", targetMac);
  }
}

String getDiscoveredDevicesJson() {
  cleanupDiscoveredDevices();
  
  StaticJsonDocument<2048> doc;
  JsonArray devices = doc.createNestedArray("devices");
  
  for (int i = 0; i < discoveredDeviceCount; i++) {
    JsonObject device = devices.createNestedObject();
    device["mac_address"] = discoveredDevices[i].mac_address;
    device["last_seen"] = discoveredDevices[i].last_seen;
    device["is_linked"] = discoveredDevices[i].is_linked;
  }
  
  String json;
  serializeJson(doc, json);
  return json;
}
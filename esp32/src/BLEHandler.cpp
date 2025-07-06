#include "BLEHandler.h"
#include "ESPNowHandler.h"
#include "DeviceManager.h"
#include "WebSocketHandler.h"  // Include for hasPhoneConnected()

// Global BLE variables
BLEServer* pBLEServer = nullptr;
BLEService* pBLEService = nullptr;
BLECharacteristic* pWeightCharacteristic = nullptr;
BLECharacteristic* pDeviceInfoCharacteristic = nullptr;
BLECharacteristic* pCommandCharacteristic = nullptr;
bool bleClientConnected = false;
int bleClientCount = 0;

// BLE Server Callbacks
class AirScalesBLEServerCallbacks: public BLEServerCallbacks {
  void onConnect(BLEServer* pServer) {
    bleClientConnected = true;
    bleClientCount++;
    Serial.printf("[BLE] 📱 Client connected! Total clients: %d\n", bleClientCount);
    
    // Send initial device info
    updateBLEDeviceInfo();
  }

  void onDisconnect(BLEServer* pServer) {
    bleClientCount--;
    if (bleClientCount <= 0) {
      bleClientConnected = false;
      bleClientCount = 0;
    }
    Serial.printf("[BLE] 📱 Client disconnected. Remaining clients: %d\n", bleClientCount);
    
    // Restart advertising for new connections
    pServer->startAdvertising();
  }
};

// BLE Characteristic Callbacks for commands
class AirScalesBLECharacteristicCallbacks: public BLECharacteristicCallbacks {
  void onWrite(BLECharacteristic *pCharacteristic) {
    std::string stdValue = pCharacteristic->getValue();
    String value = String(stdValue.c_str());  // Convert std::string to Arduino String
    if (value.length() > 0) {
      Serial.printf("[BLE] 📝 Command received: %s\n", value.c_str());
      handleBLECommand(value);
    }
  }
};

void initializeBLE() {
  Serial.println("[BLE] 🔵 Initializing Bluetooth Low Energy...");
  
  // Initialize BLE Device
  String deviceName = "AirScales-" + WiFi.macAddress();
  BLEDevice::init(deviceName.c_str());
  Serial.printf("[BLE] 📛 Device name: %s\n", deviceName.c_str());
  
  // Create BLE Server
  pBLEServer = BLEDevice::createServer();
  pBLEServer->setCallbacks(new AirScalesBLEServerCallbacks());
  
  // Create BLE Service
  pBLEService = pBLEServer->createService(AIRSCALES_SERVICE_UUID);
  
  // Weight Data Characteristic (READ + NOTIFY)
  pWeightCharacteristic = pBLEService->createCharacteristic(
    WEIGHT_CHAR_UUID,
    BLECharacteristic::PROPERTY_READ | 
    BLECharacteristic::PROPERTY_NOTIFY
  );
  pWeightCharacteristic->addDescriptor(new BLE2902());
  
  // Device Info Characteristic (READ)
  pDeviceInfoCharacteristic = pBLEService->createCharacteristic(
    DEVICE_INFO_CHAR_UUID,
    BLECharacteristic::PROPERTY_READ
  );
  
  // Command Characteristic (WRITE)
  pCommandCharacteristic = pBLEService->createCharacteristic(
    COMMAND_CHAR_UUID,
    BLECharacteristic::PROPERTY_WRITE
  );
  pCommandCharacteristic->setCallbacks(new AirScalesBLECharacteristicCallbacks());
  
  // Start the service
  pBLEService->start();
  
  // Start advertising
  BLEAdvertising *pAdvertising = BLEDevice::getAdvertising();
  pAdvertising->addServiceUUID(AIRSCALES_SERVICE_UUID);
  pAdvertising->setScanResponse(false);
  pAdvertising->setMinPreferred(0x0);  // Functions that help with iPhone connections issue
  BLEDevice::startAdvertising();
  
  Serial.println("[BLE] ✅ BLE service started and advertising");
  Serial.printf("[BLE] 📡 Service UUID: %s\n", AIRSCALES_SERVICE_UUID);
}

void broadcastToBLE() {
  if (!bleClientConnected || !pWeightCharacteristic) {
    return; // No BLE clients connected
  }
  
  // Create JSON data same as WebSocket
  StaticJsonDocument<1024> doc;
  JsonArray devices = doc.createNestedArray("devices");
  
  // Add this device (master/peer) data
  JsonObject thisDevice = devices.createNestedObject();
  thisDevice["mac_address"] = WiFi.macAddress();
  thisDevice["role"] = hasPhoneConnected() ? "master" : "peer";
  thisDevice["temperature"] = random(180, 300) / 10.0;
  thisDevice["weight"] = random(0, 2000) / 10.0;
  thisDevice["online"] = true;
  
  // Add nearby devices data
  cleanupOfflineDevices();
  for (int i = 0; i < nearbyDeviceCount; i++) {
    JsonObject device = devices.createNestedObject();
    device["mac_address"] = nearbyDevices[i].mac_address;
    device["role"] = "peer";
    device["temperature"] = nearbyDevices[i].temperature;
    device["weight"] = nearbyDevices[i].weight;
    device["online"] = nearbyDevices[i].is_online;
  }
  
  // Add summary data
  doc["total_weight"] = getTotalWeight();
  doc["device_count"] = getTotalOnlineDevices();
  doc["master_mac"] = WiFi.macAddress();
  doc["timestamp"] = millis();
  doc["connection_type"] = "ble";
  
  String json;
  serializeJson(doc, json);
  
  // Send to BLE clients
  pWeightCharacteristic->setValue(json.c_str());
  pWeightCharacteristic->notify();
  
  Serial.printf("[BLE] 📤 Data sent to %d BLE clients - %d devices, %.1f kg total\n", 
                bleClientCount, getTotalOnlineDevices(), getTotalWeight());
}

void updateBLEDeviceInfo() {
  if (!pDeviceInfoCharacteristic) return;
  
  StaticJsonDocument<512> doc;
  doc["device_mac"] = WiFi.macAddress();
  doc["device_name"] = "AirScales-" + WiFi.macAddress();
  doc["wifi_channel"] = WiFi.channel();
  doc["linked_devices"] = getLinkedDeviceCount();
  doc["software_version"] = "1.0.0";
  doc["uptime"] = millis() / 1000;
  doc["free_heap"] = ESP.getFreeHeap();
  
  String json;
  serializeJson(doc, json);
  
  pDeviceInfoCharacteristic->setValue(json.c_str());
  Serial.println("[BLE] 📋 Device info updated");
}

bool hasBLEClients() {
  return bleClientConnected && bleClientCount > 0;
}

int getBLEClientCount() {
  return bleClientCount;
}

void handleBLECommand(String command) {
  StaticJsonDocument<256> doc;
  DeserializationError error = deserializeJson(doc, command);
  
  if (error) {
    Serial.printf("[BLE] ❌ Invalid JSON command: %s\n", command.c_str());
    return;
  }
  
  String cmd = doc["command"];
  
  if (cmd == "get_device_info") {
    updateBLEDeviceInfo();
  } else if (cmd == "start_discovery") {
    startDeviceDiscovery();
    Serial.println("[BLE] 🔍 Device discovery started via BLE command");
  } else if (cmd == "stop_discovery") {
    stopDeviceDiscovery();
    Serial.println("[BLE] 🔍 Device discovery stopped via BLE command");
  } else if (cmd == "get_discovered") {
    // Could implement sending discovered devices via BLE if needed
    Serial.println("[BLE] 📋 Discovered devices request via BLE");
  } else {
    Serial.printf("[BLE] ❓ Unknown command: %s\n", cmd.c_str());
  }
}

void shutdownBLE() {
  if (pBLEServer) {
    pBLEServer->getAdvertising()->stop();
    Serial.println("[BLE] 🔴 BLE advertising stopped");
  }
  bleClientConnected = false;
  bleClientCount = 0;
}
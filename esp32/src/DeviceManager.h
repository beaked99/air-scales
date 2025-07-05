#ifndef DEVICEMANAGER_H
#define DEVICEMANAGER_H

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include <ArduinoJson.h>
#include <SPIFFS.h>
#include "DataStructures.h"

#define MAX_DISCOVERED_DEVICES 20
#define DISCOVERY_TIMEOUT_MS 30000  // 30 seconds
#define DEVICES_JSON_FILE "/devices.json"

// Structure for discovered devices during scanning
typedef struct {
  char mac_address[18];
  unsigned long last_seen;
  bool is_linked;
} discovered_device_t;

// Function declarations
void initializeDeviceManager();
bool loadLinkedDevices();
bool saveLinkedDevices();
bool addLinkedDevice(const char* macAddress);
bool removeLinkedDevice(const char* macAddress);
void startDeviceDiscovery();
void stopDeviceDiscovery();
void onAnnouncementReceived(const uint8_t *mac, const uint8_t *incomingData, int len);
void onPairingPacketReceived(const uint8_t *mac, const uint8_t *incomingData, int len);
void sendPairingRequest(const char* targetMac);
void broadcastAnnouncement();
bool isDeviceLinked(const char* macAddress);
int getLinkedDeviceCount();
int getDiscoveredDeviceCount();
void cleanupDiscoveredDevices();
String getLinkedDevicesJson();
String getDiscoveredDevicesJson();
bool parseMacAddress(const char* macStr, uint8_t* macBytes); // Add this declaration

// External variables
extern String linkedDevices[MAX_DISCOVERED_DEVICES];
extern int linkedDeviceCount;
extern discovered_device_t discoveredDevices[MAX_DISCOVERED_DEVICES];
extern int discoveredDeviceCount;
extern bool discoveryMode;

#endif // DEVICEMANAGER_H
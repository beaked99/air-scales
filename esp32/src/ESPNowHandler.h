#ifndef ESPNOWHANDLER_H
#define ESPNOWHANDLER_H

#include <Arduino.h>
#include <WiFi.h>
#include <esp_now.h>
#include "DataStructures.h"

#define MAX_NEARBY_DEVICES 10
#define DEVICE_TIMEOUT_MS 15000  // 15 seconds timeout

// Function declarations
void initializeESPNow();
void broadcastOwnSensorData();
void onDataReceived(const uint8_t *mac, const uint8_t *incomingData, int len);
void cleanupOfflineDevices();
int getTotalOnlineDevices();
float getTotalWeight();

// External variable declarations (defined in .cpp)
extern sensor_data_t nearbyDevices[MAX_NEARBY_DEVICES];
extern int nearbyDeviceCount;
extern unsigned long lastSeenTime[MAX_NEARBY_DEVICES];

#endif // ESPNOWHANDLER_H
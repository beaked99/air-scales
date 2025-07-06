#ifndef BLEHANDLER_H
#define BLEHANDLER_H

#include <Arduino.h>
#include <BLEDevice.h>
#include <BLEServer.h>
#include <BLEUtils.h>
#include <BLE2902.h>
#include <ArduinoJson.h>
#include "DataStructures.h"

// Service and Characteristic UUIDs
#define AIRSCALES_SERVICE_UUID    "12345678-1234-1234-1234-123456789abc"
#define WEIGHT_CHAR_UUID          "87654321-4321-4321-4321-cba987654321"
#define DEVICE_INFO_CHAR_UUID     "11223344-5566-7788-9900-aabbccddeeff"
#define COMMAND_CHAR_UUID         "aabbccdd-eeff-1122-3344-556677889900"

class BLEServerCallbacks;
class BLECharacteristicCallbacks;

// Function declarations
void initializeBLE();
void broadcastToBLE();
void updateBLEDeviceInfo();
bool hasBLEClients();
int getBLEClientCount();
void handleBLECommand(String command);
void shutdownBLE();

// External variables
extern BLEServer* pBLEServer;
extern BLEService* pBLEService;
extern BLECharacteristic* pWeightCharacteristic;
extern BLECharacteristic* pDeviceInfoCharacteristic;
extern BLECharacteristic* pCommandCharacteristic;
extern bool bleClientConnected;
extern int bleClientCount;

// Forward declarations from other modules
extern sensor_data_t nearbyDevices[];
extern int nearbyDeviceCount;

#endif // BLEHANDLER_H
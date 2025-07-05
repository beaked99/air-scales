#ifndef DATASTRUCTURES_H
#define DATASTRUCTURES_H

#include <Arduino.h>

// Sensor data structure for ESP-NOW communication
typedef struct {
  char mac_address[18];
  float main_air_pressure;
  float atmospheric_pressure;
  float temperature;
  int elevation;
  float gps_lat;
  float gps_lng;
  float weight;
  unsigned long timestamp;
  bool is_online;
} sensor_data_t;

// Announcement packet structure for device discovery
typedef struct {
  char device_type[16];  // "AIR_SCALES"
  char mac_address[18];
  unsigned long timestamp;
  uint8_t version;
} announcement_packet_t;

// Pairing packet structure for bidirectional linking
typedef struct {
  char packet_type[16];    // "PAIR_REQUEST" or "PAIR_RESPONSE"
  char master_mac[18];     // MAC of the device initiating pairing
  char slave_mac[18];      // MAC of the device being paired
  unsigned long timestamp;
  uint8_t version;
} pairing_packet_t;

#endif // DATASTRUCTURES_H
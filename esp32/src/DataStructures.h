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

#endif // DATASTRUCTURES_H
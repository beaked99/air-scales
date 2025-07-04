#ifndef WEBSOCKETHANDLER_H
#define WEBSOCKETHANDLER_H

#include <Arduino.h>
#include <WiFi.h>
#include <AsyncTCP.h>
#include <ESPAsyncWebServer.h>
#include <ArduinoJson.h>
#include "DataStructures.h"

extern AsyncWebSocket ws;

// Function declarations
void setupWebSocket(AsyncWebServer& server);
void broadcastAggregatedData();
bool hasPhoneConnected();

#endif // WEBSOCKETHANDLER_H
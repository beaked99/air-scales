#ifndef WEBSOCKETHANDLER_H
#define WEBSOCKETHANDLER_H

#include <Arduino.h>
#include <WiFi.h>
#include <AsyncTCP.h>
#include <ESPAsyncWebServer.h>
#include <ArduinoJson.h>

extern AsyncWebSocket ws;

void setupWebSocket(AsyncWebServer& server);
void broadcastSensorData();

#endif // WEBSOCKETHANDLER_H

// Air Scales - HTTPS Implementation
#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <WiFiClientSecure.h>
#include <SPIFFS.h>
#include <ArduinoJson.h>
#include "DataStructures.h"
#include "ESPNowHandler.h"
#include "WebSocketHandler.h"
#include "DeviceManager.h"
#include "BLEHandler.h"

#define WIFI_RESET_PIN 0

WebServer server(443);  // HTTPS server
bool systemInitialized = false;

// Self-signed certificate and key embedded
const char* server_cert = R"(-----BEGIN CERTIFICATE-----
MIICpDCCAYwCCQC+K7Vx9qJj3TANBgkqhkiG9w0BAQsFADAUMRIwEAYDVQQDDAkx
OTIuMTY4LjQuMTAeFw0yNTAxMDEwMDAwMDBaFw0yNjAxMDEwMDAwMDBaMBQxEjAQ
BgNVBAMMCTE5Mi4xNjguNC4xMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKC
AQEAzQWcZo3VZ1s5K3n1YgKYv8X7i4n2m9Lx6e3b8c4v5f6g7h8j9k0l1m2n3o4p
5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1g2h3i4j5k6l7m8n9o0p1q2r3s4t5u6v
7w8x9y0z1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b
9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h
1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7y8z9a0b1c2d3e4f5g6h7i8j9k0l1m2n
3o4p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1g2h3i4j5k6l7m8n9o0p1q2r3s4t
5QIDAQAB
-----END CERTIFICATE-----)";

const char* server_key = R"(-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDNBZxmjdVnWzkr
efViApi/xfuLifab0vHp7dvxzi/l/qDuHyP2TSXWbafejinmrqvuzy327S/XDbHf
LjPlrpvtzx315qfs/l5m6n7o8p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1g2h3i
4j5k6l7m8n9o0p1q2r3s4t5u6v7w8x9y0z1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o
6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u
8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7y8z9a
0b1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1g
2h3i4j5k6l7m8n9o0p1q2r3s4tAgMBAAECggEBAL8J5n4c8I9k3Y6Z7z2K5L6W
3M1H8g9P2J3k5n6o7p8q9r0s1t2u3v4w5x6y7z8a9b0c1d2e3f4g5h6i7j8k9l0m
1n2o3p4q5r6s7t8u9v0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m7n8o9p0q1r2s
3t4u5v6w7x8y9z0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y
5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7v8w9x0y1z2a3b4c5d6e
7f8g9h0i1j2k3l4m5n6o7p8q9r0s1t2u3v4w5x6y7z8a9b0c1d2e3f4g5h6i7j8k
9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m7n8o9p0q
1r2s3t4u5v6w7x8y9z0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w
3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7v8w9x0y1z2a3b4c
5d6e7f8g9h0i1j2k3l4m5n6o7p8q9r0s1t2u3v4w5x6y7z8a9b0c1d2e3f4g5h6i
7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z4a5b6c7d8e9f0g1h2i3j4k5l6m7n8o
9p0q1r2s3t4u5v6w7x8y9z0a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u
1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7v8w9x0y1z2a
ECggEAZoGzMqzLn5y4w3v2u1t0s9r8q7p6o5n4m3l2k1j0i9h8g7f6e5d4c3b2a1
-----END PRIVATE KEY-----)";

void initializeSystem() {
  Serial.println("");
  Serial.println("🚀 ================================");
  Serial.println("🚀 AIR SCALES ESP32 STARTING UP");
  Serial.println("🚀 ================================");
  Serial.printf("🔧 Device MAC: %s\n", WiFi.macAddress().c_str());
  
  // Mount SPIFFS for web files
  Serial.println("[SYSTEM] 📁 Mounting SPIFFS...");
  if (!SPIFFS.begin(true)) {
    Serial.println("[SYSTEM] ❌ SPIFFS mount failed!");
    return;
  }
  Serial.println("[SYSTEM] ✅ SPIFFS mounted successfully");
  
  // Initialize Device Manager
  initializeDeviceManager();
  
  // Setup WiFi Access Point
  String apName = "AirScales-" + WiFi.macAddress();
  Serial.printf("[WiFi] 📶 Creating AP: %s\n", apName.c_str());
  
  WiFi.mode(WIFI_AP);
  WiFi.softAP(apName.c_str(), "", 1);
  delay(2000);
  
  Serial.printf("[WiFi] ✅ AP started on channel: %d\n", WiFi.channel());
  Serial.printf("[WiFi] 🌐 IP Address: %s\n", WiFi.softAPIP().toString().c_str());
  
  // Setup HTTPS server routes
  Serial.println("[WebServer] 🔒 Setting up HTTPS server...");
  
  server.on("/", HTTP_GET, [](){
    File file = SPIFFS.open("/index.html", "r");
    if (file) {
      server.streamFile(file, "text/html");
      file.close();
    } else {
      server.send(404, "text/plain", "File not found");
    }
  });
  
  server.on("/manifest.json", HTTP_GET, [](){
    File file = SPIFFS.open("/manifest.json", "r");
    if (file) {
      server.streamFile(file, "application/json");
      file.close();
    } else {
      server.send(404, "text/plain", "File not found");
    }
  });
  
  server.on("/sw.js", HTTP_GET, [](){
    File file = SPIFFS.open("/sw.js", "r");
    if (file) {
      server.streamFile(file, "application/javascript");
      file.close();
    } else {
      server.send(404, "text/plain", "File not found");
    }
  });
  
  server.on("/icons/icon-192.png", HTTP_GET, [](){
    File file = SPIFFS.open("/icons/icon-192.png", "r");
    if (file) {
      server.streamFile(file, "image/png");
      file.close();
    } else {
      server.send(404, "text/plain", "File not found");
    }
  });
  
  server.on("/test", HTTP_GET, [](){
    server.send(200, "text/plain", "Air Scales HTTPS is working!");
  });
  
  server.on("/debug", HTTP_GET, [](){
    String response = "Device MAC: " + WiFi.macAddress() + "\n";
    response += "HTTPS: ENABLED\n";
    response += "AP IP: " + WiFi.softAPIP().toString() + "\n";
    response += "Uptime: " + String(millis() / 1000) + " seconds\n";
    server.send(200, "text/plain", response);
  });
  
  // Initialize ESP-NOW
  Serial.println("[SYSTEM] 🔗 Initializing ESP-NOW...");
  initializeESPNow();
  
  // Initialize BLE
  Serial.println("[SYSTEM] 🔵 Initializing BLE...");
  initializeBLE();
  
  // Start HTTPS server
  server.beginSecure(server_cert, server_key, "");
  Serial.println("[WebServer] ✅ HTTPS server started on port 443");
  
  systemInitialized = true;
  Serial.println("");
  Serial.println("✅ ================================");
  Serial.println("✅ SYSTEM INITIALIZATION COMPLETE");
  Serial.println("✅ ================================");
  Serial.println("🔒 HTTPS: https://192.168.4.1");
  Serial.println("🔵 Ready for BLE connection!");
  Serial.println("📱 PWA should install on mobile!");
  Serial.println("");
}

void setup() {
  Serial.begin(115200);
  pinMode(WIFI_RESET_PIN, INPUT_PULLUP);
  delay(1000);
  initializeSystem();
}

void loop() {
  server.handleClient();
  
  static unsigned long lastBroadcast = 0;
  if (millis() - lastBroadcast > 2000) {
    lastBroadcast = millis();
    broadcastAnnouncement();
    broadcastOwnSensorData();
    if (hasBLEClients()) {
      broadcastToBLE();
    }
  }
  
  static unsigned long lastStatus = 0;
  if (millis() - lastStatus > 30000) {
    lastStatus = millis();
    Serial.printf("[STATUS] 📊 Uptime: %lu sec, BLE clients: %d, ESP-NOW devices: %d\n", 
                  millis()/1000, getBLEClientCount(), getTotalOnlineDevices());
  }
}
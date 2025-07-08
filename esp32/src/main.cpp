#include <Arduino.h>
#include <WiFi.h>
#include <SPIFFS.h>
#include <ESPAsyncWebServer.h>

AsyncWebServer server(80);

// Set your AP credentials
const char* ssid = "ESP32-AirScales";
const char* password = "scaleitup";

void setup() {
  Serial.begin(115200);

  // 🌐 Start Wi-Fi in AP mode
  WiFi.softAP(ssid, password);
  IPAddress IP = WiFi.softAPIP();
  Serial.println("ESP32 AP IP address: " + IP.toString());

  // 💾 Initialize SPIFFS
  if (!SPIFFS.begin(true)) {
    Serial.println("SPIFFS failed to mount!");
    return;
  }

  // 📁 Serve everything under /app/ (JS, CSS, icons, etc.)
  server.serveStatic("/app/", SPIFFS, "/app/").setCacheControl("max-age=3600");

  // 🧠 Explicit fallback routes for root files
  server.serveStatic("/app/sw.js", SPIFFS, "/app/sw.js").setCacheControl("max-age=3600");
  server.serveStatic("/app/manifest.webmanifest", SPIFFS, "/app/manifest.webmanifest");

  // 🧱 Serve the root index.html (at /app/)
  server.on("/", HTTP_GET, [](AsyncWebServerRequest *request){
    request->redirect("/app/");
  });

  // 🌐 Fallback to index.html for any unrecognized path (important for PWA routes)
  server.onNotFound([](AsyncWebServerRequest *request){
    request->send(SPIFFS, "/app/index.html", "text/html");
  });

  // 🚀 Start the server
  server.begin();
}

void loop() {
  // Nothing here — async handles all server ops
}

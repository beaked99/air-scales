#include <WiFi.h>
#include <HTTPSServer.hpp>
#include <SSLCert.hpp>
#include <HTTPRequest.hpp>
#include <HTTPResponse.hpp>
#include <SPIFFS.h>
#include <FS.h>
#include "server_cert_der.h"
#include "server_key_der.h"

using namespace httpsserver;

SSLCert cert(
  server_cert_der, server_cert_der_len,
  server_key_der,  server_key_der_len
);

HTTPSServer secureServer = HTTPSServer(&cert, 443);

void handleApiTest(HTTPRequest * req, HTTPResponse * res) {
  res->setHeader("Content-Type", "text/plain");
  res->print("Hello from AirScales HTTPS API! Secure connection established.");
}

void handleApiDevice(HTTPRequest * req, HTTPResponse * res) {
  String macAddress = WiFi.macAddress();
  macAddress.replace(":", "");
  String deviceName = "AirScales-" + macAddress;

  String jsonResponse = "{";
  jsonResponse += "\"name\":\"" + deviceName + "\",";
  jsonResponse += "\"mac\":\"" + WiFi.macAddress() + "\",";
  jsonResponse += "\"ip\":\"" + WiFi.softAPIP().toString() + "\",";
  jsonResponse += "\"uptime\":" + String(millis() / 1000);
  jsonResponse += "}";

  res->setHeader("Content-Type", "application/json");
  res->print(jsonResponse);
}

void handle404(HTTPRequest * req, HTTPResponse * res) {
  req->discardRequestBody();
  res->setStatusCode(404);
  res->setStatusText("Not Found");
  res->setHeader("Content-Type", "text/html");
  res->print("<!DOCTYPE html><html><head><title>Not Found</title></head>");
  res->print("<body><h1>404 Not Found</h1><p>The requested resource was not found.</p></body></html>");
}

void setup() {
  Serial.begin(115200);
  delay(1000);

  String macAddress = WiFi.macAddress();
  macAddress.replace(":", "");
  String apName = "AirScales-" + macAddress;

  Serial.println("========================================");
  Serial.println("       AirScales HTTPS PWA Server      ");
  Serial.println("========================================");

  Serial.println("Creating Access Point...");
  Serial.print("AP Name: ");
  Serial.println(apName);

  WiFi.softAP(apName.c_str());

  Serial.println("Access Point created successfully!");
  Serial.print("AP IP address: ");
  Serial.println(WiFi.softAPIP());
  Serial.print("AP MAC address: ");
  Serial.println(WiFi.softAPmacAddress());

  if (!SPIFFS.begin()) {
    Serial.println("SPIFFS mount failed");
    return;
  }

  ResourceNode * staticApp = new ResourceNode("/app/", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    String path = String(req->getRequestString().c_str());
    if (path == "/app/") path = "/app/index.html";

    File file = SPIFFS.open(path, "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("File not found");
      return;
    }

    if (path.endsWith(".html")) res->setHeader("Content-Type", "text/html");
    else if (path.endsWith(".css")) res->setHeader("Content-Type", "text/css");
    else if (path.endsWith(".js")) res->setHeader("Content-Type", "application/javascript");
    else if (path.endsWith(".json")) res->setHeader("Content-Type", "application/manifest+json");
    else if (path.endsWith(".ico")) res->setHeader("Content-Type", "image/x-icon");

    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  
  ResourceNode * nodeApiTest = new ResourceNode("/api/test", "GET", &handleApiTest);
  ResourceNode * nodeApiDevice = new ResourceNode("/api/device", "GET", &handleApiDevice);
  ResourceNode * node404 = new ResourceNode("", "GET", &handle404);

  secureServer.registerNode(staticApp);

  // Serve sw.js directly from /app/sw.js
  ResourceNode * nodeServiceWorker = new ResourceNode("/app/sw.js", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    File file = SPIFFS.open("/app/sw.js", "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("Service Worker not found");
      return;
    }
    res->setHeader("Content-Type", "application/javascript");
    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  secureServer.registerNode(nodeServiceWorker);

  // Serve manifest.json from /app/manifest.json
  ResourceNode * nodeManifest = new ResourceNode("/app/manifest.json", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    File file = SPIFFS.open("/app/manifest.json", "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("Manifest not found");
      return;
    }
    res->setHeader("Content-Type", "application/manifest+json");
    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  secureServer.registerNode(nodeManifest);

  // Serve icon-192.png
  ResourceNode * nodeIcon192 = new ResourceNode("/app/icon-192.png", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    File file = SPIFFS.open("/app/icon-192.png", "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("Icon 192 not found");
      return;
    }
    res->setHeader("Content-Type", "image/png");
    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  secureServer.registerNode(nodeIcon192);

  // Serve icon-512.png
  ResourceNode * nodeIcon512 = new ResourceNode("/app/icon-512.png", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    File file = SPIFFS.open("/app/icon-512.png", "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("Icon 512 not found");
      return;
    }
    res->setHeader("Content-Type", "image/png");
    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  secureServer.registerNode(nodeIcon512);

  // Serve favicon.ico
  ResourceNode * nodeFavicon = new ResourceNode("/app/favicon.ico", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    File file = SPIFFS.open("/app/favicon.ico", "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("Favicon not found");
      return;
    }
    res->setHeader("Content-Type", "image/x-icon");
    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  secureServer.registerNode(nodeFavicon);

  // Serve style.css
  ResourceNode * nodeStyle = new ResourceNode("/app/style.css", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    File file = SPIFFS.open("/app/style.css", "r");
    if (!file) {
      res->setStatusCode(404);
      res->print("Style not found");
      return;
    }
    res->setHeader("Content-Type", "text/css");
    while (file.available()) {
      res->write(file.read());
    }
    file.close();
  });
  secureServer.registerNode(nodeStyle);

  ResourceNode * staticAppRedirect = new ResourceNode("/app", "GET", [](HTTPRequest *req, HTTPResponse *res) {
    res->setStatusCode(302);
    res->setHeader("Location", "/app/");
    res->print("Redirecting to /app/");
  });
  secureServer.registerNode(staticAppRedirect);
  secureServer.registerNode(nodeApiTest);
  secureServer.registerNode(nodeApiDevice);
  secureServer.setDefaultNode(node404);

  Serial.println("Starting HTTPS server...");
  secureServer.start();

  if (secureServer.isRunning()) {
    Serial.println("HTTPS server started successfully!");
    Serial.println("========================================");
    Serial.println("To connect:");
    Serial.println("1. Connect to WiFi: " + apName);
    Serial.println("2. Visit: https://" + WiFi.softAPIP().toString() + "/app/");
    Serial.println("3. Accept security warning (self-signed cert)");
    Serial.println("4. Install PWA when prompted");
    Serial.println("========================================");
  } else {
    Serial.println("HTTPS server failed to start!");
  }
}

void loop() {
  secureServer.poll();
  delay(10);
}

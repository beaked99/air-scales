console.log('[PWA] ws.js loaded');

// Hardcoded fallback IP for ESP32 in AP mode
const DEFAULT_AP_IP = '192.168.4.1';
const WS_ENDPOINT = `ws://${DEFAULT_AP_IP}/ws`;

let socket = null;
const statusEl = document.getElementById('status');
const dataEl = document.getElementById('data');

function connectToESP32() {
  socket = new WebSocket(WS_ENDPOINT);

  socket.onopen = () => {
    console.log('[WS] Connected to ESP32');
    statusEl.textContent = 'Connected to ESP32';
  };

  socket.onmessage = event => {
    try {
      const data = JSON.parse(event.data);
      dataEl.innerHTML = `
        <pre>${JSON.stringify(data, null, 2)}</pre>
      `;
    } catch (e) {
      console.error('[WS] Error parsing message', e);
    }
  };

  socket.onerror = err => {
    console.error('[WS] Error', err);
    statusEl.textContent = 'Connection error.';
  };

  socket.onclose = () => {
    console.warn('[WS] Disconnected. Retrying in 3s...');
    statusEl.textContent = 'Disconnected. Reconnecting...';
    setTimeout(connectToESP32, 3000);
  };
}

connectToESP32();

// assets/pwa/index.js
console.log("[PWA] Loaded");

window.addEventListener("load", () => {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/pwa/sw.js')
      .then(reg => console.log("[PWA] Service worker registered:", reg))
      .catch(err => console.error("[PWA] Service worker registration failed:", err));
  }

  // Example WebSocket connection — customize this!
  const socket = new WebSocket('ws://192.168.4.1/ws');
  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    console.log("[PWA] Incoming data:", data);
    // TODO: update UI or store data
  };
});

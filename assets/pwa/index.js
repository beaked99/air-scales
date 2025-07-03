console.log("[PWA] Loaded");

window.addEventListener("load", () => {
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("/pwa/sw.js")
      .then(reg => console.log("[PWA] Service worker registered:", reg))
      .catch(err => console.error("[PWA] Service worker registration failed:", err));
  }

  // Determine the correct WebSocket URL
  let protocol = window.location.protocol === "https:" ? "wss" : "ws";
  let host = window.location.hostname;
  let wsUrl = `${protocol}://${host}/ws`;

  console.log("[PWA] Connecting to WebSocket:", wsUrl);

  const socket = new WebSocket(wsUrl);
  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    console.log("[PWA] Incoming data:", data);
    // TODO: update UI or store data
  };
});

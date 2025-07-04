console.log('[PWA] ws.js loaded');

const chartCtx = document.getElementById('weightChart').getContext('2d');
let chartData = {
  labels: [],
  datasets: [{
    label: 'Weight (kg)',
    data: [],
    borderWidth: 2,
    fill: false,
    tension: 0.1
  }]
};
let chart = new Chart(chartCtx, {
  type: 'line',
  data: chartData,
  options: {
    scales: {
      x: { display: false },
      y: { beginAtZero: true }
    }
  }
});

function updateChart(data) {
  document.getElementById('connecting').style.display = 'none';
  document.getElementById('weight').innerText = data.weight + ' kg';

  chart.data.labels.push(new Date().toLocaleTimeString());
  chart.data.datasets[0].data.push(data.weight);
  if (chart.data.labels.length > 30) {
    chart.data.labels.shift();
    chart.data.datasets[0].data.shift();
  }
  chart.update();
}

function tryFetchFallback() {
  fetch('http://192.168.4.1/data')
    .then(r => r.json())
    .then(updateChart)
    .catch(() => {
      console.log('[Fetch] No data at 192.168.4.1/data');
    });
}

function connectWebSocket() {
  let host = location.hostname.startsWith('192.168') 
    ? 'ws://192.168.4.1/ws'
    : 'wss://beaker.ca/ws';

  try {
    const ws = new WebSocket(host);
    ws.onopen = () => console.log('[WebSocket] Connected to', host);
    ws.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        updateChart(data);
      } catch (err) {
        console.warn('[WebSocket] Invalid JSON', err);
      }
    };
    ws.onerror = (err) => {
      console.error('[WebSocket] Error:', err);
      tryFetchFallback();
      setTimeout(connectWebSocket, 5000);
    };
    ws.onclose = () => {
      console.warn('[WebSocket] Closed, retrying…');
      setTimeout(connectWebSocket, 5000);
    };
  } catch (e) {
    console.error('[WebSocket] Init failed', e);
    tryFetchFallback();
  }
}

connectWebSocket();

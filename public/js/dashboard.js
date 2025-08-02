// Dashboard Live Updates - Step 1: Basic Setup
// File: public/js/dashboard.js

let updateInterval;
let isUpdating = false;

// Configuration
const CONFIG = {
    updateIntervalMs: 5000, // 5 seconds
    debug: true
};

// API URL will be set from Twig template
let API_URL = null;

// Initialize API URL from Twig template
function initializeApiUrl(url) {
    API_URL = url;
    if (CONFIG.debug) console.log('API URL set to:', API_URL);
}

// Basic API connection test
async function fetchLiveData() {
    if (!API_URL) {
        console.error('API URL not set');
        return null;
    }

    try {
        if (CONFIG.debug) console.log('Fetching live data...');
        
        const response = await fetch(API_URL);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        
        if (CONFIG.debug) {
            console.log('Live data received:', data);
            console.log(`Devices: ${data.devices.length}, Total weight: ${data.total_weight} lbs`);
        }
        
        return data;
        
    } catch (error) {
        console.error('API Error:', error);
        return null;
    }
}

// Start the update cycle
function startUpdates() {
    if (isUpdating) return;
    
    isUpdating = true;
    console.log(`Starting live updates every ${CONFIG.updateIntervalMs / 1000} seconds`);
    
    // Initial fetch
    fetchLiveData();
    
    // Set up interval
    updateInterval = setInterval(fetchLiveData, CONFIG.updateIntervalMs);
}

// Stop updates
function stopUpdates() {
    if (updateInterval) {
        clearInterval(updateInterval);
        updateInterval = null;
    }
    isUpdating = false;
    console.log('Stopped live updates');
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('Dashboard JavaScript loaded');
    
    // Wait a bit for API URL to be set
    setTimeout(() => {
        if (API_URL) {
            startUpdates();
        } else {
            console.error('API URL not initialized');
        }
    }, 100);
});

// Clean up when page unloads
window.addEventListener('beforeunload', function() {
    stopUpdates();
});

// Expose functions for debugging
window.DashboardDebug = {
    start: startUpdates,
    stop: stopUpdates,
    fetch: fetchLiveData,
    config: CONFIG,
    status: () => ({
        isUpdating,
        apiUrl: API_URL,
        intervalId: updateInterval
    })
};
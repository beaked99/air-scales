// Dashboard Live Updates - Step 2: DOM Updates
// File: public/js/dashboard.js

let updateInterval;
let isUpdating = false;

// Configuration
const CONFIG = {
    updateIntervalMs: 5000, // 5 seconds
    debug: true,
    maxRetries: 3,
    retryDelay: 2000 // 2 seconds
};

// API URL will be set from Twig template
let API_URL = null;
let retryCount = 0;

// Initialize API URL from Twig template
function initializeApiUrl(url) {
    API_URL = url;
    if (CONFIG.debug) console.log('API URL set to:', API_URL);
}

// Enhanced API connection with DOM updates
async function fetchLiveData() {
    if (!API_URL) {
        console.error('API URL not set');
        return null;
    }

    try {
        if (CONFIG.debug) console.log(`Fetching live data from: ${API_URL}`);
        
        const response = await fetch(API_URL, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('HTTP Error:', {
                status: response.status,
                statusText: response.statusText,
                body: errorText
            });
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        
        // Reset retry count on successful request
        retryCount = 0;
        
        if (CONFIG.debug) {
            console.log('✅ Live data received:', data);
            console.log(`📊 Total weight: ${data.total_weight} lbs, Devices: ${data.devices.length}`);
        }
        
        // 🆕 UPDATE THE DOM WITH LIVE DATA
        updateDashboardWithLiveData(data);
        
        return data;
        
    } catch (error) {
        retryCount++;
        console.error(`❌ API Error (attempt ${retryCount}/${CONFIG.maxRetries}):`, error.message);
        
        if (retryCount < CONFIG.maxRetries) {
            console.log(`🔄 Retrying in ${CONFIG.retryDelay / 1000} seconds...`);
            setTimeout(fetchLiveData, CONFIG.retryDelay);
        }
        
        return null;
    }
}

// 🆕 UPDATE DOM ELEMENTS WITH LIVE DATA
function updateDashboardWithLiveData(data) {
    if (CONFIG.debug) console.log('🔄 Updating dashboard DOM with live data...');
    
    // Update each device
    data.devices.forEach(device => {
        updateDeviceDisplay(device);
    });
    
    // Update total weight
    updateTotalWeight(data.total_weight, data.device_count);
    
    if (CONFIG.debug) console.log('✅ Dashboard DOM updated successfully');
}

// 🆕 UPDATE INDIVIDUAL DEVICE DISPLAY
function updateDeviceDisplay(device) {
    const deviceElement = document.querySelector(`[data-device-id="${device.device_id}"]`);
    if (!deviceElement) {
        if (CONFIG.debug) console.warn(`Device element not found for ID: ${device.device_id}`);
        return;
    }
    
    if (CONFIG.debug) {
        console.log(`🔧 Updating device ${device.device_name}:`, {
            status: device.status,
            weight: device.weight,
            lastSeen: device.last_seen
        });
    }
    
    // Update connection status dot
    updateConnectionStatusDot(deviceElement, device.status);
    
    // Update last seen text
    updateLastSeenText(deviceElement, device.last_seen, device.status);
    
    // Update weight and pressure
    updateWeightAndPressure(deviceElement, device);
}

// 🆕 UPDATE CONNECTION STATUS DOT (GREEN/ORANGE/RED CIRCLE)
function updateConnectionStatusDot(deviceElement, status) {
    const dotElement = deviceElement.parentElement.querySelector('.w-3.h-3.rounded-full');
    if (!dotElement) return;
    
    // Remove all existing status classes
    dotElement.classList.remove(
        'bg-green-400', 'animate-pulse',
        'bg-orange-400', 
        'bg-red-500',
        'bg-purple-500',
        'bg-gray-500'
    );
    
    // Add new status classes based on API status
    switch(status) {
        case 'online':
            dotElement.classList.add('bg-green-400', 'animate-pulse');
            break;
        case 'recent':
            dotElement.classList.add('bg-orange-400');
            break;
        case 'offline':
        default:
            dotElement.classList.add('bg-red-500');
            break;
    }
    
    if (CONFIG.debug) console.log(`🔴 Updated status dot for device to: ${status}`);
}

// 🆕 UPDATE LAST SEEN TEXT
function updateLastSeenText(deviceElement, lastSeen, status) {
    const textElement = deviceElement.querySelector('.text-sm');
    if (!textElement) return;
    
    // Update text content
    textElement.textContent = lastSeen;
    
    // Remove all existing color classes
    textElement.classList.remove(
        'text-green-400',
        'text-orange-400', 
        'text-red-500',
        'text-purple-400',
        'text-gray-500'
    );
    
    // Add new color class based on status
    switch(status) {
        case 'online':
            textElement.classList.add('text-green-400');
            break;
        case 'recent':
            textElement.classList.add('text-orange-400');
            break;
        case 'offline':
            textElement.classList.add('text-red-500');
            break;
        case 'old':
        default:
            textElement.classList.add('text-gray-500');
            break;
    }
    
    if (CONFIG.debug) console.log(`📝 Updated last seen text: "${lastSeen}" with status: ${status}`);
}

// 🆕 UPDATE WEIGHT AND PRESSURE
function updateWeightAndPressure(deviceElement, device) {
    const deviceContainer = deviceElement.closest('.flex.items-center.justify-between.p-4');
    if (!deviceContainer) return;
    
    const weightElement = deviceContainer.querySelector('.text-right .font-bold');
    const pressureElement = deviceContainer.querySelector('.text-right .text-sm.text-gray-400');
    
    if (weightElement) {
        weightElement.textContent = `${Math.round(device.weight).toLocaleString()} lbs`;
        if (CONFIG.debug) console.log(`⚖️ Updated weight: ${device.weight} lbs`);
    }
    
    if (pressureElement) {
        pressureElement.textContent = `${device.main_air_pressure.toFixed(1)} psi`;
        if (CONFIG.debug) console.log(`🫁 Updated pressure: ${device.main_air_pressure} psi`);
    }
}

// 🆕 UPDATE TOTAL WEIGHT
function updateTotalWeight(totalWeight, deviceCount) {
    const totalWeightElement = document.querySelector('.text-5xl.font-bold.text-white');
    if (totalWeightElement) {
        totalWeightElement.textContent = Math.round(totalWeight).toLocaleString();
        if (CONFIG.debug) console.log(`🏋️ Updated total weight: ${totalWeight} lbs from ${deviceCount} devices`);
    }
}

// Start the update cycle
function startUpdates() {
    if (isUpdating) return;
    
    isUpdating = true;
    console.log(`🚀 Starting live updates every ${CONFIG.updateIntervalMs / 1000} seconds`);
    
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
    console.log('⏹️ Stopped live updates');
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('📄 Dashboard JavaScript loaded');
    
    // Wait a bit for API URL to be set
    setTimeout(() => {
        if (API_URL) {
            console.log('🔗 Starting live DOM updates...');
            startUpdates();
        } else {
            console.error('❌ API URL not initialized');
        }
    }, 100);
});

// Clean up when page unloads
window.addEventListener('beforeunload', function() {
    stopUpdates();
});

// Enhanced debugging functions
window.DashboardDebug = {
    start: startUpdates,
    stop: stopUpdates,
    fetch: fetchLiveData,
    config: CONFIG,
    status: () => ({
        isUpdating,
        apiUrl: API_URL,
        intervalId: updateInterval,
        retryCount
    }),
    // Manual DOM update test
    testDomUpdate: async () => {
        console.log('🧪 Testing DOM update...');
        const data = await fetchLiveData();
        if (data) {
            updateDashboardWithLiveData(data);
        }
    }
};
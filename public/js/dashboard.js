// Dashboard Live Updates + Bluetooth Device Discovery
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

// BLUETOOTH DEVICE DISCOVERY FUNCTIONS
function initializeDeviceScanning() {
    const scanBtn = document.getElementById('scan-devices-btn');
    if (scanBtn) {
        scanBtn.addEventListener('click', scanForBluetoothDevices);
        console.log('🔍 Device scanning initialized');
    }
}

async function scanForBluetoothDevices() {
    console.log('🔍 Starting Bluetooth scan for Air Scales devices...');
    
    const scanBtn = document.getElementById('scan-devices-btn');
    const scanningIndicator = document.getElementById('scanning-indicator');
    const discoveredDevices = document.getElementById('discovered-devices');
    const noDevicesFound = document.getElementById('no-devices-found');
    const devicesList = document.getElementById('discovered-devices-list');
    
    // Check if Web Bluetooth is supported
    if (!navigator.bluetooth) {
        showBluetoothError('Bluetooth is not supported in this browser. Please use Chrome, Edge, or another Chromium-based browser.');
        return;
    }
    
    try {
        // Disable scan button and show scanning indicator
        scanBtn.disabled = true;
        scanBtn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> <span>Scanning...</span>';
        scanningIndicator.classList.remove('hidden');
        discoveredDevices.classList.add('hidden');
        noDevicesFound.classList.add('hidden');
        
        // Clear previous results
        devicesList.innerHTML = '';
        
        // Use the newer scan API for multiple devices and RSSI
        const devices = [];
        
        try {
            // Method 1: Try experimental scan API for multiple devices
            const scan = await navigator.bluetooth.requestLEScan({
                filters: [
                    { namePrefix: 'AirScales' },
                    { namePrefix: 'AS25-' },
                    { namePrefix: 'ESP32' }
                ]
            });
            
            // Listen for advertisements
            let scanTimeout;
            const foundDevices = new Map();
            
            navigator.bluetooth.addEventListener('advertisementreceived', event => {
                const device = event.device;
                const rssi = event.rssi;
                
                console.log(`📡 Found device: ${device.name} (RSSI: ${rssi} dBm)`);
                
                // Update or add device with signal strength
                foundDevices.set(device.id, {
                    device: device,
                    rssi: rssi,
                    lastSeen: Date.now()
                });
                
                // Update UI immediately
                displayDiscoveredDevicesWithSignal(Array.from(foundDevices.values()));
            });
            
            // Scan for 10 seconds
            scanTimeout = setTimeout(() => {
                scan.stop();
                console.log(`🔍 Scan completed. Found ${foundDevices.size} devices`);
                
                if (foundDevices.size === 0) {
                    noDevicesFound.classList.remove('hidden');
                }
            }, 10000);
            
        } catch (scanError) {
            console.log('⚠️ Advanced scan not available, falling back to single device selection');
            
            // Fallback: Single device selection (no RSSI available)
            const device = await navigator.bluetooth.requestDevice({
                filters: [
                    { namePrefix: 'AirScales' },
                    { namePrefix: 'AS25-' },
                    { namePrefix: 'ESP32' }
                ],
                optionalServices: [
                    'battery_service',
                    '12345678-1234-1234-1234-1234567890ab'
                ]
            });
            
            console.log('✅ Found device (no RSSI):', device.name, device.id);
            
            // Show single device without signal strength
            displayDiscoveredDevicesWithSignal([{
                device: device,
                rssi: null,
                lastSeen: Date.now()
            }]);
        }
        
    } catch (error) {
        console.log('❌ Bluetooth scan error:', error);
        
        // Hide scanning indicator
        scanningIndicator.classList.add('hidden');
        
        if (error.name === 'NotFoundError') {
            noDevicesFound.classList.remove('hidden');
        } else {
            showBluetoothError(`Bluetooth error: ${error.message}`);
        }
    } finally {
        // Hide scanning indicator
        scanningIndicator.classList.add('hidden');
        
        // Re-enable scan button
        scanBtn.disabled = false;
        scanBtn.innerHTML = '<i class="fas fa-bluetooth-b"></i> <span>Scan for Devices</span>';
    }
}

function displayDiscoveredDevicesWithSignal(deviceData) {
    const discoveredDevices = document.getElementById('discovered-devices');
    const devicesList = document.getElementById('discovered-devices-list');
    
    // Show discovered devices section
    discoveredDevices.classList.remove('hidden');
    
    // Sort devices by signal strength (strongest first)
    deviceData.sort((a, b) => {
        if (a.rssi === null && b.rssi === null) return 0;
        if (a.rssi === null) return 1;
        if (b.rssi === null) return -1;
        return b.rssi - a.rssi; // Higher RSSI = stronger signal
    });
    
    // Clear existing devices
    devicesList.innerHTML = '';
    
    deviceData.forEach((item, index) => {
        const { device, rssi } = item;
        
        // Determine signal strength category and styling
        const signalInfo = getSignalStrengthInfo(rssi);
        
        // Create device card with signal strength
        const deviceCard = document.createElement('div');
        deviceCard.className = `p-3 border rounded-lg transition-all ${signalInfo.cardClass}`;
        
        // Add "CLOSEST" badge for strongest signal
        const closestBadge = index === 0 && rssi !== null ? 
            '<span class="px-2 py-1 text-xs font-bold text-white bg-green-500 rounded-full">CLOSEST</span>' : '';
        
        deviceCard.innerHTML = `
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-3 h-3 ${signalInfo.dotClass} rounded-full ${signalInfo.animation}"></div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-white">${device.name || 'Air Scales Device'}</span>
                            ${closestBadge}
                        </div>
                        <div class="text-sm ${signalInfo.textClass}">
                            ${rssi !== null ? 
                                `Signal: ${rssi} dBm (${signalInfo.label})` : 
                                'Bluetooth ID: ' + device.id
                            }
                        </div>
                        ${rssi !== null ? 
                            `<div class="text-xs text-gray-400">ID: ${device.id}</div>` : 
                            ''
                        }
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    ${rssi !== null ? `
                    <div class="flex items-center gap-1">
                        <span class="text-xs font-mono">${signalInfo.bars}</span>
                    </div>
                    ` : ''}
                    <button class="connect-device-btn px-3 py-1 text-sm text-white transition-colors rounded bg-green-600 hover:bg-green-700" 
                            data-device-name="${device.name}" 
                            data-device-id="${device.id}"
                            data-signal="${rssi}">
                        Connect
                    </button>
                </div>
            </div>
        `;
        
        // Add connect button handler
        const connectBtn = deviceCard.querySelector('.connect-device-btn');
        connectBtn.addEventListener('click', () => connectToDevice(device, rssi));
        
        devicesList.appendChild(deviceCard);
    });
    
    console.log(`📱 Displayed ${deviceData.length} devices with signal strength`);
}

function getSignalStrengthInfo(rssi) {
    if (rssi === null) {
        return {
            label: 'Unknown',
            cardClass: 'border-gray-600 bg-gray-600/20',
            dotClass: 'bg-gray-400',
            textClass: 'text-gray-300',
            animation: '',
            bars: ''
        };
    }
    
    // RSSI interpretation for Bluetooth LE:
    // > -40 dBm: Excellent (very close, < 3 feet)
    // -40 to -55 dBm: Good (close, 3-15 feet) 
    // -55 to -70 dBm: Fair (medium distance, 15-30 feet)
    // < -70 dBm: Poor (far away, > 30 feet)
    
    if (rssi > -40) {
        return {
            label: 'Excellent - Very Close',
            cardClass: 'border-green-500 bg-green-500/20',
            dotClass: 'bg-green-400',
            textClass: 'text-green-300',
            animation: 'animate-pulse',
            bars: '█████'
        };
    } else if (rssi > -55) {
        return {
            label: 'Good - Close',
            cardClass: 'border-sky-500 bg-sky-500/20',
            dotClass: 'bg-sky-400',
            textClass: 'text-sky-300',
            animation: 'animate-pulse',
            bars: '████░'
        };
    } else if (rssi > -70) {
        return {
            label: 'Fair - Medium Distance',
            cardClass: 'border-orange-500 bg-orange-500/20',
            dotClass: 'bg-orange-400',
            textClass: 'text-orange-300',
            animation: '',
            bars: '███░░'
        };
    } else {
        return {
            label: 'Poor - Far Away',
            cardClass: 'border-red-500 bg-red-500/20',
            dotClass: 'bg-red-400',
            textClass: 'text-red-300',
            animation: '',
            bars: '██░░░'
        };
    }
}

async function connectToDevice(device, rssi) {
    console.log('🔗 Connecting to device:', device.name, 'Signal:', rssi);
    
    const connectBtn = event.target;
    const originalText = connectBtn.innerHTML;
    
    try {
        // Show connecting state
        connectBtn.disabled = true;
        connectBtn.innerHTML = '<i class="fas fa-spinner animate-spin"></i> Connecting...';
        
        // Connect to the Bluetooth device
        await device.gatt.connect();
        console.log('✅ Bluetooth GATT connected');
        
        // Get current user ID
        const userId = await getCurrentUserId();
        if (!userId) {
            throw new Error('User not authenticated');
        }
        
        // Call your bridge API to register the connection
        const response = await fetch('/api/bridge/connect', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                mac_address: device.id, // Use Bluetooth ID as MAC for now
                user_id: userId,
                signal_strength: rssi, // Include signal strength
                device_name: device.name
            })
        });
        
        const result = await response.json();
        
        if (result.status === 'connected') {
            console.log('✅ Device connected via Bridge API');
            
            // Show success with signal info
            connectBtn.className = 'px-3 py-1 text-sm text-white bg-green-600 rounded';
            connectBtn.innerHTML = '✓ Connected';
            
            // Show success toast with signal strength
            showSuccessToast(`Connected to ${device.name}${rssi ? ` (${rssi} dBm)` : ''}`);
            
            // Refresh the dashboard to show the new device
            setTimeout(() => {
                location.reload();
            }, 1500);
            
        } else {
            throw new Error(result.error || 'Connection failed');
        }
        
    } catch (error) {
        console.error('❌ Connection error:', error);
        
        // Show error state
        connectBtn.className = 'px-3 py-1 text-sm text-white bg-red-600 rounded';
        connectBtn.innerHTML = 'Failed';
        
        // Reset after delay
        setTimeout(() => {
            connectBtn.disabled = false;
            connectBtn.className = 'px-3 py-1 text-sm text-white transition-colors bg-green-600 rounded connect-device-btn hover:bg-green-700';
            connectBtn.innerHTML = originalText;
        }, 3000);
        
        showBluetoothError(`Failed to connect: ${error.message}`);
    }
}

// Get current user ID via API
async function getCurrentUserId() {
    try {
        const response = await fetch('/api/current-user', {
            method: 'GET',
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error('Not authenticated');
        }
        
        const userData = await response.json();
        return userData.id;
        
    } catch (error) {
        console.error('Failed to get current user:', error);
        return null;
    }
}

// Utility functions for UI feedback
function showBluetoothError(message) {
    // Create toast notification
    const toast = document.createElement('div');
    toast.className = 'fixed z-50 p-4 text-white bg-red-600 rounded-lg shadow-lg top-4 right-4';
    toast.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Remove after 5 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 5000);
}

function showSuccessToast(message) {
    const toast = document.createElement('div');
    toast.className = 'fixed z-50 p-4 text-white bg-green-600 rounded-lg shadow-lg top-4 right-4';
    toast.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 5000);
}

// Initialize when page loads (Turbo-compatible)
document.addEventListener('turbo:load', function() {
    console.log('📄 Dashboard JavaScript loaded via Turbo');
    
    // Initialize device scanning
    initializeDeviceScanning();
    
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

// Fallback for non-Turbo environments
document.addEventListener('DOMContentLoaded', function() {
    console.log('📄 Dashboard JavaScript loaded via DOMContentLoaded');
    
    // Initialize device scanning
    initializeDeviceScanning();
    
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

// Enhanced debugging functions (matching dashboard pattern)
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
    },
    // Test Bluetooth scanning
    testBluetooth: () => {
        console.log('🧪 Testing Bluetooth...');
        console.log('Bluetooth supported:', !!navigator.bluetooth);
        if (navigator.bluetooth) {
            scanForBluetoothDevices();
        }
    }
};
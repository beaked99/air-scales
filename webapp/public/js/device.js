// Device Detail Page JavaScript - Enhanced Live Updates

let deviceApiUrl = null;
let deviceId = null;
let updateInterval = null;
let isUpdating = false;
let retryCount = 0;

// Configuration (matching dashboard pattern)
const CONFIG = {
    updateIntervalMs: 5000, // 5 seconds (same as dashboard)
    debug: true,
    maxRetries: 3,
    retryDelay: 2000 // 2 seconds
};

// BLE tracking
let deviceMacAddress = null;
let bleListenerRemover = null;
let isReceivingBLEData = false;
let lastBLEDataTime = 0;
let lastServerSync = 0; // Track last time we synced to server

function initializeDeviceDetailApi(apiUrl, id, macAddress = null) {
    deviceApiUrl = apiUrl;
    deviceId = id;
    deviceMacAddress = macAddress ? macAddress.toUpperCase() : null;

    if (CONFIG.debug) {
        console.log('Device Detail API URL set to:', deviceApiUrl);
        console.log('Device MAC Address:', deviceMacAddress);
    }

    // Set up BLE listeners first (priority)
    setupBLEListener();

    // Start live data updates (fallback when no BLE)
    startLiveDataUpdates();

    // Initialize event listeners
    initializeEventListeners();
}

function startLiveDataUpdates() {
    if (isUpdating) return;
    
    isUpdating = true;
    console.log(`🚀 Starting device live updates every ${CONFIG.updateIntervalMs / 1000} seconds`);
    
    // Initial fetch
    updateLiveData();
    
    // Set up interval (same frequency as dashboard)
    updateInterval = setInterval(updateLiveData, CONFIG.updateIntervalMs);
}

function stopLiveDataUpdates() {
    if (updateInterval) {
        clearInterval(updateInterval);
        updateInterval = null;
    }
    isUpdating = false;
    console.log('⏹️ Stopped device live updates');
}

async function updateLiveData() {
    if (!deviceApiUrl) {
        console.error('Device API URL not set');
        return;
    }

    // Skip server polling if we're receiving fresh BLE data
    const timeSinceLastBLE = Date.now() - lastBLEDataTime;
    if (isReceivingBLEData && timeSinceLastBLE < 10000) {
        if (CONFIG.debug) console.log('⏭️ Skipping server update - receiving live BLE data');
        return;
    }

    try {
        if (CONFIG.debug) console.log(`Fetching device live data from: ${deviceApiUrl}`);

        const response = await fetch(deviceApiUrl, {
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
            console.log('✅ Device live data received from server:', data);
            console.log(`📊 Weight: ${data.weight} lbs, Pressure: ${data.main_air_pressure} psi`);
        }

        // Update the DOM with live data
        updateDeviceDisplayWithLiveData(data, 'server');

    } catch (error) {
        retryCount++;
        console.error(`❌ Device API Error (attempt ${retryCount}/${CONFIG.maxRetries}):`, error.message);

        if (retryCount < CONFIG.maxRetries) {
            console.log(`🔄 Retrying in ${CONFIG.retryDelay / 1000} seconds...`);
            setTimeout(updateLiveData, CONFIG.retryDelay);
        } else {
            console.error('❌ Max retries reached, stopping updates');
            stopLiveDataUpdates();
        }
    }
}

// Enhanced DOM update function (matching dashboard pattern)
function updateDeviceDisplayWithLiveData(data, source = 'unknown') {
    if (CONFIG.debug) console.log(`🔄 Updating device detail DOM with live data from ${source}...`);

    // Update current readings section
    updateCurrentReadings(data);

    // Update last seen timestamp
    updateLastSeenTimestamp(data.last_seen || 'just now', source);

    // Update connection status in bluetooth section
    updateConnectionStatus(data.connection_status || (source === 'bluetooth' ? 'connected' : 'recent'));

    if (CONFIG.debug) console.log('✅ Device detail DOM updated successfully');
}

// Update all sensor readings
function updateCurrentReadings(data) {
    // Update channel data if available (new format)
    if (data.channels && Array.isArray(data.channels)) {
        data.channels.forEach(channelData => {
            const channelIndex = channelData.channel_index;

            // Update weight
            const weightDisplay = document.getElementById(`channel-${channelIndex}-weight`);
            if (weightDisplay && channelData.weight !== null && channelData.weight !== undefined) {
                weightDisplay.textContent = `${Math.round(channelData.weight).toLocaleString()} lbs`;
            }

            // Update air pressure
            const pressureDisplay = document.getElementById(`channel-${channelIndex}-pressure`);
            if (pressureDisplay && channelData.air_pressure !== null && channelData.air_pressure !== undefined) {
                pressureDisplay.textContent = `${channelData.air_pressure.toFixed(1)} psi`;
            }
        });
    } else if (data.weight !== undefined || data.main_air_pressure !== undefined) {
        // Legacy single-channel format (backward compatibility)
        const weightDisplay = document.getElementById('channel-1-weight');
        if (weightDisplay && data.weight !== null && data.weight !== undefined) {
            weightDisplay.textContent = `${Math.round(data.weight).toLocaleString()} lbs`;
        }

        const pressureDisplay = document.getElementById('channel-1-pressure');
        if (pressureDisplay && data.main_air_pressure !== null && data.main_air_pressure !== undefined) {
            pressureDisplay.textContent = `${data.main_air_pressure.toFixed(1)} psi`;
        }
    }

    // Environmental data (shared across all channels)
    if (data.atmospheric_pressure !== null && data.atmospheric_pressure !== undefined) {
        updateElementWithClass('atmospheric-pressure-display', `${data.atmospheric_pressure.toFixed(1)} psi`);
    }

    if (data.temperature !== null && data.temperature !== undefined) {
        updateElementWithClass('temperature-display', `${Math.round(data.temperature)}°F`);
    }

    // GPS coordinates (from phone)
    if (data.gps_lat !== null && data.gps_lng !== null && data.gps_lat !== undefined && data.gps_lng !== undefined) {
        updateElementWithClass('gps-display', `${data.gps_lat.toFixed(3)}, ${data.gps_lng.toFixed(3)}`);
    }

    // Signal strength
    const signalText = data.signal_strength ? `${data.signal_strength} dBm` : '-- dBm';
    updateElementWithClass('signal-display', signalText);

    if (CONFIG.debug) console.log('📊 Updated all sensor readings');
}

// Update last seen with enhanced styling
function updateLastSeenTimestamp(lastSeen, source = 'unknown') {
    const element = document.getElementById('last-updated');
    if (element) {
        const prefix = source === 'bluetooth' ? 'Live • Updated' : 'Updated';
        element.textContent = `${prefix} ${lastSeen}`;

        // Add visual feedback for fresh data
        if (source === 'bluetooth') {
            element.classList.add('text-green-400');
        } else {
            element.classList.add('text-sky-400');
            setTimeout(() => {
                element.classList.remove('text-sky-400');
            }, 1000);
        }

        if (CONFIG.debug) console.log(`📝 Updated timestamp: ${lastSeen} (${source})`);
    }
}

// Update connection status in bluetooth section
function updateConnectionStatus(status) {
    const bluetoothStatus = document.querySelector('#bluetooth-devices .text-sm.font-medium');
    if (bluetoothStatus) {
        // Remove existing classes
        bluetoothStatus.classList.remove('text-green-400', 'text-gray-400', 'text-red-500');
        
        // Update text and color based on status
        switch(status) {
            case 'connected':
                bluetoothStatus.textContent = 'Active';
                bluetoothStatus.classList.add('text-green-400');
                break;
            case 'recent':
                bluetoothStatus.textContent = 'Recent';
                bluetoothStatus.classList.add('text-orange-400');
                break;
            case 'offline':
            default:
                bluetoothStatus.textContent = 'Inactive';
                bluetoothStatus.classList.add('text-gray-400');
                break;
        }
        
        if (CONFIG.debug) console.log(`🔗 Updated connection status: ${status}`);
    }
}

// Enhanced element update with error handling
function updateElementWithClass(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
        
        // Add brief highlight effect for visual feedback
        element.classList.add('transition-colors');
        element.style.backgroundColor = 'rgba(56, 189, 248, 0.1)'; // sky blue tint
        setTimeout(() => {
            element.style.backgroundColor = '';
        }, 500);
        
    } else if (CONFIG.debug) {
        console.warn(`Element not found: ${id}`);
    }
}

// Simple element update (legacy support)
function updateElement(id, value) {
    updateElementWithClass(id, value);
}

function initializeEventListeners() {
    // VIN search functionality
    const vinSearchInput = document.getElementById('vin-search');
    const searchResults = document.getElementById('search-results');
    
    if (vinSearchInput) {
        let searchTimeout;
        
        vinSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 3) {
                searchResults.classList.add('hidden');
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchVehicles(query);
            }, 300);
        });
    }
    
    // Vehicle assignment buttons
    const assignBtn = document.getElementById('assign-btn');
    const createBtn = document.getElementById('create-btn');
    const unassignBtn = document.getElementById('unassign-btn');
    
    if (assignBtn) {
        assignBtn.addEventListener('click', handleAssignDevice);
    }
    
    if (createBtn) {
        createBtn.addEventListener('click', handleCreateVehicle);
    }
    
    if (unassignBtn) {
        unassignBtn.addEventListener('click', handleUnassignDevice);
    }
    
    // Device management buttons
    const updateFirmwareBtn = document.getElementById('update-firmware-btn');
    const restartBtn = document.getElementById('restart-device-btn');
    const factoryResetBtn = document.getElementById('factory-reset-btn');
    
    if (updateFirmwareBtn) {
        updateFirmwareBtn.addEventListener('click', handleUpdateFirmware);
    }
    
    if (restartBtn) {
        restartBtn.addEventListener('click', handleRestartDevice);
    }
    
    if (factoryResetBtn) {
        factoryResetBtn.addEventListener('click', handleFactoryReset);
    }
}

function searchVehicles(query) {
    const searchUrl = `/device/${deviceId}/search-vehicles?q=${encodeURIComponent(query)}`;
    
    fetch(searchUrl)
        .then(response => response.json())
        .then(data => {
            displaySearchResults(data.vehicles || []);
        })
        .catch(error => {
            console.error('Search failed:', error);
            showToast('Vehicle search failed', 'error');
        });
}

function displaySearchResults(vehicles) {
    const searchResults = document.getElementById('search-results');
    
    if (vehicles.length === 0) {
        searchResults.classList.add('hidden');
        return;
    }
    
    let html = '';
    vehicles.forEach(vehicle => {
        html += `
            <div class="p-3 border-b border-gray-600 cursor-pointer hover:bg-gray-600 vehicle-result" 
                 data-vehicle='${JSON.stringify(vehicle)}'>
                <div class="font-medium text-white">${vehicle.display}</div>
                <div class="text-sm text-gray-400">VIN: ${vehicle.vin} • ${vehicle.axle_group || 'No Axle Group'}</div>
            </div>
        `;
    });
    
    searchResults.innerHTML = html;
    searchResults.classList.remove('hidden');
    
    // Add click handlers to results
    searchResults.querySelectorAll('.vehicle-result').forEach(result => {
        result.addEventListener('click', function() {
            const vehicle = JSON.parse(this.dataset.vehicle);
            selectVehicle(vehicle);
        });
    });
}

function selectVehicle(vehicle) {
    // Fill form with vehicle data
    document.getElementById('vin-search').value = vehicle.vin;
    document.getElementById('vehicle-year').value = vehicle.year || '';
    document.getElementById('vehicle-make').value = vehicle.make || '';
    document.getElementById('vehicle-model').value = vehicle.model || '';
    document.getElementById('vehicle-license').value = vehicle.license_plate || '';
    
    // Set axle group if available
    const axleSelect = document.getElementById('axle-position');
    if (axleSelect && vehicle.axle_group_id) {
        axleSelect.value = vehicle.axle_group_id;
    }
    
    // Hide search results
    document.getElementById('search-results').classList.add('hidden');
}

function handleAssignDevice() {
    const vin = document.getElementById('vin-search').value.trim();
    
    if (!vin) {
        showToast('Please enter a VIN', 'error');
        return;
    }
    
    const vehicleData = {
        vin: vin,
        year: document.getElementById('vehicle-year').value || null,
        make: document.getElementById('vehicle-make').value || null,
        model: document.getElementById('vehicle-model').value || null,
        license_plate: document.getElementById('vehicle-license').value || null,
        axle_group_id: document.getElementById('axle-position').value || null
    };
    
    showLoading('Assigning device...');
    
    fetch(`/device/${deviceId}/assign-vehicle`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(vehicleData)
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast('Device assigned successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.error || 'Assignment failed', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Assignment failed:', error);
        showToast('Assignment failed', 'error');
    });
}

function handleCreateVehicle() {
    // Same as assign - the backend will create if VIN doesn't exist
    handleAssignDevice();
}

function handleUnassignDevice() {
    if (!confirm('Are you sure you want to unassign this device from its vehicle?')) {
        return;
    }
    
    showLoading('Unassigning device...');
    
    fetch(`/device/${deviceId}/unassign-vehicle`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast('Device unassigned successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Unassignment failed', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Unassignment failed:', error);
        showToast('Unassignment failed', 'error');
    });
}

function handleUpdateFirmware() {
    if (!confirm('Are you sure you want to update the firmware? This may take several minutes.')) {
        return;
    }
    
    showLoading('Initiating firmware update...');
    
    fetch(`/device/${deviceId}/update-firmware`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast('Firmware update initiated!', 'success');
        } else {
            showToast('Update failed', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Update failed:', error);
        showToast('Update failed', 'error');
    });
}

function handleRestartDevice() {
    if (!confirm('Are you sure you want to restart this device?')) {
        return;
    }
    
    showLoading('Restarting device...');
    
    fetch(`/device/${deviceId}/restart`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast('Restart command sent!', 'success');
        } else {
            showToast('Restart failed', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Restart failed:', error);
        showToast('Restart failed', 'error');
    });
}

function handleFactoryReset() {
    const confirmation = prompt('WARNING: This will erase all device data and calibrations. Type "RESET" to confirm:');
    
    if (confirmation !== 'RESET') {
        return;
    }
    
    showLoading('Performing factory reset...');
    
    fetch(`/device/${deviceId}/factory-reset`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast('Factory reset command sent!', 'success');
        } else {
            showToast('Factory reset failed', 'error');
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Factory reset failed:', error);
        showToast('Factory reset failed', 'error');
    });
}

// Utility functions for UI feedback
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `px-4 py-3 rounded-lg text-white transform transition-all duration-300 translate-x-full ${
        type === 'success' ? 'bg-green-600' :
        type === 'error' ? 'bg-red-600' :
        'bg-blue-600'
    }`;
    toast.textContent = message;
    
    container.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.classList.remove('translate-x-full');
    }, 100);
    
    // Remove after 5 seconds
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 5000);
}

function showLoading(message) {
    // Add loading state to buttons or show overlay
    const buttons = document.querySelectorAll('button');
    buttons.forEach(btn => btn.disabled = true);
    
    showToast(message, 'info');
}

function hideLoading() {
    // Remove loading state
    const buttons = document.querySelectorAll('button');
    buttons.forEach(btn => btn.disabled = false);
}

/****************************
 * BLE Data Handling
 ****************************/
function setupBLEListener() {
    if (!window.AirScalesBLE) {
        if (CONFIG.debug) console.log('⚠️ AirScalesBLE not available yet, will retry...');

        // Retry setup after a short delay to wait for BLE initialization
        setTimeout(() => {
            if (window.AirScalesBLE) {
                if (CONFIG.debug) console.log('🔄 AirScalesBLE now available, setting up listener');
                setupBLEListener();
            } else {
                if (CONFIG.debug) console.log('⚠️ AirScalesBLE still not available (web browser mode)');
            }
        }, 500);
        return;
    }

    // Remove existing listener if any
    if (bleListenerRemover) {
        bleListenerRemover();
        if (CONFIG.debug) console.log('🔄 Removed existing BLE listener');
    }

    // Add new listener
    bleListenerRemover = window.AirScalesBLE.addListener((event, data) => {
        switch (event) {
            case 'connected':
                console.log('📱 BLE Connected on device page:', data);
                break;

            case 'disconnected':
                console.log('📱 BLE Disconnected on device page');
                isReceivingBLEData = false;
                hideLiveIndicator();
                break;

            case 'data':
                handleBLEData(data);
                break;
        }
    });

    if (CONFIG.debug) console.log('✅ BLE listener set up for device detail page');
}

function handleBLEData(data) {
    if (!data) return;

    // Check if this BLE data is for our device
    const dataMac = data.mac_address?.toUpperCase();

    // Match by MAC address (direct or mesh)
    let isForThisDevice = false;

    if (deviceMacAddress && dataMac === deviceMacAddress) {
        isForThisDevice = true;
        if (CONFIG.debug) console.log('📡 BLE data matches device MAC:', dataMac);
    }

    // Check mesh/slave devices
    if (!isForThisDevice && data.slave_devices && deviceMacAddress) {
        const matchingSlave = data.slave_devices.find(
            slave => slave.mac_address?.toUpperCase() === deviceMacAddress
        );
        if (matchingSlave) {
            data = matchingSlave; // Use slave data instead
            isForThisDevice = true;
            if (CONFIG.debug) console.log('📡 BLE data matches mesh device MAC:', deviceMacAddress);
        }
    }

    if (!isForThisDevice) {
        // Not for this device, skip
        return;
    }

    // Update tracking
    isReceivingBLEData = true;
    lastBLEDataTime = Date.now();

    // Show LIVE indicator
    showLiveIndicator();

    if (CONFIG.debug) {
        console.log('📡 Live BLE data for this device:', data);
    }

    // Transform BLE data to match server format
    const transformedData = {
        // Multi-channel support
        channels: data.channels || null,

        // Legacy single-channel support (backward compatibility)
        weight: data.weight || 0,
        main_air_pressure: data.main_air_pressure || 0,

        // Environmental data (shared)
        atmospheric_pressure: data.atmospheric_pressure || 0,
        temperature: data.temperature || 0,
        gps_lat: data.gps_lat || 0,
        gps_lng: data.gps_lng || 0,
        signal_strength: data.signal_strength || null,
        connection_status: 'connected',
        last_seen: 'just now'
    };

    // Update UI with live BLE data
    updateDeviceDisplayWithLiveData(transformedData, 'bluetooth');

    // Sync to server (uses original data, not transformed)
    bufferDataForSync(data);
}

function showLiveIndicator() {
    const indicator = document.getElementById('ble-live-indicator');
    if (indicator) {
        indicator.classList.remove('hidden');
    }
}

function hideLiveIndicator() {
    const indicator = document.getElementById('ble-live-indicator');
    if (indicator) {
        indicator.classList.add('hidden');
    }
}

/****************************
 * Server Sync
 ****************************/
function bufferDataForSync(data) {
    const now = Date.now();
    if (now - lastServerSync > 30000) { // 30 seconds
        sendBLEDataToServer(data);
        lastServerSync = now;
    }
}

async function sendBLEDataToServer(data) {
    try {
        if (CONFIG.debug) console.log('📤 Sending BLE data to server:', data);

        const response = await fetch('/api/bridge/data', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ...data,
                source: 'bluetooth_app',
                request_coefficients: true,
            }),
        });

        if (response.ok) {
            const result = await response.json();
            if (CONFIG.debug) console.log('✅ BLE data synced to server:', result);

            // Send coefficients back to device if provided (new multi-channel format)
            if (result.channel_calibrations && window.AirScalesBLE) {
                console.log('📊 Channel calibrations received from server:', result.channel_calibrations);
                for (const channelCal of result.channel_calibrations) {
                    const coefficients = {
                        intercept: channelCal.intercept,
                        air_pressure_coeff: channelCal.air_pressure_coeff,
                        ambient_pressure_coeff: channelCal.ambient_pressure_coeff,
                        air_temp_coeff: channelCal.air_temp_coeff
                    };
                    await window.AirScalesBLE.sendCoefficients(coefficients, channelCal.channel_index);
                    console.log('📊 Coefficients sent to device for channel', channelCal.channel_index);
                }
            }
            // DEPRECATED: Legacy single-channel coefficients for backward compatibility
            else if (result.regression_coefficients && window.AirScalesBLE) {
                console.log('📊 Legacy regression coefficients received from server:', result.regression_coefficients);
                await window.AirScalesBLE.sendCoefficients(result.regression_coefficients, 1);
                console.log('📊 Legacy coefficients sent to device');
            }
        } else {
            const errorText = await response.text();
            console.error('❌ Server sync failed:', response.status, errorText);
        }
    } catch (error) {
        console.error('❌ Server sync error:', error);
    }
}

// Cleanup when page unloads
window.addEventListener('beforeunload', function() {
    if (updateInterval) {
        clearInterval(updateInterval);
    }

    // Remove BLE listener
    if (bleListenerRemover) {
        bleListenerRemover();
    }
});
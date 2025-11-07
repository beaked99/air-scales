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

function initializeDeviceDetailApi(apiUrl, id) {
    deviceApiUrl = apiUrl;
    deviceId = id;
    
    if (CONFIG.debug) console.log('Device Detail API URL set to:', deviceApiUrl);
    
    // Start live data updates
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
            console.log('✅ Device live data received:', data);
            console.log(`📊 Weight: ${data.weight} lbs, Pressure: ${data.main_air_pressure} psi`);
        }
        
        // Update the DOM with live data
        updateDeviceDisplayWithLiveData(data);
        
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
function updateDeviceDisplayWithLiveData(data) {
    if (CONFIG.debug) console.log('🔄 Updating device detail DOM with live data...');
    
    // Update current readings section
    updateCurrentReadings(data);
    
    // Update last seen timestamp
    updateLastSeenTimestamp(data.last_seen);
    
    // Update connection status in bluetooth section
    updateConnectionStatus(data.connection_status);
    
    if (CONFIG.debug) console.log('✅ Device detail DOM updated successfully');
}

// Update all sensor readings
function updateCurrentReadings(data) {
    // Weight with enhanced formatting
    updateElementWithClass('weight-display', `${Math.round(data.weight).toLocaleString()} lbs`);
    
    // Pressures
    updateElementWithClass('pressure-display', `${data.main_air_pressure.toFixed(1)} psi`);
    updateElementWithClass('atmospheric-pressure-display', `${data.atmospheric_pressure.toFixed(1)} psi`);
    
    // Temperature 
    updateElementWithClass('temperature-display', `${Math.round(data.temperature)}°F`);
    
    // GPS coordinates
    updateElementWithClass('gps-display', `${data.gps_lat.toFixed(3)}, ${data.gps_lng.toFixed(3)}`);
    
    // Signal strength
    const signalText = data.signal_strength ? `${data.signal_strength} dBm` : '-- dBm';
    updateElementWithClass('signal-display', signalText);
    
    if (CONFIG.debug) console.log('📊 Updated all sensor readings');
}

// Update last seen with enhanced styling
function updateLastSeenTimestamp(lastSeen) {
    const element = document.getElementById('last-updated');
    if (element) {
        element.textContent = `Updated ${lastSeen}`;
        
        // Add visual feedback for fresh data
        element.classList.add('text-sky-400');
        setTimeout(() => {
            element.classList.remove('text-sky-400');
        }, 1000);
        
        if (CONFIG.debug) console.log(`📝 Updated timestamp: ${lastSeen}`);
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

// Cleanup when page unloads
window.addEventListener('beforeunload', function() {
    if (updateInterval) {
        clearInterval(updateInterval);
    }
});
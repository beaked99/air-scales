// vehicles.js - Vehicle list page functionality

const modal = document.getElementById('vehicle-modal');
const modalTitle = document.getElementById('modal-title');
const vehicleForm = document.getElementById('vehicle-form');
const vehicleIdInput = document.getElementById('vehicle-id');

// Vehicle search/add functionality
const vehicleSearchInput = document.getElementById('vehicle-search-input');
const vehicleSearchResults = document.getElementById('vehicle-search-results');
let searchTimeout = null;

// Search functionality
const searchInput = document.getElementById('search-input');
const vehicleCards = document.querySelectorAll('.vehicle-card');
const noResults = document.getElementById('no-results');
const vehiclesContainer = document.getElementById('vehicles-container');

searchInput?.addEventListener('input', function() {
  const query = this.value.toLowerCase().trim();

  let visibleCount = 0;

  vehicleCards.forEach(card => {
    const make = card.dataset.make || '';
    const model = card.dataset.model || '';
    const vin = card.dataset.vin || '';
    const license = card.dataset.license || '';
    const nickname = card.dataset.nickname || '';

    const searchText = `${make} ${model} ${vin} ${license} ${nickname}`;

    if (!query || searchText.includes(query)) {
      card.classList.remove('hidden');
      visibleCount++;
    } else {
      card.classList.add('hidden');
    }
  });

  if (visibleCount === 0 && query) {
    noResults.classList.remove('hidden');
    vehiclesContainer.classList.add('hidden');
  } else {
    noResults.classList.add('hidden');
    vehiclesContainer.classList.remove('hidden');
  }
});

document.getElementById('clear-search-btn')?.addEventListener('click', function() {
  searchInput.value = '';
  vehicleCards.forEach(card => card.classList.remove('hidden'));
  noResults.classList.add('hidden');
  vehiclesContainer.classList.remove('hidden');
});

// Modal controls
function openModal(isEdit = false, vehicleId = null) {
  modal.classList.remove('hidden');
  modalTitle.textContent = isEdit ? 'Edit Vehicle' : 'Add Vehicle';
  vehicleIdInput.value = vehicleId || '';

  if (isEdit && vehicleId) {
    // Fetch vehicle data and populate form
    fetch(`/api/vehicle/${vehicleId}`)
      .then(response => response.json())
      .then(data => {
        if (data.vehicle) {
          document.getElementById('vehicle-year').value = data.vehicle.year || '';
          document.getElementById('vehicle-make').value = data.vehicle.make || '';
          document.getElementById('vehicle-model').value = data.vehicle.model || '';
          document.getElementById('vehicle-nickname').value = data.vehicle.nickname || '';
          document.getElementById('vehicle-vin').value = data.vehicle.vin || '';
          document.getElementById('vehicle-license-plate').value = data.vehicle.license_plate || '';
        }
      })
      .catch(error => {
        console.error('Error fetching vehicle:', error);
        alert('Failed to load vehicle data');
        closeModal();
      });
  } else {
    // Clear form for new vehicle
    vehicleForm.reset();
  }
}

function closeModal() {
  modal.classList.add('hidden');
  vehicleForm.reset();
  vehicleIdInput.value = '';
}

// Add vehicle button
document.getElementById('add-vehicle-btn')?.addEventListener('click', () => openModal(false));
document.getElementById('add-first-vehicle-btn')?.addEventListener('click', () => openModal(false));

// Cancel button
document.getElementById('cancel-modal-btn')?.addEventListener('click', closeModal);

// Close modal on outside click
modal?.addEventListener('click', function(e) {
  if (e.target === modal) {
    closeModal();
  }
});

// Edit vehicle buttons
document.querySelectorAll('.edit-vehicle-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const vehicleId = this.dataset.vehicleId;
    openModal(true, vehicleId);
  });
});

// Delete vehicle buttons
document.querySelectorAll('.delete-vehicle-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const vehicleId = this.dataset.vehicleId;
    const vehicleName = this.dataset.vehicleName;
    const deviceCount = parseInt(this.dataset.deviceCount);

    if (deviceCount > 0) {
      alert(`Cannot delete "${vehicleName}" because it has ${deviceCount} device(s) attached.\n\nPlease detach all devices before deleting this vehicle.`);
      return;
    }

    if (!confirm(`Are you sure you want to delete "${vehicleName}"?\n\nThis action cannot be undone.`)) {
      return;
    }

    const button = this;
    button.disabled = true;
    button.textContent = 'Deleting...';

    fetch(`/api/vehicle/delete/${vehicleId}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        alert(data.message);
        location.reload();
      } else {
        alert('Error: ' + (data.message || 'Failed to delete vehicle'));
        button.disabled = false;
        button.textContent = 'Delete';
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Failed to delete vehicle');
      button.disabled = false;
      button.textContent = 'Delete';
    });
  });
});

// Form submission
vehicleForm?.addEventListener('submit', function(e) {
  e.preventDefault();

  const vehicleId = vehicleIdInput.value;
  const isEdit = !!vehicleId;

  const data = {
    year: parseInt(document.getElementById('vehicle-year').value),
    make: document.getElementById('vehicle-make').value.trim(),
    model: document.getElementById('vehicle-model').value.trim(),
    nickname: document.getElementById('vehicle-nickname').value.trim() || null,
    vin: document.getElementById('vehicle-vin').value.trim().toUpperCase() || null,
    license_plate: document.getElementById('vehicle-license-plate').value.trim().toUpperCase() || null
  };

  const url = isEdit ? `/api/vehicle/update/${vehicleId}` : '/api/vehicle/create';
  const method = 'POST';

  fetch(url, {
    method: method,
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert(isEdit ? 'Vehicle updated successfully!' : 'Vehicle created successfully!');
      location.reload();
    } else {
      alert('Error: ' + (data.message || 'Failed to save vehicle'));
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Failed to save vehicle');
  });
});

// Vehicle search autocomplete
vehicleSearchInput?.addEventListener('input', function() {
  const query = this.value.trim();

  clearTimeout(searchTimeout);

  if (query.length < 2) {
    vehicleSearchResults.classList.add('hidden');
    return;
  }

  searchTimeout = setTimeout(() => {
    searchVehicles(query);
  }, 300);
});

// Hide results when clicking outside
document.addEventListener('click', function(e) {
  if (!vehicleSearchInput?.contains(e.target) && !vehicleSearchResults?.contains(e.target)) {
    vehicleSearchResults?.classList.add('hidden');
  }
});

function searchVehicles(query) {
  fetch(`/api/vehicle/search?q=${encodeURIComponent(query)}`)
    .then(response => response.json())
    .then(data => {
      if (data.vehicles && data.vehicles.length > 0) {
        displaySearchResults(data.vehicles);
      } else {
        vehicleSearchResults.innerHTML = '<div class="p-4 text-gray-400 text-sm">No vehicles found with Air Scales devices</div>';
        vehicleSearchResults.classList.remove('hidden');
      }
    })
    .catch(error => {
      console.error('Error searching vehicles:', error);
      vehicleSearchResults.classList.add('hidden');
    });
}

function displaySearchResults(vehicles) {
  vehicleSearchResults.innerHTML = vehicles.map(vehicle => `
    <div class="p-3 hover:bg-gray-600 cursor-pointer border-b border-gray-600 last:border-b-0 vehicle-search-result"
         data-vehicle-id="${vehicle.id}"
         data-vehicle-name="${vehicle.name}">
      <div class="flex justify-between items-center">
        <div>
          <div class="font-semibold text-white">${vehicle.name}</div>
          <div class="text-xs text-gray-400">
            ${vehicle.vin ? 'VIN: ' + vehicle.vin : ''}
            ${vehicle.license_plate ? 'License: ' + vehicle.license_plate : ''}
          </div>
          <div class="text-xs text-green-400 mt-1">
            ${vehicle.device_count} Air Scale device(s) attached
          </div>
        </div>
        <button class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm add-vehicle-btn">
          Add
        </button>
      </div>
    </div>
  `).join('');

  vehicleSearchResults.classList.remove('hidden');

  // Add click handlers for "Add" buttons
  vehicleSearchResults.querySelectorAll('.add-vehicle-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const resultDiv = this.closest('.vehicle-search-result');
      const vehicleId = resultDiv.dataset.vehicleId;
      const vehicleName = resultDiv.dataset.vehicleName;
      addVehicleToMyList(vehicleId, vehicleName);
    });
  });
}

function addVehicleToMyList(vehicleId, vehicleName) {
  if (!confirm(`Connect to "${vehicleName}"?\n\nThis will add it to your vehicle list.`)) {
    return;
  }

  fetch(`/api/vehicle/connect/${vehicleId}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    }
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert(`Connected to "${vehicleName}" successfully!`);
      location.reload();
    } else {
      alert('Error: ' + (data.message || 'Failed to connect to vehicle'));
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Failed to connect to vehicle');
  });
}

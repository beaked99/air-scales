<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Device Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" crossorigin="anonymous" />
  </head>
  <body class="min-h-screen text-white bg-gray-900">
    <!-- Header -->
    <header class="sticky top-0 z-50 px-4 py-3 bg-gray-800 border-b border-gray-700 shadow-md">
      <div class="flex items-center justify-between">
        <div class="w-1/3">
          <h1 class="text-xl font-semibold leading-tight text-white">Device</h1>
        </div>
        <!-- empty left spacer -->

        <!-- Center section -->
        <div class="w-1/3 text-center">
          <h1 class="text-2xl font-semibold text-sky-400">AS25-5C1984</h1>
        </div>

        <!-- Right side -->
        <div class="flex items-center justify-end w-1/3 space-x-3">
          <div class="text-gray-300 fas fa-user"></div>
          <div class="text-sm text-gray-300">Kevin Wiebe</div>
        </div>
      </div>
    </header>

    <main class="pb-20 divide-y divide-gray-700">
      <!-- Section: Current Readings -->
      <section class="p-6 bg-gray-800">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-xl font-semibold text-white">Current Readings</h2>
          <p class="text-sm text-gray-400">Updated 5 min ago</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label class="block mb-2 text-sm text-gray-400">Weight</label>
            <div class="px-4 py-3 text-lg font-semibold text-gray-200 bg-gray-700 rounded-lg">21,791 lbs</div>
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Main Air Bag Pressure</label>
            <div class="px-4 py-3 text-gray-200 bg-gray-700 rounded-lg">31.0 psi</div>
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Ambient Temperature</label>
            <div class="px-4 py-3 text-gray-200 bg-gray-700 rounded-lg">45°F</div>
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Ambient Pressure</label>
            <div class="px-4 py-3 text-gray-200 bg-gray-700 rounded-lg">14.7 psi</div>
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">GPS Location</label>
            <div class="px-4 py-3 font-mono text-sm text-gray-200 bg-gray-700 rounded-lg">40.712, -74.005</div>
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Signal Strength</label>
            <div class="px-4 py-3 text-gray-200 bg-gray-700 rounded-lg">-67 dBm</div>
          </div>
        </div>
      </section>

      <!-- Section: Device Assignment -->
      <section class="p-6 bg-gray-800">
        <h2 class="mb-6 text-xl font-semibold text-white">Device Assignment</h2>

        <!-- Current Assignment -->
        <div class="p-4 mb-6 border border-gray-600 rounded-lg bg-gray-700/50">
          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-white">Currently Assigned</p>
              <p class="text-gray-300">2018 Peterbilt 378 - Drive Axle</p>
              <p class="text-sm text-gray-400">VIN: BNR34-001864 • Plate: ABC-123</p>
            </div>
            <button class="text-sm text-orange-400 hover:text-red-500"><i class="mr-1 fas fa-unlink"></i>Unassign</button>
          </div>
        </div>

        <!-- VIN Search -->
        <div class="mb-6">
          <label class="block mb-2 text-sm text-gray-400">Vehicle VIN</label>
          <div class="relative">
            <input type="text" placeholder="Search by VIN number..." class="w-full px-4 py-3 pr-12 text-white placeholder-gray-400 transition-colors bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none" />
            <div class="absolute transform -translate-y-1/2 top-1/2 right-3">
              <i class="text-gray-400 fas fa-search"></i>
            </div>
          </div>
          <p class="mt-2 text-xs text-gray-400">Start typing to search existing vehicles or enter full VIN for new vehicle</p>
        </div>

        <!-- Vehicle Details -->
        <div class="grid grid-cols-1 gap-4 mb-6 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="block mb-2 text-sm text-gray-400">Year</label>
            <input type="text" value="2018" class="w-full px-4 py-3 text-white bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none" />
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Make</label>
            <input type="text" value="Peterbilt" class="w-full px-4 py-3 text-white bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none" />
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Model</label>
            <input type="text" value="378" class="w-full px-4 py-3 text-white bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none" />
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">License Plate</label>
            <input type="text" value="ABC-123" class="w-full px-4 py-3 text-white bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none" />
          </div>
        </div>
        <!-- Axle Position Selection -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="block mb-2 text-sm text-gray-400">Axle Position</label>
            <select name="axle-position" id="axle-position" class="w-full px-4 py-3 text-white transition-colors bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none">
              <option value="">Select Position</option>
              <option value="steer">Steer Axle</option>
              <option value="drive" selected>Drive Axle</option>
              <option value="trailer-1">Trailer Axle 1</option>
              <option value="trailer-2">Trailer Axle 2</option>
              <option value="trailer-3">Trailer Axle 3</option>
            </select>
          </div>
          <div>
            <label class="block mb-2 text-sm text-gray-400">Weight Limit (lbs)</label>
            <input type="number" placeholder="e.g. 34000" class="w-full px-4 py-3 text-white placeholder-gray-400 transition-colors bg-gray-700 border border-gray-600 rounded-lg focus:border-sky-400 focus:outline-none" />
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-col mt-6 sm:w-full sm:flex-row sm:justify-evenly sm:gap-3">
          <button class="flex items-center justify-center w-full gap-2 px-6 py-3 mb-2 font-semibold text-black transition-colors bg-orange-400 rounded-lg hover:bg-orange-700 sm:mb-0 sm:w-full">
            <i class="fas fa-link"></i>
            <span>Assign Device</span>
          </button>
          <button class="flex items-center justify-center w-full gap-2 px-6 py-3 font-medium text-black transition-colors bg-green-400 rounded-lg hover:bg-green-700 sm:w-full">
            <i class="fas fa-plus"></i>
            <span>Create New Vehicle</span>
          </button>
        </div>
      </section>

      <!-- Section: Connections -->
      <section class="p-6 bg-gray-800">
        <h2 class="mb-6 text-xl font-semibold text-white">Connections</h2>
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
          <!-- WiFi -->
          <div>
            <h3 class="mb-4 text-lg font-medium text-gray-200">WiFi Networks</h3>
            <ul class="space-y-3">
              <li class="flex items-center justify-between px-4 py-3 bg-gray-700 rounded-lg">
                <div>
                  <span class="text-gray-200">TruckStop_WiFi</span>
                </div>
                <span class="text-sm font-medium text-green-400">Connected</span>
              </li>
              <li class="flex items-center justify-between px-4 py-3 bg-gray-700 rounded-lg">
                <span class="text-gray-200">HomeOffice_5G</span>
                <span class="text-sm text-gray-400">Saved</span>
              </li>
            </ul>
            <button class="mt-4 text-sm font-medium transition-colors text-sky-400 hover:text-sky-300"><i class="mr-2 fas fa-plus"></i>Add Network</button>
          </div>

          <!-- Bluetooth -->
          <div>
            <h3 class="mb-4 text-lg font-medium text-gray-200">Bluetooth Devices</h3>
            <ul class="space-y-3">
              <li class="flex items-center justify-between px-4 py-3 bg-gray-700 rounded-lg">
                <div>
                  <span class="text-gray-200">John's iPhone</span>
                  <div class="mt-1 text-xs text-gray-400">Last connected: 2 min ago</div>
                </div>
                <span class="text-sm font-medium text-green-400">Active</span>
              </li>
              <li class="flex items-center justify-between px-4 py-3 bg-gray-700 rounded-lg">
                <div>
                  <span class="text-gray-200">Maria's Android</span>
                  <div class="mt-1 text-xs text-gray-400">Last connected: 3 days ago</div>
                </div>
                <span class="text-sm text-gray-400">Inactive</span>
              </li>
            </ul>
            <button class="mt-4 text-sm font-medium transition-colors text-sky-400 hover:text-sky-300"><i class="mr-2 fas fa-plus"></i>Pair Device</button>
          </div>
        </div>
      </section>

      <!-- Section: Device Management -->
      <section class="p-6 bg-gray-800">
        <h2 class="mb-6 text-xl font-semibold text-white">Device Management</h2>

        <!-- Device Info -->
        <div class="mb-6">
          <h3 class="mb-3 text-lg font-medium text-gray-200">Hardware Information</h3>
          <div class="grid grid-cols-1 text-sm gap-x-8 gap-y-2 sm:grid-cols-2">
            <div class="flex justify-between">
              <span class="text-gray-400">Serial Number</span>
              <span class="text-gray-300">AS25-5C1984</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">MAC Address</span>
              <span class="font-mono text-gray-300">EC:DA:3B:5C:19:84</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">Current Firmware</span>
              <span class="text-gray-300">v0.0.7</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">Available Update</span>
              <span class="font-medium text-green-400">v0.0.8</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">Calibrations</span>
              <span class="text-gray-300">12 completed</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">Last Calibration</span>
              <span class="text-gray-300">3 days ago</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">Last Seen</span>
              <span class="text-gray-300">5 min ago</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-400">Uptime</span>
              <span class="text-gray-300">7 days, 3 hours</span>
            </div>
          </div>
        </div>

        <!-- Management Actions -->
        <div class="flex flex-col sm:w-full sm:flex-row sm:justify-evenly sm:gap-3">
          <button class="flex items-center justify-center w-full gap-2 px-4 py-3 mb-2 font-semibold text-black transition-colors rounded-lg bg-sky-600 hover:bg-sky-700 sm:w-full">
            <i class="text-sm fas fa-wrench"></i>
            <span>Update Firmware</span>
          </button>
          <button class="flex items-center justify-center w-full gap-2 px-4 py-3 mb-2 font-semibold text-black transition-colors bg-orange-400 rounded-lg hover:bg-orange-600 sm:w-full">
            <i class="text-sm fas fa-sync"></i>
            <span>Restart Device</span>
          </button>
          <button class="flex items-center justify-center w-full gap-2 px-4 py-3 mb-2 font-semibold text-black transition-colors bg-red-600 rounded-lg hover:bg-red-700 sm:w-full">
            <i class="text-sm fas fa-undo"></i>
            <span>Factory Reset</span>
          </button>
        </div>
      </section>
    </main>

    <!-- Bottom Navigation -->
    <nav class="fixed bottom-0 left-0 right-0 bg-gray-900 border-t border-gray-800">
      <div class="flex items-center justify-around py-3">
        <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
          <div class="flex items-center justify-center w-6 h-6 rounded-lg tab-icon bg-sky-400">
            <i class="text-sm text-gray-900 fas fa-tachometer-alt"></i>
          </div>
          <span class="text-xs font-medium tab-label text-sky-400">Dashboard</span>
        </button>
        <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
          <div class="flex items-center justify-center w-6 h-6 bg-gray-700 rounded-lg tab-icon">
            <i class="text-sm text-gray-400 fas fa-balance-scale"></i>
          </div>
          <span class="text-xs text-gray-400 tab-label">Calibrate</span>
        </button>
        <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
          <div class="flex items-center justify-center w-6 h-6 bg-gray-700 rounded-lg tab-icon">
            <i class="text-sm text-gray-400 fas fa-cog"></i>
          </div>
          <span class="text-xs text-gray-400 tab-label">Setup</span>
        </button>
        <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
          <div class="flex items-center justify-center w-6 h-6 bg-gray-700 rounded-lg tab-icon">
            <i class="text-sm text-gray-400 fas fa-user"></i>
          </div>
          <span class="text-xs text-gray-400 tab-label">Profile</span>
        </button>
      </div>
    </nav>
  </body>
</html>

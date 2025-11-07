<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Air Scales Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />

</head>
<body class="min-h-screen text-gray-100 bg-gray-900">

  <!-- Header -->
  <header class="px-4 py-3 bg-gray-900 border-b border-gray-800">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold text-white">Air Scales Dashboard</h1>
      <div class="flex items-center space-x-3">
        <div class="text-gray-400 fas fa-user"></div>
        <div class="text-xs text-gray-400">Kevin Wiebe</div>
      </div>
    </div>
  </header>

    <!-- Main Content -->
  <main class="pb-20">
  <div id="warning" class="p-1 space-y-2 bg-gray-900">
  <!-- Subscription Warning -->
  <div class="p-4 border border-black rounded-lg bg-orange-400/85">
    <div class="flex items-center">
      <div class="flex items-center justify-center w-8 h-8 mr-3">
  <i class="text-4xl text-black fas fa-exclamation-triangle"></i>
      </div>
      <div>
        <div class="font-bold text-black">Subscription Required</div>
        <div class="text-sm text-black">Ensure you have an active subscription to see live axle weights.</div>
      </div>
    </div>
  </div>
  
  <!-- Config Warning -->
  <div class="p-4 border border-black rounded-lg bg-orange-400/85">
    <div class="flex items-center">
      <div class="flex items-center justify-center w-8 h-8 mr-3">
  <i class="text-4xl text-black fas fa-exclamation-triangle"></i>
</div>
      <div>
        <div class="font-bold text-black">Setup Required</div>
        <div class="text-sm text-black">Set up your truck and trailer configuration to see axle weights.</div>
      </div>
    </div>
  </div>
</div>


    <!-- Device Status List -->
    <section class="bg-gray-900 border-gray-800">
      <div class="p-4 border-b border-gray-800">
        <h2 class="text-lg font-medium text-white">Current Axle-Group Configuration</h2>
      </div>
      <div class="divide-y divide-gray-800">

        <!-- For each device in the users Current Axle-Group Configuration... Device #1 -->
        <div class="flex items-center justify-between p-4">
  <div class="flex items-center space-x-3">
    <div class="w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
    <div data-device-id="001">
      <div class="font-medium text-white">Drives</div>
      <div class="text-sm text-green-400 ">Connected</div>
    </div>
  </div>
  <div class="text-right">
    <div class="font-bold text-white">33,200 lbs</div>
    <div class="text-sm text-gray-400">85.2 psi</div>
  </div>
</div>

        <!-- Device 2 -->
        <div class="flex items-center justify-between p-4">
          <div class="flex items-center space-x-3">
            <div class="w-3 h-3 bg-orange-400 rounded-full"></div>
            <div data-device-id="002">
              <div class="font-medium text-white">Lead Trailer</div>
      <div id="status-device-001" class="text-sm text-orange-400">5 min ago</div>
            </div>
          </div>
          <div class="text-right">
            <div class="font-bold text-red-500">42,530 lbs</div><!-- Rounded to nearest 10, orange because we are over weight by a few percent. 42000 would be the allowable here -->
            <div class="text-sm text-gray-400">92.1 psi</div>
          </div>
        </div>

        <!-- Device 3 -->
        <div class="flex items-center justify-between p-4">
          <div class="flex items-center space-x-3">
            <div class="w-3 h-3 bg-red-500 rounded-full"></div>
            <div data-device-id="003">
              <div class="font-medium text-white">Pup Trailer</div>
      <div id="status-device-001" class="text-sm text-red-500">2 day(s) ago</div>
            </div>
          </div>
          <div class="text-right">
            <div class="font-bold text-white">5,000 lbs</div>
            <div class="text-sm text-gray-400">45.8 psi</div>
          </div>
        </div>
      </div>
    </section>

    <!-- Total Weight Display -->
    <section class="p-4 text-center bg-gray-900 border border-gray-800">
      <div class="mb-2 text-sm tracking-wider text-gray-400 uppercase">Total Estimated Weight</div>
<div class="flex items-end justify-center mb-3 space-x-2">
  <div class="text-5xl font-bold text-white">34,580</div>
  <div class="text-lg text-gray-400">lbs</div>
  <div class="text-lg text-gray-400">± 240</div><!-- only show this if our calibration count is > 3 on all axle group configurations -->
</div>
<div class="flex items-end justify-center space-x-2 ">
            <svg class="h-[1lh] w-5.5 shrink-0" viewBox="0 0 22 22" fill="none" stroke-linecap="square">
              <circle cx="11" cy="11" r="11" class="fill-sky-400/20" />
              <circle cx="11" cy="11" r="10.5" class="stroke-sky-400/20" />
              <path d="M8 11.5L10.5 14L14 8" class="stroke-sky-800 dark:stroke-sky-300" />
            </svg>
              <p class="font-mono font-medium text-gray-950 dark:text-white">within limits</p>
          </div><div class="flex items-end justify-center space-x-2">
  <svg class="h-[1lh] w-5.5 shrink-0" viewBox="0 0 22 22" fill="none" stroke-linecap="square">
    <circle cx="11" cy="11" r="11" class="fill-red-400/20" />
    <circle cx="11" cy="11" r="10.5" class="stroke-red-400/20" />
    <path d="M8 8L14 14M14 8L8 14" class="stroke-red-800 dark:stroke-red-300" />
  </svg>
  <p class="font-mono font-medium text-gray-950 dark:text-white">over weight</p>
</div>

    </section>
<section id="quote-of-the-day" class="bg-gray-900">
  <div id="tips" class="p-4">
    <div class="relative text-center">
      <p class="px-6 italic text-gray-200">
        Your Air Scales device improves with each calibration. Start with 4 to get basic readings; aim for 10+ for reliable accuracy.
      </p>
      <p class="mt-2 text-sm text-gray-400">beaker, July 2025</p>
    </div>
  </div>
</section>
    

  </main>

  <!-- Bottom Navigation -->
<nav class="fixed bottom-0 left-0 right-0 bg-gray-900 border-t border-gray-800">
  <div class="flex items-center justify-around py-3">
    <!-- Dashboard -->
    <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
      <div class="flex items-center justify-center w-6 h-6 rounded-lg tab-icon bg-sky-400">
        <i class="text-sm text-gray-900 fas fa-tachometer-alt"></i>
      </div>
      <span class="text-xs font-medium tab-label text-sky-400">Dashboard</span>
    </button>

    <!-- Calibrate -->
    <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
      <div class="flex items-center justify-center w-6 h-6 bg-gray-700 rounded-lg tab-icon">
        <i class="text-sm text-gray-400 fas fa-balance-scale"></i>
      </div>
      <span class="text-xs text-gray-400 tab-label">Calibrate</span>
    </button>

    <!-- Setup -->
    <button class="flex flex-col items-center px-4 py-2 space-y-1 tab-btn">
      <div class="flex items-center justify-center w-6 h-6 bg-gray-700 rounded-lg tab-icon">
        <i class="text-sm text-gray-400 fas fa-cog"></i>
      </div>
      <span class="text-xs text-gray-400 tab-label">Setup</span>
    </button>

    <!-- Profile -->
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

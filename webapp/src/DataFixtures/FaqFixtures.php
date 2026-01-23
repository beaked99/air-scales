<?php

namespace App\DataFixtures;

use App\Entity\Faq;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class FaqFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $faqs = [
            // Getting Started
            [
                'title' => 'What is AirScales?',
                'category' => 'general',
                'sortOrder' => 10,
                'content' => '<p class="text-gray-300">AirScales is a wireless load monitoring system that estimates vehicle weight by reading air bag pressure from your truck or trailer\'s air suspension system. It provides real-time weight estimates directly to your mobile device via Bluetooth or WiFi.</p>'
            ],
            [
                'title' => 'Do I need a subscription?',
                'category' => 'general',
                'sortOrder' => 20,
                'content' => '<p class="text-gray-300 mb-2">Yes. A subscription is required to unlock all features of the mobile app including:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4">
  <li>Real-time weight monitoring</li>
  <li>Cloud data storage and sync</li>
  <li>Historical data and reports</li>
  <li>Custom alerts and notifications</li>
  <li>Multi-device support</li>
</ul>
<p class="text-gray-300 mt-2"><strong>Good news:</strong> New device purchases include 6 months free subscription for 1 user!</p>'
            ],
            [
                'title' => 'How accurate is AirScales?',
                'category' => 'general',
                'sortOrder' => 30,
                'content' => '<p class="text-gray-300 mb-3">AirScales accuracy improves with proper calibration. The more calibration points you use across different load levels, the more accurate your weight estimates will be.</p>
<p class="text-gray-300 mb-3"><strong>Key insight:</strong> Calibrating at different load levels (empty, half-full, fully loaded) is more important than environmental factors. Once properly calibrated, readings remain stable across normal operating conditions.</p>
<p class="text-gray-300 mb-3"><strong class="text-yellow-400">Important:</strong> AirScales provides weight <strong>estimates</strong>, not certified measurements. Always confirm legal compliance using certified scales where required.</p>
<p class="text-gray-300 text-sm">Note: AirScales assumes the air suspension system is functioning normally (no leaks or mechanical faults).</p>'
            ],

            // Device Configuration
            [
                'title' => 'How many devices do I need?',
                'category' => 'technical',
                'sortOrder' => 10,
                'content' => '<p class="text-gray-300 mb-3"><strong>Simple rule:</strong> One AirScales device per vehicle (truck or trailer). Each device can monitor up to two axle groups.</p>
<p class="text-gray-300 mb-3">Common setups:</p>
<div class="bg-gray-900 rounded-lg p-4 space-y-3">
  <div>
    <p class="text-white font-semibold">Single truck with single trailer</p>
    <p class="text-gray-300">→ 2 devices total (1 on the truck, 1 on the trailer)</p>
  </div>
  <div>
    <p class="text-white font-semibold">Super B-Train (truck + two trailers)</p>
    <p class="text-gray-300">→ 3 devices total (1 per vehicle)</p>
  </div>
  <div>
    <p class="text-white font-semibold">Truck with quad-axle trailer</p>
    <p class="text-gray-300">→ 2 devices total (Truck drive axles + two trailer axle groups)</p>
  </div>
</div>'
            ],
            [
                'title' => 'Single-sensor vs. dual-sensor configuration?',
                'category' => 'technical',
                'sortOrder' => 20,
                'content' => '<p class="text-gray-300 mb-3">Each AirScales device supports one or two pressure sensors.</p>
<div class="space-y-3 ml-4">
  <div>
    <p class="text-white font-semibold">Single sensor</p>
    <p class="text-gray-300">→ Monitors a single load level sensor for 1 axle group using one device and 1 sensor</p>
  </div>
  <div>
    <p class="text-white font-semibold">Dual sensor</p>
    <p class="text-gray-300">→ Monitors two separate load level sensors for two separate axle groups using 1 device and 2 sensors</p>
  </div>
</div>'
            ],
            [
                'title' => 'Do I need to connect wires between my truck and trailer or 2nd trailer?',
                'category' => 'technical',
                'sortOrder' => 30,
                'content' => '<p class="text-gray-300 mb-3"><strong>No.</strong> AirScales devices communicate wirelessly between themselves and only one is required to connect with your phone or with your truck wifi system. Usually the one mounted to your truck frame.</p>
<p class="text-gray-300">There are:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4 mt-2">
  <li>No wires at the kingpin</li>
  <li>No connections between truck and trailer or trailer and trailer</li>
  <li>Each device is fully self-contained on its own vehicle</li>
</ul>'
            ],

            // Installation
            [
                'title' => 'How do I install AirScales?',
                'category' => 'installation',
                'sortOrder' => 10,
                'content' => '<p class="text-gray-300 mb-2">Installation is straightforward:</p>
<ol class="list-decimal list-inside text-gray-300 space-y-2 ml-4">
  <li>Drop the air out of your air bags, ensure that the bags are fully deflated</li>
  <li>Connect the device to your vehicle\'s 12V power supply using the supplied power wire ensuring to connect the blade 5amp fuse on the red wire</li>
  <li>Cut the air supply line between the air bag(s) and the load level sensor</li>
  <li>Install the supplied push to connect 3/8" T-Fitting into the air line. Install the pressure sensor and torque to XX ft-lb</li>
  <li>Connect the three wires from the AirScales device to the pressure sensor in the T-fitting: 5V (yellow), GND (white), and 0.5V - 4.5V sensor line (blue or green)</li>
  <li>Mount the device in a secure location</li>
  <li>Download the mobile app and pair via Bluetooth or WiFi</li>
</ol>
<p class="text-gray-300 mt-3">No complex wiring or special tools required. Most installations take 30-60 minutes per vehicle.</p>'
            ],
            [
                'title' => 'Can I install it myself?',
                'category' => 'installation',
                'sortOrder' => 20,
                'content' => '<p class="text-gray-300">Yes! If you\'re comfortable with basic mechanical work and have installed accessories on your truck before, you can install AirScales yourself. The installation guide includes step-by-step instructions with photos. Otherwise, any truck mechanic or shop can install it for you.</p>'
            ],
            [
                'title' => 'What if I switch trailers frequently?',
                'category' => 'installation',
                'sortOrder' => 30,
                'content' => '<p class="text-gray-300">Perfect use case! Since each trailer has its own AirScales device, you can monitor any trailer configuration. The app automatically detects and connects to all nearby AirScales devices. Drop a trailer? The app shows only your truck. Hook up a different trailer? It automatically appears in the app.</p>'
            ],

            // Calibration
            [
                'title' => 'Is calibration required?',
                'category' => 'calibration',
                'sortOrder' => 10,
                'content' => '<p class="text-gray-300 mb-3"><strong>Yes.</strong> AirScales does not work without calibration.</p>
<p class="text-gray-300">Each axle group must be calibrated so the system can convert air pressure → axle weight for that specific suspension.</p>'
            ],
            [
                'title' => 'How calibration works',
                'category' => 'calibration',
                'sortOrder' => 20,
                'content' => '<p class="text-gray-300 mb-3">Calibration defines the pressure-to-weight curve for an axle group.</p>
<p class="text-gray-300 mb-2">At minimum, calibration requires two known weights:</p>
<div class="space-y-3 ml-4">
  <div>
    <p class="text-white font-semibold">Empty axle weight</p>
    <ul class="list-disc list-inside text-gray-300 space-y-1 ml-4">
      <li>Weigh the vehicle empty on a certified scale</li>
      <li>Save the air pressure reading in the app</li>
    </ul>
  </div>
  <div>
    <p class="text-white font-semibold">Loaded axle weight</p>
    <ul class="list-disc list-inside text-gray-300 space-y-1 ml-4">
      <li>Weigh the vehicle with a known load</li>
      <li>Save the air pressure reading in the app</li>
    </ul>
  </div>
</div>
<p class="text-gray-300 mt-3">AirScales calculates the axle-specific relationship between pressure and weight using these points.</p>'
            ],
            [
                'title' => 'Accuracy and calibration points',
                'category' => 'calibration',
                'sortOrder' => 30,
                'content' => '<p class="text-gray-300 mb-3">Accuracy improves with additional calibration points, especially when they span the full operating range.</p>
<p class="text-gray-300 mb-2">Best results come from:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4 mb-3">
  <li>One empty calibration</li>
  <li>One or more loaded calibrations near typical operating weight</li>
</ul>
<p class="text-gray-300"><strong>More calibration points = tighter fit = more consistent results.</strong></p>'
            ],
            [
                'title' => 'When recalibration is required',
                'category' => 'calibration',
                'sortOrder' => 40,
                'content' => '<p class="text-gray-300 mb-2">Recalibrate only when the pressure-to-load relationship changes:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4 mb-3">
  <li>Suspension components are replaced or repaired</li>
  <li>Air system behavior changes (leaks, leveling valve replacement)</li>
  <li>Axle configuration is modified</li>
</ul>
<p class="text-gray-300">Routine seasonal changes, tire pressure changes, or weather do not require recalibration.</p>'
            ],

            // Usage
            [
                'title' => 'What features require a subscription?',
                'category' => 'usage',
                'sortOrder' => 10,
                'content' => '<p class="text-gray-300 mb-2">Without a subscription, the app has very limited functionality. An active subscription is required for:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4">
  <li>Real-time weight monitoring</li>
  <li>Viewing historical weight data</li>
  <li>Cloud storage and sync across devices</li>
  <li>Custom alerts (overweight warnings, etc.)</li>
  <li>Data export and reporting</li>
  <li>Multi-device management</li>
</ul>
<p class="text-gray-300 mt-3"><strong>Device purchasers get 6 months free!</strong> After that, plans start at just $5/month.</p>'
            ],
            [
                'title' => 'Can I use AirScales without internet?',
                'category' => 'usage',
                'sortOrder' => 20,
                'content' => '<p class="text-gray-300">Yes! AirScales works via Bluetooth even without cellular data or WiFi. However, some features require internet:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4 mt-2">
  <li><strong>Works offline:</strong> Real-time weight readings, local data storage</li>
  <li><strong>Requires internet:</strong> Cloud sync, historical data access from other devices, remote monitoring</li>
</ul>'
            ],
            [
                'title' => 'Can I monitor my truck remotely?',
                'category' => 'usage',
                'sortOrder' => 30,
                'content' => '<p class="text-gray-300 mb-2">Yes, if the AirScales device has power and an internet connection (via WiFi or your mobile phone connected via Bluetooth with cellular data), and the device can see data, you can monitor load updates remotely.</p>
<p class="text-gray-300">Load data updates every 30 seconds or so and is visible via the web app at beaker.ca/dashboard or through the mobile app. Great for fleet managers or checking weights while physically not near the vehicle.</p>'
            ],
            [
                'title' => 'Does AirScales track my location?',
                'category' => 'usage',
                'sortOrder' => 40,
                'content' => '<p class="text-gray-300 mb-2"><strong>No.</strong> The AirScales device does not track your location.</p>
<p class="text-gray-300">Your phone, however, can track your location and this data can be saved in the AirScales app. You can disable location services entirely in your phone settings - AirScales will work fine without it.</p>'
            ],

            // Troubleshooting
            [
                'title' => 'What do all the lights on the device mean?',
                'category' => 'troubleshooting',
                'sortOrder' => 10,
                'content' => '<p class="text-gray-300 mb-3">There are 7 indicator lights on the AirScales device in this order:</p>
<div class="bg-gray-900 rounded-lg p-4 space-y-2">
  <div class="text-gray-300"><span class="text-white font-semibold">12V power:</span> Device receives 12V from your vehicle</div>
  <div class="text-gray-300"><span class="text-white font-semibold">5V power:</span> Buck converter providing 5V feed for the device</div>
  <div class="text-gray-300"><span class="text-white font-semibold">USB:</span> 5V power from USB connection</div>
  <div class="text-gray-300"><span class="text-white font-semibold">MUX:</span> Power switch regulating either 12V or USB to 5V</div>
  <div class="text-gray-300"><span class="text-white font-semibold">3.8V:</span> On-board display power (only lit for first 5 minutes during power cycle, then goes off)</div>
  <div class="text-gray-300"><span class="text-white font-semibold">3.3V:</span> Main CPU power</div>
  <div class="text-gray-300"><span class="text-white font-semibold">SYS:</span> Status indicator (various colors for boot sequencing, mesh connection, Bluetooth, etc.)</div>
</div>
<p class="text-gray-300 mt-3"><strong>Normal operation:</strong> When connected to your truck or trailer, you should see 12V, 5V, MUX, 3.3V, and SYS lit.</p>'
            ],
            [
                'title' => 'What happens if my device loses power?',
                'category' => 'troubleshooting',
                'sortOrder' => 20,
                'content' => '<p class="text-gray-300">Your calibration and settings are stored in the device\'s memory and won\'t be lost. When power is restored, the device will automatically reconnect to your phone.</p>'
            ],
            [
                'title' => 'The app says "Updated 6 hours ago" instead of showing live data. Why?',
                'category' => 'troubleshooting',
                'sortOrder' => 30,
                'content' => '<p class="text-gray-300 mb-2">This usually means the device isn\'t actively connected. Common causes:</p>
<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4">
  <li>Device is powered off or lost 12V power</li>
  <li>Bluetooth is disabled on your phone</li>
  <li>Device is out of Bluetooth range (typically 30-50 feet)</li>
  <li>Another phone is connected to the device (only one connection at a time)</li>
</ul>
<p class="text-gray-300 mt-2">Check the device status indicator in the app - it should show "LIVE" with a green badge when actively connected.</p>'
            ],
            [
                'title' => 'Can I use one subscription for multiple trucks?',
                'category' => 'troubleshooting',
                'sortOrder' => 40,
                'content' => '<p class="text-gray-300">One subscription covers one user account. Your account can manage multiple devices (all your trucks and trailers), but you can only be logged in on one phone at a time. If you have multiple drivers or phones, each needs their own subscription.</p>'
            ],
            [
                'title' => 'What if I sell my truck or trailer?',
                'category' => 'troubleshooting',
                'sortOrder' => 50,
                'content' => '<p class="text-gray-300">You can remove the AirScales device and transfer it to your new truck/trailer. Just unbolt it and reinstall on the new vehicle. You\'ll need to recalibrate for the new vehicle, but all your subscription and account info stays the same.</p>'
            ],
            [
                'title' => 'Is AirScales weatherproof?',
                'category' => 'troubleshooting',
                'sortOrder' => 60,
                'content' => '<p class="text-gray-300">Yes, AirScales devices are designed for harsh trucking environments. However, mount the device in a location protected from direct water spray (under the frame, in a fairing, etc.) for best longevity.</p>'
            ],

            // Purchasing & Support
            [
                'title' => 'How much does AirScales cost?',
                'category' => 'general',
                'sortOrder' => 100,
                'content' => '<p class="text-gray-300">Contact us for current pricing on devices. Subscriptions are $5/month or $50/year (save $10/year). New device purchases include 6 months free subscription.</p>'
            ],
            [
                'title' => 'What\'s included with my device purchase?',
                'category' => 'general',
                'sortOrder' => 110,
                'content' => '<ul class="list-disc list-inside text-gray-300 space-y-1 ml-4">
  <li>AirScales main device unit</li>
  <li>12V power cable with fuse protection</li>
  <li>Pressure sensor(s) - 1 or 2 depending on configuration</li>
  <li>T-fittings for air line installation</li>
  <li>Mounting hardware</li>
  <li>Installation guide</li>
  <li>6 months free subscription (1 user)</li>
  <li>1-year limited warranty</li>
</ul>'
            ],
            [
                'title' => 'What\'s the return policy?',
                'category' => 'general',
                'sortOrder' => 120,
                'content' => '<p class="text-gray-300">Device returns must be requested within 30 days of purchase. Devices must be in original condition with all components and packaging. A restocking fee may apply.</p>'
            ],
            [
                'title' => 'How do I get support?',
                'category' => 'general',
                'sortOrder' => 130,
                'content' => '<p class="text-gray-300">For technical support or questions:</p>
<ul class="list-none text-gray-300 space-y-1 mt-2">
  <li><i class="fas fa-envelope text-blue-400 mr-2"></i>Email: support@beaker.ca</li>
  <li><i class="fas fa-globe text-blue-400 mr-2"></i>Website: beaker.ca</li>
</ul>'
            ],
        ];

        foreach ($faqs as $data) {
            $faq = new Faq();
            $faq->setTitle($data['title']);
            $faq->setCategory($data['category']);
            $faq->setContent($data['content']);
            $faq->setSortOrder($data['sortOrder']);
            $faq->setIsActive(true);

            $manager->persist($faq);
        }

        $manager->flush();
    }
}
